#!/usr/bin/env python3
"""Stripe tranzakciók lekérdezése és feltöltése Firestore-ba (Firebase riport).

A belső gépen fut, a wp_stripe_report.py mellé másolva (a Stripe-lekérdező
függvényeket onnan importálja). A Firestore-ba a Firebase service account
kulccsal ír — ez a kulcs is csak a belső gépen létezik.

Telepítés:
    pip install stripe firebase-admin

Konfiguráció környezeti változókból:
    STRIPE_ACCOUNTS   - Név=kulcs párok pontosvesszővel (mint eddig),
    STRIPE_API_KEY    - VAGY egyetlen fiók kulcsa,
    FIREBASE_KEY_FILE - a Firebase service account JSON fájl útvonala,
    REPORT_DAYS       - hány napra visszamenőleg (alapértelmezés: 31).

A tranzakciók dokumentum-azonosítója a Stripe tranzakció-ID, ezért az újra
feltöltés a meglévő sorokat frissíti, a korábbi (időablakon kívüli) sorok
pedig megmaradnak — a Firestore-ban így idővel teljes történet gyűlik.
"""

import os
import sys
from datetime import datetime, timezone

import firebase_admin
from firebase_admin import credentials, firestore
import stripe

from wp_stripe_report import (
    fetch_rows,
    fetch_webhook_status,
    parse_accounts,
    require_env,
)

BATCH_LIMIT = 400  # a Firestore batch felső korlátja 500 művelet


def main() -> None:
    stripe.max_network_retries = 2
    key_file = require_env("FIREBASE_KEY_FILE")
    days = int(os.environ.get("REPORT_DAYS", "31"))
    accounts = parse_accounts()

    firebase_admin.initialize_app(credentials.Certificate(key_file))
    db = firestore.client()

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

    batch = db.batch()
    pending = 0

    def flush(force: bool = False) -> None:
        nonlocal batch, pending
        if pending and (force or pending >= BATCH_LIMIT):
            batch.commit()
            batch = db.batch()
            pending = 0

    for row in rows:
        batch.set(db.collection("transactions").document(row["id"]), row)
        pending += 1
        flush()

    for wh in webhooks:
        doc_id = (wh["account"] or "stripe").replace("/", "_")
        batch.set(db.collection("webhooks").document(doc_id), wh)
        pending += 1
        flush()

    batch.set(db.collection("riport").document("meta"), {
        "updated": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC"),
        "days": days,
    })
    pending += 1
    flush(force=True)

    print(f"Feltöltve a Firestore-ba: {len(rows)} tranzakció, "
          f"{len(webhooks)} fiók webhook-állapota.")


if __name__ == "__main__":
    main()
