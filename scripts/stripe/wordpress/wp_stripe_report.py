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
    WP_USER          - a "riport robot" WordPress felhasználó neve
    WP_APP_PASSWORD  - a robot felhasználó Application Password-je
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


def fetch_rows(days: int) -> list[dict]:
    created_after = int((datetime.now(timezone.utc) - timedelta(days=days)).timestamp())
    rows = []
    # A balance_transactions minden pénzmozgást tartalmaz (befizetés, díj,
    # visszatérítés, kifizetés), díj- és nettó bontással — könyveléshez ez kell.
    for tx in stripe.BalanceTransaction.list(
        created={"gte": created_after}, limit=100
    ).auto_paging_iter():
        rows.append({
            "date": datetime.fromtimestamp(tx.created, tz=timezone.utc).strftime("%Y-%m-%d %H:%M"),
            "id": tx.id,
            "amount": format_amount(tx.amount, tx.currency),
            "fee": format_amount(tx.fee, tx.currency),
            "net": format_amount(tx.net, tx.currency),
            "status": f"{tx.type} / {tx.status}",
            "description": tx.description or "",
        })
    return rows


def upload(wp_url: str, user: str, app_password: str, rows: list[dict]) -> None:
    resp = requests.post(
        f"{wp_url.rstrip('/')}/wp-json/stripe-riport/v1/upload",
        json={"rows": rows},
        auth=(user, app_password),
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
    wp_user = require_env("WP_USER")
    wp_app_password = require_env("WP_APP_PASSWORD")
    days = int(os.environ.get("REPORT_DAYS", "31"))

    rows = fetch_rows(days)
    print(f"{len(rows)} tranzakció az elmúlt {days} napból.")
    upload(wp_url, wp_user, wp_app_password, rows)


if __name__ == "__main__":
    main()
