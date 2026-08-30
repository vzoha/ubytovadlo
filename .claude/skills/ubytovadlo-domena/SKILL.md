---
name: ubytovadlo-domena
description: Doménová pravidla Ubytovadla — prodejní kanály (web/Booking/Airbnb/iCal), tok OTA rezervace, pět fakturačních toků a jejich mapování na MotoPress gateway, tři daňové profily provozovatele (identifikovaná osoba / plátce / neplátce), reverse charge z provizí, sazby a daň shora, evidované údaje, glosář. Použij při práci na rezervacích, fakturách, DPH, importech z kanálů nebo mailových parserech.
---

# Doména

## Prodejní kanály

- **Vlastní web** — WordPress + **MotoPress Hotel Booking**. Kompletní zdroj pravdy včetně hosta a částky.
- **Booking.com** — obsazenost přes **iCal**. E-mail obsahuje jen `res_id` + datum příjezdu v předmětu a odkaz do extranetu — **žádné údaje hosta ani cenu**. Je to úmyslný design Booking (ne GDPR), v extranetu se to nedá přepnout; obejít jde jen placeným channel managerem, což je pro jeden objekt overkill. Detaily se čtou z extranetu / Pulse appky.
- **Airbnb** — obsazenost přes **iCal**, ale e-maily jsou **bohaté**: jméno hosta, region, příjezd/odjezd s časy, počet hostů, potvrzující kód, cena × nocí, celkem CZK, **provize 3 %** (podklad pro reverse charge), čistá výplata. Chybí plná adresa (Airbnb ji nesbírá) a reálný kontakt (proxy `@reply.airbnb.com`).
- **eChalupy, CS chalupy** — jen obsazenost přes iCal.

Do vyhrazené automatizační schránky (IMAP, SSL 993 — adresa a host v `CLAUDE.local.md` a `app/.env.local`) chodí Booking *Reservations* + *Invoices* notifikace a přeposílané Airbnb e-maily.

