# Stripe tranzakciók programozott lekérdezése

Ez a mappa egy Node.js scriptet tartalmaz (`stripe-transactions.mjs`), ami a
hivatalos [`stripe` npm SDK-val](https://www.npmjs.com/package/stripe) kérdezi
le a saját Stripe-fiókod tranzakcióit.

## Előfeltétel: kihez tartoznak az adatok?

A Stripe API-t a **Stripe-fiók tulajdonosa** (a kereskedő) tudja lekérdezni,
saját API-kulccsal. Ha egy webshop *vásárlójaként* szeretnéd a saját
vásárlásaidat látni, arra nincs publikus Stripe-végpont — azok az adatok a
kereskedő fiókjához tartoznak.

## API-kulcs beszerzése

1. Lépj be a [Stripe Dashboardra](https://dashboard.stripe.com/apikeys).
2. Lehetőleg **korlátozott kulcsot** (restricted key, `rk_...`) hozz létre,
   csak olvasási joggal a szükséges erőforrásokra (Charges, PaymentIntents,
   Balance transactions). A teljes jogú titkos kulcs (`sk_...`) is működik,
   de lekérdezéshez fölöslegesen széles jogosultság.
3. Teszteléshez a *test mode* kulcsokat használd (`rk_test_...` / `sk_test_...`).

**Biztonság:** a kulcs szerveroldali titok. Soha ne kerüljön be a kódba, git
repóba, és főleg nem a Cordova/webes kliensalkalmazásba — a kliensbe csomagolt
kulcsot bárki kiolvashatja. A lekérdezéseket mindig szerverről (vagy fejlesztői
gépről, env változóból) futtasd.

## Használat

```sh
# Függőségek telepítése (egyszer, ebben a mappában):
npm install

# Az elmúlt 7 nap pénzmozgásai (balance transactions — ez a "bankszámlakivonat"):
STRIPE_API_KEY=rk_test_... node stripe-transactions.mjs balance

# Az elmúlt 30 nap terhelései:
STRIPE_API_KEY=rk_test_... node stripe-transactions.mjs charges --days 30

# PaymentIntentek (a modern fizetési folyamat objektumai):
STRIPE_API_KEY=rk_test_... node stripe-transactions.mjs payment_intents --days 14
```

## Melyik végpont mire való?

| Végpont | Mit ad vissza |
|---|---|
| `/v1/balance_transactions` | Minden pénzmozgás a Stripe-egyenlegen: befizetések, díjak, visszatérítések, kifizetések. Könyveléshez ez a legteljesebb. |
| `/v1/charges` | Kártyaterhelések (sikeres és sikertelen). |
| `/v1/payment_intents` | Fizetési szándékok a teljes életciklusukkal (a Stripe ajánlott fizetési modellje). |
| `/v1/refunds`, `/v1/payouts`, `/v1/disputes` | Visszatérítések, kifizetések, reklamációk — a script mintájára könnyen hozzáadhatók. |

A Stripe cursor-alapú lapozást használ; az SDK `list()` hívása auto-lapozó
aszinkron iterátort ad vissza, így a script `for await` ciklussal az összes
oldalt magától végigjárja. Dátumra a `created: { gte: ... }` paraméter szűr.

Hivatalos dokumentáció: <https://docs.stripe.com/api> és
<https://github.com/stripe/stripe-node>.
