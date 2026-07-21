#!/usr/bin/env python3
"""Stripe tranzakciók lekérdezése és feltöltése a WordPress oldalra.

A belső hálózaton lévő Windows gépen fut (időzítve, pl. Feladatütemezővel).
A Stripe kulcs csak ezen a gépen létezik; a WordPress felé már csak a kész,
megjelenítésre szánt riportsorok mennek ki.

Telepítés:
    pip install stripe requests

Konfiguráció környezeti változókból:
    STRIPE_API_KEY   - Stripe korlátozott kulcs (rk_..., csak olvasás)
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


def fetch_rows(days: int) -> list[dict]:
    created_after = int((datetime.now(timezone.utc) - timedelta(days=days)).timestamp())
    rows = []
    # A balance_transactions minden pénzmozgást tartalmaz (befizetés, díj,
    # visszatérítés, kifizetés), díj- és nettó bontással — könyveléshez ez kell.
    # Az expand=["data.source"] miatt a kulcsnak Charges: Read jog is kell.
    for tx in stripe.BalanceTransaction.list(
        created={"gte": created_after}, limit=100, expand=["data.source"]
    ).auto_paging_iter():
        rows.append({
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


def upload(wp_url: str, token: str, rows: list[dict]) -> None:
    # Szándékosan saját fejléc, nem Authorization: így a JWT/egyéb hitelesítő
    # bővítmények nem nyúlnak bele a kérésbe.
    resp = requests.post(
        f"{wp_url.rstrip('/')}/wp-json/stripe-riport/v1/upload",
        json={"rows": rows},
        headers={"X-Riport-Token": token},
        timeout=60,
    )
    if not resp.ok:
        print(f"Feltöltési hiba ({resp.status_code}): {resp.text}", file=sys.stderr)
        sys.exit(1)
    print(f"Feltöltve: {resp.json().get('count')} sor.")


def main() -> None:
    stripe.api_key = require_env("STRIPE_API_KEY")
    stripe.max_network_retries = 2
    wp_url = require_env("WP_URL")
    wp_token = require_env("WP_RIPORT_TOKEN")
    days = int(os.environ.get("REPORT_DAYS", "31"))

    rows = fetch_rows(days)
    print(f"{len(rows)} tranzakció az elmúlt {days} napból.")
    upload(wp_url, wp_token, rows)


if __name__ == "__main__":
    main()
