# Stripe tranzakció-lekérdezés programból

Ez a mappa egy önálló parancssori eszközt tartalmaz, amellyel a saját Stripe
fiókod tranzakcióit kérdezheted le a [Stripe API](https://stripe.com/docs/api)-n
keresztül. Nincs szükség semmilyen npm csomagra — csak **Node.js 18 vagy újabb**
kell hozzá.

## Előfeltételek

1. **Stripe fiók** — a lekérdezés a saját (kereskedői) Stripe fiókod adataira
   vonatkozik. Ha csak vásárlóként fizettél egy boltban Stripe-on keresztül, a
   tranzakcióidat az adott kereskedő látja, API-val te nem tudod lekérdezni.
2. **API kulcs** — a [Stripe Dashboard → Developers → API keys](https://dashboard.stripe.com/apikeys)
   oldalon találod.
   - Teszteléshez a `sk_test_...` kulcsot használd, éles adatokhoz az `sk_live_...` kulcsot.
   - **Ajánlott:** hozz létre *restricted key*-t (`rk_...`), amelynek csak
     olvasási joga van a szükséges erőforrásokhoz — így a kulcs kiszivárgása
     esetén sem lehet vele pénzt mozgatni.
   - A kulcsot **soha ne** commitold a repóba; környezeti változóban add át.

## Használat

```bash
export STRIPE_SECRET_KEY=sk_test_...

# Terhelések (kártyás fizetések) listája
node stripe/stripe-transactions.js charges

# Elszámolt pénzmozgások adott időszakra (díjakkal és nettó összeggel)
node stripe/stripe-transactions.js balance-transactions --from 2026-07-01 --to 2026-07-20

# Az összes fizetési szándék CSV-be mentve
node stripe/stripe-transactions.js payment-intents --all --csv fizetesek.csv

# Nyers JSON, ha tovább akarod dolgozni
node stripe/stripe-transactions.js payouts --json
```

### Erőforrások

| Erőforrás | Mit ad vissza |
|---|---|
| `balance-transactions` | Minden elszámolt pénzmozgás: bruttó összeg, Stripe-díj, nettó. Könyveléshez ez a legjobb. |
| `charges` | Terhelések (kártyás fizetések), állapottal és visszatérítési jelzéssel. |
| `payment-intents` | Fizetési szándékok — a modern Stripe fizetési folyamat alapobjektuma. |
| `payouts` | A Stripe-egyenlegből a bankszámládra indított kifizetések. |
| `refunds` | Visszatérítések. |

### Opciók

| Opció | Jelentés |
|---|---|
| `--from YYYY-MM-DD` | Csak az ettől a naptól létrejött rekordok |
| `--to YYYY-MM-DD` | Csak az eddig a napig (a nap végéig) létrejött rekordok |
| `--limit N` | Legfeljebb N rekord (alapértelmezés: 100) |
| `--all` | Minden találat lekérése automatikus lapozással |
| `--json` | Nyers JSON kimenet táblázat helyett |
| `--csv FÁJL` | Eredmény mentése CSV fájlba (Excelben megnyitható) |

## Jó tudni

- **Összegek:** a Stripe a legkisebb pénzegységben adja vissza az összegeket
  (pl. cent). A táblázatos és CSV kimenet ezt 100-zal osztva jeleníti meg
  (a HUF a Stripe-nál kétdecimális pénznemként működik).
- **Lapozás:** a Stripe oldalanként legfeljebb 100 rekordot ad; a `--all`
  kapcsoló a `starting_after` kurzorral automatikusan végiglapozza az összeset.
- **Időzóna:** a `--from`/`--to` szűrés UTC szerint értendő.
- **Hivatalos SDK:** ha alkalmazásba építenéd be, érdemes a hivatalos
  [`stripe` npm csomagot](https://github.com/stripe/stripe-node) használni
  (`npm install stripe`) — ez a szkript szándékosan függőségmentes, hogy
  bárhol azonnal futtatható legyen.
