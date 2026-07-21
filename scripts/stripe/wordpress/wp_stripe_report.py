#!/usr/bin/env python3
"""Stripe tranzakciók lekérdezése és feltöltése a WordPress oldalra.

A belső hálózaton lévő Windows gépen fut (időzítve, pl. Feladatütemezővel).
A Stripe kulcs csak ezen a gépen létezik; a WordPress felé már csak a kész,
megjelenítésre szánt riportsorok mennek ki.

Telepítés:
    pip install stripe requests

Konfiguráció környezeti változókból:
    STRIPE_ACCOUNTS  - több Stripe fiók: pontosvesszővel elválasztott
                       Név=kulcs párok, pl. Marina=rk_live_xxx;Kikoto=rk_live_yyy
    STRIPE_API_KEY   - VAGY egyetlen fiók kulcsa (rk_..., csak olvasás)
    WP_URL           - a WordPress oldal gyökere, pl. https://pelda.hu
    WP_RIPORT_TOKEN  - feltöltési token (WordPress: Beállítások -> Stripe Riport)
    REPORT_DAYS      - hány napra visszamenőleg (alapértelmezés: 31)
"""

import os
import sys
from datetime import datetime, timezone, timedelta

import requests
import stripe

ZERO_DECIMAL = {"huf", "jpy", "krw", "vnd", "clp", "isk", "twd", "ugx"}


