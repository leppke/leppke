# Stripe tranzakciós lekérdezések

Parancssori eszköz, amellyel a saját Stripe-fiókod tranzakcióit kérdezheted le
programból a hivatalos Stripe API-n keresztül.

> **Fontos tisztázás:** a Stripe API-t a **Stripe-fiók tulajdonosa** (a
> kereskedő) használhatja a saját fiókja adataira. Ha egy webshopban vásárló
> „ügyfélként" szeretnéd látni a saját vásárlásaidat, arra a Stripe nem ad
> publikus API-t — azt az adott kereskedőtől (számlák, nyugták) kapod meg.
> Ez az eszköz tehát azt feltételezi, hogy van saját Stripe-fiókod.

## Előkészületek

1. **API-kulcs beszerzése:** a [Stripe Dashboard → Developers → API keys](https://dashboard.stripe.com/apikeys)
   oldalon.
   - Fejlesztéshez/próbához használd a **teszt módú** kulcsot (`sk_test_...`) —
     ez a teszt-adatokon dolgozik, éles pénzt nem érint.
   - Éles lekérdezéshez érdemes **korlátozott kulcsot** (`rk_live_...`)
     létrehozni, amelynek csak *olvasási* joga van a szükséges erőforrásokra
     (Charges, PaymentIntents, Balance transactions, Payouts, Refunds). Így ha
     a kulcs kiszivárog, akkor sem lehet vele pénzt mozgatni.
2. **Soha ne** tedd a kulcsot verziókezelésbe, kliensoldali kódba (böngésző,
   mobilapp — ide értve a repó Cordova POS-appját is!), és ne oszd meg. A
   kulcsot környezeti változóban add át.
3. Node.js 18+ szükséges.

## Telepítés

```bash
cd stripe-queries
npm install
```

## Használat

```bash
export STRIPE_API_KEY=sk_test_...   # vagy rk_live_... korlátozott kulcs

# Minden pénzmozgás (terhelések, díjak, visszatérítések, kifizetések):
node list-transactions.js --type balance --from 2026-07-01 --to 2026-07-20

# Kártyaterhelések egy adott vevőre szűrve:
node list-transactions.js --type charges --customer cus_ABC123 --limit 50

# Fizetési szándékok (PaymentIntents) — a modern integráció alapobjektuma:
node list-transactions.js --type payment-intents --from 2026-07-01

# Bankszámlára történt kifizetések:
node list-transactions.js --type payouts

# Visszatérítések, nyers JSON-ban (további feldolgozáshoz, pl. jq-val):
node list-transactions.js --type refunds --json
```

### Opciók

| Opció | Jelentés |
|---|---|
| `--type` | `balance` (alapértelmezés), `charges`, `payment-intents`, `payouts`, `refunds` |
| `--from` / `--to` | dátumszűrés a létrehozás idejére, `ÉÉÉÉ-HH-NN` formátumban (UTC) |
| `--limit` | max. rekordszám; 100 felett automatikusan lapoz |
| `--customer` | `cus_...` azonosítóra szűrés (`charges` és `payment-intents` esetén) |
| `--json` | nyers JSON kimenet a táblázatos helyett |

## Hogyan működik?

- A [hivatalos `stripe` npm csomagot](https://github.com/stripe/stripe-node)
  használja; a lekérdezések a `GET /v1/balance_transactions`,
  `/v1/charges`, `/v1/payment_intents`, `/v1/payouts`, `/v1/refunds`
  REST-végpontokra mennek.
- Az összegeket a Stripe a legkisebb pénzegységben adja vissza (pl. EUR-nál
  centben); a script ezt olvasható formára alakítja. A HUF a Stripe-nál
  „zero-decimal" pénznem, ott az összeg már forintban értendő.
- A lapozást (`starting_after` kurzor) az SDK `autoPagingEach` metódusa
  intézi, így 100-nál több rekord is lekérhető egy futással.
- Bonyolultabb szűréshez (pl. metaadat alapján) ott a
  [Search API](https://docs.stripe.com/search):
  `stripe.charges.search({ query: "metadata['order_id']:'6735'" })`.

## Hasznos dokumentáció

- API-referencia: https://docs.stripe.com/api
- Balance transactions (minden pénzmozgás): https://docs.stripe.com/api/balance_transactions/list
- API-kulcsok és jogosultságok: https://docs.stripe.com/keys
- Riportok programozott exportja (nagy tömegű adathoz): https://docs.stripe.com/reports/report-api
