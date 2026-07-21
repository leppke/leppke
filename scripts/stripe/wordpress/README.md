# Stripe riport a könyvelőnek — WordPress + belső Python gép

## Architektúra

A Stripe API-kulcs **soha nem hagyja el a belső hálózaton lévő Windows gépet**,
és a WordPress szerver **nem éri el** a belső hálózatot — ezért az adat
áramlása fordított ("push" modell):

```
[Belső Windows gép]                         [Publikus WordPress szerver]
 Python + Stripe kulcs                        Stripe Riport bővítmény
   1. lekérdezi a Stripe API-t     ──HTTPS──▶  3. REST végpont fogadja
   2. riportsorokká alakítja                   4. elmenti (options tábla)
      (időzítve, pl. naponta)                  5. [stripe_riport] oldal
                                                  megjeleníti a könyvelőnek
```

A WordPressre csak a kész, megjelenítésre szánt sorok kerülnek (dátum, összeg,
díj, nettó, státusz) — kulcs és nyers Stripe-adat nem.

## 1. lépés — WordPress bővítmény telepítése

1. Másold fel a `stripe-riport.php` fájlt a szerverre
   (`wp-content/plugins/stripe-riport/stripe-riport.php`), vagy töltsd fel a
   `stripe-riport.zip`-et az admin felületen (Bővítmények → Új hozzáadása →
   Bővítmény feltöltése).
2. Admin felületen: **Bővítmények → Stripe Riport → Aktiválás**.
   Aktiváláskor létrejön a **Könyvelő** szerepkör és a feltöltési token.

## 2. lépés — Könyvelő fiók és feltöltési token

1. **Könyvelő fiók:** Felhasználók → Új hozzáadása → szerepkör: *Könyvelő*.
   Ezzel be tud lépni és látja a riportoldalt, mást nem tud szerkeszteni.
2. **Feltöltési token:** **Beállítások → Stripe Riport** oldalon található —
   ezt kell majd a Python konfigurációba (`WP_RIPORT_TOKEN`) beírni.
   A feltöltő script ezt a tokent egy saját `X-Riport-Token` fejlécben küldi,
   szándékosan nem az `Authorization` fejlécben — így a JWT-s vagy egyéb
   hitelesítő bővítmények (pl. Simple JWT Login) nem nyúlnak bele a kérésbe.
   Ha a token kiszivárogna, ugyanezen az oldalon egy kattintással új
   generálható (a régi azonnal érvénytelen lesz).

## 3. lépés — Riportoldal létrehozása

Hozz létre egy új oldalt (pl. "Stripe riport"), tartalma egyetlen shortcode:

```
[stripe_riport]
```

Az oldal akár publikus is lehet: a shortcode csak bejelentkezett, *Könyvelő*
(vagy admin) felhasználónak mutatja a táblázatot, mindenki másnak egy
elutasító üzenetet. A könyvelőnek elég ennek az oldalnak a linkjét elküldeni.

## 4. lépés — Python a belső Windows gépen

1. Telepítsd a csomagokat:
   ```
   pip install stripe requests
   ```
2. Másold a gépre a `wp_stripe_report.py` fájlt és a
   `riport_futtatas.bat.example` mintát. A mintát nevezd át
   `riport_futtatas.bat`-ra, és töltsd ki:
   - `STRIPE_API_KEY`: a Stripe Dashboardon létrehozott **korlátozott, csak
     olvasó** kulcs (`rk_...`) — elég a *Balance transactions: Read* jog,
   - `WP_URL`: a WordPress oldal gyökere (alkönyvtáras telepítésnél azzal
     együtt, pl. `https://pelda.hu/app`),
   - `WP_RIPORT_TOKEN`: a 2. lépésben kimásolt feltöltési token.
3. **Fontos:** a `.bat` fájl titkokat tartalmaz — az NTFS jogosultságait
   szűkítsd úgy, hogy csak a futtató Windows-fiók olvashassa
   (Tulajdonságok → Biztonság).
4. Próbafuttatás parancssorból:
   ```
   riport_futtatas.bat
   ```
   Sikeres esetben kiírja: `Feltöltve: N sor.` — és a riportoldalon azonnal
   megjelenik a táblázat.

## 5. lépés — Időzítés (Feladatütemező)

Rendszergazdai parancssorból, pl. minden reggel 6-kor:

```
schtasks /Create /TN "Stripe riport" /SC DAILY /ST 06:00 ^
  /TR "C:\stripe-riport\riport_futtatas.bat"
```

(Vagy a grafikus Feladatütemezőben: új feladat → napi trigger → a `.bat`
indítása. Állítsd be, hogy akkor is fusson, ha a felhasználó nincs belépve.)

## Biztonsági összefoglaló

- A Stripe kulcs csak a belső gépen létezik, és korlátozott, csak olvasó
  (`rk_...`) kulcs — ha mégis kiszivárogna, fizetést indítani nem lehet vele.
- A WordPress csak származtatott riportadatot tárol, kulcsot soha.
- A feltöltési token egyetlen dolgot tud: a riport REST végpontját hívni —
  WordPress-belépésre nem használható, és a Beállítások → Stripe Riport
  oldalon bármikor cserélhető.
- A riportot csak *Könyvelő* szerepkörű (vagy admin) bejelentkezett
  felhasználó látja.
- Minden forgalom HTTPS-en menjen — a token védelme ezen múlik, a WordPress
  oldalnak érvényes tanúsítvány kell.
