# Stripe riport Firebase-en (Hosting + Auth + Cloud Functions)

A riport egy Firebase Hostingon futó weboldal. A könyvelő e-mail + jelszóval
belép (Firebase Auth), és a **Lekérdezés** gombbal indítja a lekérdezést: egy
Cloud Function élőben kérdezi le a Stripe-ot (a kulcsok a Google Secret
Managerben vannak), az oldal pedig fiókonkénti táblázatokban mutatja az
eredményt, CSV-letöltéssel és webhook-állapottal. Nincs ütemezés, nincs
adattárolás, nincs belső gép — az adat forrása mindig maga a Stripe.

```
[Könyvelő] → belépés (Auth) → „Lekérdezés"
    → [Cloud Function: Stripe API, kulcsok a Secret Managerben]
    → táblázatok + CSV a böngészőben
```

## Beüzemelés lépésről lépésre

### 1. Firebase projekt

1. https://console.firebase.google.com → **Add project** (pl. `marinaport-riport`),
   a Google Analytics kikapcsolható.
2. A bal alsó sarokban válts **Blaze** (pay-as-you-go) csomagra — a Cloud
   Functionnek a Stripe-hoz kimenő hálózathoz és a titok-tárhoz kell.
   Ennél a terhelésnél (napi néhány lekérdezés) a költség az ingyenes kereten
   belül marad.

### 2. Web app és konfiguráció

1. A projekt főoldalán a **`</>` (Web)** ikonnal adj hozzá egy web appot
   (Hosting pipálása nem kötelező, a CLI-ből deployolunk).
2. A kapott `firebaseConfig` objektumot (apiKey, authDomain, projectId, appId)
   másold be a `public/index.html` fájlba a megjelölt helyre.

### 3. Belépés (Auth)

1. **Authentication → Sign-in method → Email/Password → Enable**.
2. **Authentication → Users → Add user**: hozd létre a saját és a könyvelő
   fiókját (e-mail + jelszó).
3. A `functions/index.js` tetején írd át az `ALLOWED_EMAILS` listát ugyanezekre
   a címekre — hiába tudna bárki regisztrálni, adatot csak az itt felsoroltak
   kapnak.

### 4. Stripe-kulcsok titokként

A deployhoz a Firebase CLI kell (a fejlesztő gépen, egyszer):

```sh
npm install -g firebase-tools
firebase login
```

Majd ebben a mappában (`scripts/stripe/firebase`):

```sh
firebase functions:secrets:set STRIPE_ACCOUNTS --project A_PROJEKT_ID
```

A bekérdezésnél illeszd be a fiókokat ugyanabban a formátumban, mint a
Windows-os `.bat`-ban volt:

```
Hotel=rk_live_xxx;Marina=rk_live_yyy
```

A kulcsokon szükséges jogok (fiókonként, a Stripe Dashboardon): *Balance
transactions: Read*, *Charges: Read*, *Events: Read*, *Webhook Endpoints: Read*.

### 5. Deploy

Ebben a mappában:

```sh
npm --prefix functions install
firebase deploy --project A_PROJEKT_ID
```

A végén kiírja a Hosting URL-t: `https://A_PROJEKT_ID.web.app` — ezt kapja meg
a könyvelő (könyvjelzőnek). Belépés után a **Lekérdezés** gomb 7/31/90/180
napos időszakra kérdez le.

## Módosítások később

- **Új engedélyezett felhasználó:** Auth-ban felvenni + `ALLOWED_EMAILS`-be
  beírni + `firebase deploy --only functions --project A_PROJEKT_ID`.
- **Kulcs csere / új Stripe-fiók:** `firebase functions:secrets:set
  STRIPE_ACCOUNTS` újra, majd deploy `--only functions`.
- **Oldal módosítása:** `public/index.html` szerkesztése, majd deploy
  `--only hosting`.

## Biztonság

- A Stripe-kulcsok a Google Secret Managerben vannak; a böngészőbe soha nem
  jutnak el — a kliens csak a kész riportsorokat kapja.
- A Cloud Function kétszeresen véd: bejelentkezett felhasználó kell (Auth) ÉS
  a címének szerepelnie kell az `ALLOWED_EMAILS` listában.
- A kulcsok korlátozott, csak olvasó Stripe-kulcsok — fizetést indítani akkor
  sem lehetne velük, ha kiszivárognának.
- A korábbi WordPress-es megoldás (`../wordpress/`) ettől függetlenül működik;
  ha a Firebase-verzió bevált, a WP-bővítmény deaktiválható és a Windows-os
  időzítés törölhető.