**Důsledek:** u OTA nemáme automaticky hosta, kontakt, adresu ani dohodnutou cenu. Booking e-mail je jen **trigger** („rezervace existuje, dotáhni si k ní hosta"); u Airbnb hraje stejnou roli **iCal poller**, který zachytí nový blok.

## Tok OTA rezervace — zúžení ruční práce

Ruční zadání úplně nezmizí. Cíl je dostat ho na **jedno místo a co nejméně polí**.

1. Trigger: nový Booking e-mail nebo nový iCal blok, který nemáme v DB.
2. Vznikne `reservation` ve stavu `needs_details` s tím, co víme (kanál, termín, případně `res_id`).
3. Majitelce odejde **jediný e-mail** s odkazem do našeho UI a paralelně do extranetu.
4. Ona opíše jméno / adresu / kontakt / cenu / počet osob → uloží.
5. Automaticky následuje: faktura (PDF + zápis), naplánování pre-arrival a post-stay zprávy, zápis do dashboardu.

Tok z vlastního webu je bez `needs_details` — MotoPress REST API dodá plné údaje a rovnou se vystaví faktura a naplánují zprávy.

## Pět fakturačních toků

Jak faktura hostovi vypadá, řídí **daňový profil provozovatele** (`App\Enum\TaxProfile`, nastavení dodavatele). Default je identifikovaná osoba; profil konkrétní instance je v `CLAUDE.local.md`.

| Profil | Faktura hostovi | DPH modul |
|---|---|---|
| `identified_person` | bez DPH, s poznámkou o identifikované osobě a vlastním DIČ | reverse charge z provizí |
| `vat_payer` | s DPH — sazby na řádcích, rekapitulace | reverse charge + výstupní DPH; RC je odpočet, ne náklad |
| `non_payer` | bez DPH, bez poznámky o režimu | skrytý |

Nikdy nepiš chování jednoho profilu natvrdo — čti profil přes `TaxProfileConfig`.

1. **Web — klasika** (soukromý host): záloha → **zálohová faktura**, zbytek při příjezdu (QR nebo hotově). Výši a splatnost zálohy drží `App\Invoice\DepositConfig` (fixní částka / procento z ceny / žádná záloha); jestli se záloha u dané rezervace vůbec bere, rozhoduje `appliesTo()`. **Konečná faktura** s odpočtem zálohy se posílá spolu se zálohovou během pobytu.
2. **Web — FKSP** (zaměstnanecký fond): bez zálohy, jedna faktura na celou částku, ale **až po obdržení fakturačních údajů firmy** (stav `needs_billing_details`).
3. **Web — admin/známí**: rezervaci založil provozovatel z WP adminu. Bez zálohy, jedna faktura během pobytu.
4. **Airbnb**: údaje hosta se sbírají osobně na startu pobytu. Faktura v CZK na celou částku, e-mailem pokud host chce.
5. **Booking**: adresa z extranetu, cena v EUR → faktura v CZK **kurzem ČNB ke dni vystavení** (`api.cnb.cz/cnbapi/exrates/daily`). Vystavuje se během pobytu.

Faktura musí unést: vlastní číselnou řadu, variabilní symbol, QR Platbu (SPAYD), původní měnu + kurz + datum kurzu, odkaz na zálohu, poznámku o identifikované osobě, DIČ.

### Dispatcher při syncu z MotoPressu

1. `booking.imported == true` → **iCal blok z OTA** (`total_price = 0`, `payments = []`, bez gateway). Kanál se určí z `ical_prodid` (`airbnb.com` vs `admin.booking.com`) a jede OTA tok.
2. `booking.imported == false` → **web rezervace**, tok podle `gateway_id` první platby:

| `gateway_id` | Tok | Faktura |
|---|---|---|
| `bank` | Web klasika | Záloha dle `DepositConfig` + doplatek s odpočtem |
| `cash` | Web FKSP | Jedna faktura na celou částku po doplnění firmy |
| `manual` | Web admin/známí | Jedna faktura na celou částku během pobytu |
| (žádná platba) | Nezaplaceno / čekající | Žádná akce |

MotoPress FKSP gateway nemá — `cash` je naše konvence. Manuální override v UI zůstává pro případ, že konvence selže.

## DPH — identifikovaná osoba

Z **provizí Booking/Airbnb** (přijatá služba z EU) se v ČR odvádí **21 % DPH reverse charge bez nároku na odpočet** — je to reálný náklad. Systém musí evidovat:

- měsíční Booking/Airbnb vyúčtování provize (z e-mailu, případně ruční upload),
- přepočet ČNB ke dni přijetí služby (DUZP),
- sumu základu a DPH za kalendářní měsíc jako podklad pro přiznání (do 25. následujícího měsíce, jen za měsíce s přijatou službou),
- připomínku ~20. dne v měsíci.

**Souhrnné hlášení se u přijatých služeb NEpodává** (jen u poskytnutých do EU — u nás nenastává).

## DPH — plátce

- **Sazby:** ubytování **12 %**, doplňky **21 %**, reverse charge z provize zůstává 21 %.
- **Cena rezervace je brutto.** `priceTotal` z MotoPressu, ruční rezervace i OTA = částka, kterou host platí včetně DPH. Faktura ji rozpouští **daní shora** (základ = `total / 1,12`, DPH = zbytek). QR Platba i `totalAmount` zůstávají na brutto.
- **Reverse charge z provize u plátce není náklad** — má nárok na odpočet, takže do zisku rezervace nevstupuje.
- **Kontrolní hlášení a přiznání aplikace negeneruje jako XML.** Spočítá čísla a vyexportuje seznam dokladů do CSV; do portálu je zadá provozovatel nebo účetní. Aplikace dělá podklad, ne e-podání.

## Co se eviduje

Vychází z tabulky, kterou provozovatel vedl ručně v tabulkovém procesoru (vzor v gitignored `sources/`):

- rezervace: příjezd, odjezd, nocí, jméno, adresa, zdroj, e-mail, telefon, dospělí/děti/hosté, pes
- elektřina: VT/NT před a po pobytu, cena za kWh, „elektřina v ceně" ano/ne
- úklid: typ, kdo uklízí, vyplaceno
- finance: rekreační poplatek, provize OTA a %, příjem, příjem bez nákladů, výdaje, zisk, zisk/noc
- souhrny dle zdroje

Ceník **není** součást rezervace — tu logiku má MotoPress.

## Architektura toku dat

```
Booking e-mail  ─► IMAP poller ─┐
iCal feedy OTA  ─► iCal sync   ─┼─► MySQL: reservation, guest, channel, invoice, payment,
MotoPress REST  ─► sync        ─┘   message_schedule, message_log, electricity_reading, expense…
                                        │         │          │
                                        │         │          └─► dashboard / ekonomika
                                        │         └─► plánovač zpráv hostům (SMTP hostingu)
                                        └─► fakturace (mPDF, vlastní řada, ARES pro IČO)
```

## Glosář

- **Vejminek** — historická malá obytná stavba u statku, dnes pronajímaná jako apartmán.
- **MotoPress (HB)** — WordPress plugin pro správu ubytování a rezervací s napojením na OTA.
- **OTA** — Online Travel Agency (Booking, Airbnb, …).
- **Identifikovaná osoba** — neplátce DPH, který ale odvádí DPH z přijatých služeb z EU.

Aktuální rozsah implementace (co už je hotové) je v `docs/stav-projektu.md`.