def require_env(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        print(f"Hiba: hiányzó környezeti változó: {name}", file=sys.stderr)
        sys.exit(1)
    return value


def format_amount(amount: int, currency: str) -> str:
    # A Stripe a legkisebb pénzegységben adja az összegeket; a HUF nulla tizedesjegyű.
    value = amount if currency in ZERO_DECIMAL else amount / 100
    return f"{value:,.0f} {currency.upper()}".replace(",", " ")


def payer_name(tx) -> str:
    # A tranzakció forrása fizetésnél egy Charge objektum (expand-dal kérjük le),
    # abban a kártyán megadott név a billing_details.name. Díjaknál,
    # kifizetéseknél nincs ilyen — ott üres marad.
    source = getattr(tx, "source", None)
    if source is None or isinstance(source, str):
        return ""
    billing = getattr(source, "billing_details", None)
    name = getattr(billing, "name", None) if billing is not None else None
    return name or ""


def parse_accounts() -> list[tuple[str, str]]:
    """(fióknév, kulcs) párok a STRIPE_ACCOUNTS vagy a STRIPE_API_KEY változóból."""
    accounts_env = os.environ.get("STRIPE_ACCOUNTS", "").strip()
    if accounts_env:
        accounts = []
        for part in accounts_env.split(";"):
            part = part.strip()
            if not part:
                continue
            if "=" not in part:
                print(f"Hiba: hibás STRIPE_ACCOUNTS elem (Név=kulcs kell): {part!r}", file=sys.stderr)
                sys.exit(1)
            name, key = part.split("=", 1)
            accounts.append((name.strip(), key.strip()))
        if not accounts:
            print("Hiba: a STRIPE_ACCOUNTS nem tartalmaz Név=kulcs párt.", file=sys.stderr)
            sys.exit(1)
        return accounts
    return [("", require_env("STRIPE_API_KEY"))]


def fetch_rows(account_name: str, api_key: str, days: int) -> list[dict]:
    created_after = int((datetime.now(timezone.utc) - timedelta(days=days)).timestamp())
    rows = []
    # A balance_transactions minden pénzmozgást tartalmaz (befizetés, díj,
    # visszatérítés, kifizetés), díj- és nettó bontással — könyveléshez ez kell.
    # Az expand=["data.source"] miatt a kulcsnak Charges: Read jog is kell.
    for tx in stripe.BalanceTransaction.list(
        api_key=api_key, created={"gte": created_after}, limit=100, expand=["data.source"]
    ).auto_paging_iter():
        rows.append({
            "account": account_name,
            "date": datetime.fromtimestamp(tx.created, tz=timezone.utc).strftime("%Y-%m-%d %H:%M"),
            "customer": payer_name(tx),
            "id": tx.id,
            "amount": format_amount(tx.amount, tx.currency),
            "fee": format_amount(tx.fee, tx.currency),
            "net": format_amount(tx.net, tx.currency),
            "status": f"{tx.type} / {tx.status}",
            "description": tx.description or "",
        })
    return rows


def fetch_webhook_status(account_name: str, api_key: str, days: int) -> dict:
    """Webhook-végpontok állapota + még kézbesítetlen események.

    A Stripe a kézbesítési naplót nem adja ki API-n; amit tudunk: a végpont
    státuszát (a tartósan hibázót a Stripe letiltja), és eseményenként a
    pending_webhooks számot (>0 = van végpont, ami még nem igazolta vissza,
    a Stripe újrapróbálja). Ehhez a kulcsnak Events: Read és Webhook
    Endpoints: Read jog kell.
    """
    result = {"account": account_name, "endpoints": [], "events_total": 0, "pending": []}
    try:
        for ep in stripe.WebhookEndpoint.list(api_key=api_key, limit=100).auto_paging_iter():
            result["endpoints"].append({"url": ep.url, "status": ep.status})

        created_after = int((datetime.now(timezone.utc) - timedelta(days=days)).timestamp())
        for ev in stripe.Event.list(
            api_key=api_key, created={"gte": created_after}, limit=100
        ).auto_paging_iter():
            result["events_total"] += 1
            if ev.pending_webhooks > 0 and len(result["pending"]) < 200:
                result["pending"].append({
                    "date": datetime.fromtimestamp(ev.created, tz=timezone.utc).strftime("%Y-%m-%d %H:%M"),
                    "type": ev.type,
                    "id": ev.id,
                })
    except Exception as err:  # a webhook-rész hibája ne vigye el a tranzakciós riportot
        print(f"Figyelmeztetés ({account_name or 'Stripe'}): webhook-állapot nem elérhető: {err}",
              file=sys.stderr)
        result["error"] = str(err)[:300]
    return result


def upload(wp_url: str, token: str, rows: list[dict], webhooks: list[dict]) -> None:
    # Szándékosan saját fejléc, nem Authorization: így a JWT/egyéb hitelesítő
    # bővítmények nem nyúlnak bele a kérésbe.
    resp = requests.post(
        f"{wp_url.rstrip('/')}/wp-json/stripe-riport/v1/upload",
        json={"rows": rows, "webhooks": webhooks},
        headers={"X-Riport-Token": token},
        timeout=60,
    )
    if not resp.ok:
        print(f"Feltöltési hiba ({resp.status_code}): {resp.text}", file=sys.stderr)
        sys.exit(1)
    print(f"Feltöltve: {resp.json().get('count')} sor.")


def main() -> None:
    stripe.max_network_retries = 2
    wp_url = require_env("WP_URL")
    wp_token = require_env("WP_RIPORT_TOKEN")
    days = int(os.environ.get("REPORT_DAYS", "31"))
    accounts = parse_accounts()

    rows = []
    webhooks = []
    for name, api_key in accounts:
        account_rows = fetch_rows(name, api_key, days)
        print(f"{name or 'Stripe'}: {len(account_rows)} tranzakció az elmúlt {days} napból.")
        rows.extend(account_rows)

        status = fetch_webhook_status(name, api_key, days)
        if "error" not in status:
            print(f"{name or 'Stripe'}: {len(status['endpoints'])} webhook-végpont, "
                  f"{status['events_total']} esemény, {len(status['pending'])} kézbesítetlen.")
        webhooks.append(status)

    rows.sort(key=lambda r: r["date"], reverse=True)
    upload(wp_url, wp_token, rows, webhooks)


if __name__ == "__main__":
    main()
