# Ubytovadlo — automatizace rezervací

**Ubytovadlo** — automatizace rezervací, fakturace a evidence pro malé ubytování. Cíl: *jedna rezervace = jediný vstup*, ze kterého se vygeneruje faktura, řádek v evidenci i plán zpráv hostům — bez placených SaaS. Provozní specifika konkrétní instance (objekt, domény, přístupy) jsou v `CLAUDE.local.md` (gitignored).

Komunikace s uživatelem probíhá v češtině. Odpovědi i názvy v kódu drž v češtině jen tam, kde je to přirozené (doménové pojmy, šablony zpráv hostům); identifikátory v kódu piš anglicky.

**Popisy a texty v aplikaci (UI, nápovědy, changelog) piš, jako by předchozí verze neexistovala** — popiš, co věc *je* a *dělá*, ne jak se to dělalo dřív ani že se něco změnilo. Žádné „místo X", „už není potřeba", „nově", „nahrazuje". Uživatel vidí jen aktuální stav; srovnání s minulostí je pro něj šum. (Changelog smí uvést fakt „přesunuto z env do UI", ale ne hodnotící rámování.)

> Lokální provozní detaily (reálná adresa schránky, IMAP host, konkrétní účty, přístupy): `@CLAUDE.local.md` — gitignored, není součástí veřejného repa.

## Kontext

Ubytování se prodává třemi kanály:

- **Vlastní web** — WordPress + plugin **MotoPress Hotel Booking** (kompletní zdroj pravdy včetně hosta a částky)
- **Booking.com** — sync do MotoPressu **přes iCal** (`https://<vas-web>/?feed=mphb.ics&accommodation_id=<ID>`). Booking posílá e‑mail jen s `res_id + datum příjezdu v předmětu` + odkaz do extranetu — **žádné údaje hosta ani cenu**. Strohý obsah je úmyslný design Booking (ne GDPR), v extranetu žádný přepínač "detaily v e‑mailu" neexistuje. Workaround pouze přes placené channel managery (Smoobu/Beds24/…) — pro jeden objekt overkill. **Pulse app** zobrazuje detaily — používáme ji jako lidský "front-end" extranetu.
- **Airbnb** — sync do MotoPressu **přes iCal**. Airbnb e‑maily mají naopak **bohatý obsah** (jméno hosta, region, příjezd/odjezd s časy, počet hostů, Airbnb potvrzující kód, cena × nocí, celkem CZK, **provize Airbnb 3 %** = podklad pro DPH reverse charge, čistá výplata). Chybí jen **plná adresa hosta** (Airbnb ji nesbírá) a reálný kontakt (anonymizovaný proxy `@reply.airbnb.com`).

**Schránka pro automatizaci:** vyhrazená e‑mailová schránka (IMAP přes SSL, port 993; konkrétní adresa/host viz `CLAUDE.local.md` a `app/.env.local`). Sem chodí Booking *Reservations* + *Invoices* notifikace (přidané kontakty v Booking Property → Contacts) a Airbnb e‑maily (přes forwarding z osobní schránky, případně přepojením Airbnb účtu).

Důsledek: pro OTA nemáme nikde automaticky dostupné údaje hosta, e‑mail, telefon, adresu ani dohodnutou cenu. Jediná spolehlivá cesta k těmto datům je extranet Bookingu / Airbnb appka, kde si je musí majitel/ka dotáhnout sám/sama. Naším úkolem je tu ruční práci minimalizovat na **jediný formulář** a všechno ostatní zautomatizovat.

Příchozí Booking e‑mail tedy slouží jen jako **trigger** ("nová rezervace existuje, dotáhni si k ní hosta"). Pro Airbnb hraje stejnou roli **iCal poller**, který zachytí nový blok v kalendáři.

## Bolest, kterou řešíme

Majitel/ka ručně:

1. Přepisuje adresu a údaje o rezervaci z Bookingu a Airbnb (do WP / interní evidence).
2. Vyplňuje stejné údaje znovu pro vystavení faktury.
3. Zapisuje rezervaci do evidence (částka, kanál, termín).
4. Hlídá si v hlavě: vystavit fakturu, poslat hostům zprávu před příjezdem, …

Cíl projektu: **rezervace v MotoPressu = jediný vstup**, vše ostatní (faktura, řádek v evidenci, plán zpráv hostům) se vygeneruje automaticky.

## Stack

- **PHP 8 / Symfony 7** + **Doctrine ORM** + **MySQL** (preferovaný stack uživatele).
- **Twig** + Bootstrap/Tailwind pro malé interní UI.
- **mPDF** (nebo Dompdf) pro generování faktur — vystavujeme si je vlastním generátorem, bez závislosti na externí fakturační službě.
- **Symfony Mailer** + IMAP přes `webklex/php-imap` pro příchozí Booking notifikace.
- **Symfony Scheduler / Messenger** spouštěné z **cronu** (sdílený hosting nemá daemon procesy).
- **ARES API** (zdarma) pro doplnění firemních údajů z IČO.

## Hosting a vývoj

**Lokální vývoj:** Docker (`docker-compose.yml` v rootu). PHP 8.4 CLI image (`docker/php/Dockerfile`) + MySQL 8.4. Žádný PHP/Composer/MySQL na hostu — vše v kontejnerech. Cesta příkazů: `docker compose exec app bin/console …`, `docker compose run --rm app composer …`.

**Produkce:** WordPress + tato aplikace na **sdíleném hostingu** (PHP, MySQL/MariaDB, IMAP, cron). Aplikace jako samostatný projekt vedle WP — vlastní subdoména nebo podsložka, vlastní MySQL databáze. **Žádné daemon procesy** — IMAP poller a další úlohy se spouští z cronu. Konkrétní hosting/účet viz `CLAUDE.local.md` a `docs/deploy.md`.

**Repo:** `https://github.com/vzoha/ubytovadlo` (public, OSS pod FSL).

**Jednorázové backfilly dat (CSV importy, ruční SQL na historických rezervacích, …) NEcommituj.** Provedou se jednou lokálně proti dev DB a na produkci se přenesou jako součást kompletního `mysqldump` při deployi. Audit-trail těchto skriptů můžeš nechat v `sources/` (gitignored), ale logika do `src/` ani migrace nepatří — backfill není schema change, je to obsah.

## Proces vývoje nových funkcí

Repo je **veřejné OSS** (FSL). Každá funkce projde tímhle, ať se nic nerozbije a neunikne tajemství:

**1. Vývoj — definition of done.** Kód v `app/`, schéma jen přes Doctrine migrace. U každé funkce:

- **rozumné testy** — unit i funkční, ne jen happy path (hraniční stavy, idempotence, prázdné vstupy)
- **demo fixtures** aktualizovat (`app:dev:import-fixtures` / seed), ať demo i screenshoty ukazují novou funkci na **neutrálních datech** (žádné reálné PII)
- **revize kódu** přes `/code-review` — SOLID, clean code, DRY (žádná duplikace logiky, krátké metody, jasné názvy, jednotný styl s okolím)
- **jen public do repa** (viz krok 2)
- **mechanické kontroly zelené:** `docker compose exec app composer check` (= `cs:check` + PHPStan L6 + PHPUnit). CI (`.github/workflows/ci.yml`) to zrcadlí — co projde lokálně, projde i tam
- záznam do `CHANGELOG.md` (sekce `[Unreleased]`)

**Git hooky** (jednorázově po clonu): `git config core.hooksPath .githooks`. pre-commit hlídá únik privátních souborů/tajemství + style, pre-push pustí PHPStan + testy. Obejití `git commit/push --no-verify`.

**2. Public vs. private — co NEcommitovat.** Tajemství (hesla, klíče, IMAP/MotoPress creds) **jen v `app/.env.local`** (gitignored), nikdy do kódu ani `.env`. Mimo repo (gitignored) patří: `/sources/` (vzorky e-mailů, CSV, backfilly), `/docs/private/` (interní runbooky, plány), `/CLAUDE.local.md` (identita instance), `public/assets/logo.png` (značka instance), `config/secrets/prod`, `/www/`. Per-instance věci řeš přes env/soubor s graceful fallbackem, ne natvrdo. Žádné reálné PII v kódu, testech ani fixtures (demo jména neutrální). Před commitem `git status` přečíst — vědět, co přidávám.

**3. Release.** SemVer, anotované tagy `vX.Y.Z`. Do `CHANGELOG.md` přidat sekci verze (formát Keep a Changelog, česky: Přidáno/Změněno/Opraveno + odkaz na GitHub release). Tag + push + GitHub release. Drobné funkce se můžou kupit do příští verze — netagovat každý commit.

**4. Deploy na produkci** (sdílený hosting, runbook `docs/deploy.md`): `git pull` → `composer install --no-dev --optimize-autoloader` → `composer deploy-www` → `bin/console doctrine:migrations:migrate --env=prod`. Nová funkce s cronem → doplnit cron úlohu (deploy.md §7). Po deployi smoke checklist (deploy.md §8). Konkrétní instance (doména, hosting, účet, creds) → `CLAUDE.local.md` + `docs/private/`.

## Architektura

```
┌────────────────────────────┐
│ Booking e-mail "Nová rez." │──► IMAP poller ─► detekce nové/storno rezervace
└────────────────────────────┘                       │
┌────────────────────────────┐                       ▼
│ iCal feed MotoPressu       │──► iCal sync ─► detekce Airbnb bloků (e-mail nechodí)
└────────────────────────────┘                       │
┌────────────────────────────┐                       │
│ MotoPress REST API         │──► WP rezervace s plnými údaji hosta
└────────────────────────────┘                       │
                                                     ▼
                              ┌─────────────────────────────────────┐
                              │   MySQL: reservation, guest,        │
                              │   channel, invoice, payment,        │
                              │   message_schedule, message_log,    │
                              │   electricity_reading, expense, ... │
                              └────┬─────────┬──────────┬───────────┘
                                   │         │          │
                                   │         │          ▼
                                   │         │   přehled / dashboard (evidence příjmů/výdajů)
                                   │         ▼
                                   │   plánovač zpráv hostům (SMTP přes hosting)
                                   ▼
                       fakturace (mPDF, vlastní číselná řada, ARES pro IČO)
```

## Klíčový tok — OTA rezervace ("zúžení ruční práce")

Protože Booking ani Airbnb nedávají automaticky údaje hosta, ruční zadání úplně nezmizí. Cílem je dostat ho **na jedno místo a co nejméně polí**.

1. Trigger: nový Booking e-mail (Booking) nebo nový blok v iCalu, který nemáme v DB (Airbnb).
2. Aplikace založí `reservation` ve stavu `needs_details` s tím, co ví (kanál, datum příjezdu/odjezdu, případně Booking `res_id`).
3. Pošle majitelce **jediný e-mail**: *"Nová Booking rezervace 13. dubna — doplň údaje"*, s odkazem do našeho UI a paralelně přímo na Booking extranet.
4. Majitelka v extranetu otevře rezervaci, opíše do našeho formuláře jméno / adresu / kontakt / cenu / počet osob → uloží.
5. Po uložení automaticky: vystavení faktury (PDF + zápis do DB), naplánování pre-arrival a post-stay e‑mailu hostovi, zápis stavu do dashboardu.

(Volitelně později: browser extension, která údaje přečte z DOMu Booking extranetu a pošle je do našeho API jedním kliknutím. Tím by se ruční práce zmenšila na 1 klik.)

## Klíčový tok — rezervace z vlastního webu

1. MotoPress REST API (auth) každých pár minut → import nových potvrzených rezervací s plnými údaji hosta (které host vyplnil v MotoPress formuláři).
2. Bez `needs_details` stavu, rovnou se vytvoří faktura + naplánují se zprávy.

## Pravidla pro návrh

- **Bez placených SaaS** (fakturace ani SMTP). Faktury si generujeme sami.
- **Žádný daemon proces** — cron + krátké commandy.
- **Všechny rezervace jsou v naší DB**, bez ohledu na zdroj. Evidence žije tady, ne v externí tabulce.
- **WP/MotoPress** je jen jeden ze vstupů — žádná primární logika v PHP pluginu uvnitř WP.
- E-mail šablony v Twigu, číselné řady fakturace v DB.
- **UX drž konzistentní a promyšlené.** Před každou prací na UI (Twig šablony, formuláře, dashboard, layout) načti skill `frontend-design` a řiď se jím: jednotná vizuální hierarchie, konzistentní komponenty/spacing/typografie napříč stránkami, přístupnost, jasné stavy (prázdno/chyba/načítání), srozumitelné akce. Cíl je appka, která je uxově dobrá jako celek, ne jednotlivé stránky zvlášť.

## Datová struktura — z čeho vycházíme

`sources/Vejminek - kalkulace - 2026.csv` ukazuje, co majitelka eviduje:

- rezervace: příjezd, odjezd, nocí, jméno, adresa, zdroj, e‑mail, telefon, počet dospělých/dětí/hostů, pes
- ceník (NE rezervace) — ceny den/noc, mimo/hlavní sezóna, pro 2/4 osoby, 2/7 nocí
- elektřina: VT/NT před a po pobytu, ceny za kWh, "elektřina v ceně ANO/NE"
- úklid: typ, kdo uklízí, vyplaceno
- finance: rekreační poplatek, provize OTA, %, příjem, příjem bez nákladů, výdaje, zisk, zisk/noc
- statistiky a souhrny dle zdroje

Z toho vyplývá DB schéma; ceník můžeme držet stranou (nebo úplně přenechat MotoPressu, který tu logiku má).

## Fakturační toky

Majitelka **není plátce DPH**, je **identifikovaná osoba** (§6h/§6i ZDPH). Hostům fakturuje vždy **bez DPH** s poznámkou o identifikované osobě a vlastním DIČ. Existuje pět toků; tři "web" varianty se rozliší podle MotoPress `payment.gateway_id`:

1. **Web — klasika (soukromý host).** MotoPress `gateway_id = bank`. Host platí zálohu 1000 Kč (potvrzení příjmu zálohy je dnes manuální). Vystavuje se **zálohová faktura** (1000 Kč). Zbytek host doplácí při příjezdu — buď QR kódem na faktuře, nebo hotově. **Konečná faktura** (s odpočtem zálohy) se posílá hostovi spolu se zálohovou až **během pobytu**.
2. **Web — FKSP** (zaměstnanecký fond). MotoPress `gateway_id = cash` (MotoPress FKSP gateway nemá, používá se `cash` jako konvence). Žádná záloha. Faktura na celou částku, ale **až po obdržení fakturačních údajů firmy**. Stav rezervace: `needs_billing_details` → po doplnění → vystavení.
3. **Web — admin/známí** (rodina/známí). MotoPress `gateway_id = manual` — rezervaci založil provozovatel z WP adminu pro známého/rodinu. Žádná záloha. Faktura na celou částku během pobytu.
4. **Airbnb.** Údaje hosta a kontakt sbíráme **osobně na startu pobytu** (Airbnb je neposílá). Faktura v CZK na celou částku, e‑mailem hostovi pokud chce.
5. **Booking.** Adresu hosta tahám z extranetu. Cena je v EUR → faktura v CZK přepočtem **kurzem ČNB ke dni vystavení** (`api.cnb.cz/cnbapi/exrates/daily`). Vystavuje se během pobytu.

Faktura musí umět nést: vlastní číselnou řadu, variabilní symbol, QR Platbu (SPAYD), původní cizí měnu + kurz + datum kurzu, odkaz na zálohu (u konečné), poznámku o identifikované osobě, DIČ.

Dispatcher pro sync rezervace z MotoPressu:

1. `booking.imported == true` → **iCal blok z OTA**. `total_price = 0`, `payments = []`, žádný gateway. Kanál se určí z `ical_prodid` (`airbnb.com` vs `admin.booking.com`). Webová fakturace se nepoužije — jede OTA tok (Airbnb/Booking).
2. `booking.imported == false` → **web rezervace**. Tok se určí z `gateway_id` první platby:

| `gateway_id` | Tok | Faktura |
|---|---|---|
| `bank` | Web klasika | Záloha 1000 Kč + doplatek se odpočtem |
| `cash` | Web FKSP | Jedna faktura na celou částku po doplnění firmy |
| `manual` | Web admin/známí | Jedna faktura na celou částku během pobytu |
| (žádná platba) | Nezaplaceno / čekající | Žádná akce |

Manuální override v UI zůstává pro případ, že konvence selže.

## DPH — identifikovaná osoba

Z **provizí Booking/Airbnb** (přijatá služba z EU) odvádí majitelka v ČR **21 % DPH reverse charge** bez nároku na odpočet — je to reálný náklad. Systém **musí** evidovat:

- Měsíční Booking/Airbnb invoice (z e‑mailu, případně manuální upload). Booking jakožto B2B partner posílá souhrnné měsíční vyúčtování provize.
- Přepočet ČNB ke dni přijetí služby (DUZP).
- Sumu základu a DPH za kalendářní měsíc → podklad pro DPH přiznání (do 25. následujícího měsíce, jen za měsíce s přijatou službou). **Souhrnné hlášení se u přijatých služeb NEpodává** (jen u poskytnutých do EU — u nás nenastává).
- Připomínku ~20. dne v měsíci, ať si majitelka stáhne, co chybí, a dá účetní.

Tohle není volitelný modul — bez něj se obejde jen, dokud finanční úřad nezaklepe.

## Otevřené otázky / TODO

- **OTA notifikace**: zapnout v Booking extranetu plné detaily a v Airbnb ověřit/zapnout e‑mail notifikace. Po zapnutí mi poslat aktuální vzorek Booking e‑mailu — zúžíme `needs_details` formulář na to, co opravdu chybí (typicky jen adresa + případné firemní údaje).
- **Záloha 1000 Kč**: jak teď chodí (převod / GoPay / něco jiného)? Lze automatizovat spárování se rezervací (variabilní symbol, callback brány, parsování bankovního výpisu)?
- **Browser extension** pro Booking/Airbnb extranet (1 klik místo formuláře) — odložené rozhodnutí, ale realistická volba.
- **Subdoména** pro aplikaci na sdíleném hostingu — ověřit, že tarif povolí samostatnou DocumentRoot a samostatnou MySQL DB.
- **Číselná řada faktur**: navázat na dosavadní číslování, nebo začít novou řadu (po dohodě s účetní)?
- **Booking/Airbnb měsíční vyúčtování**: kam přesně chodí, v jakém formátu (PDF s tabulkou? CSV?), jestli jsou v IMAPu mezi vzorky.
- **MotoPress jako konektor (autorita = Ubytovadlo)**: import je jednosměrný a **volitelný** (přepínač „Importovat rezervace z MotoPressu" na Připojení + `MotoPressSettings::enabled`; command přeskočí i bez vyplněných přístupů). **Novou** rezervaci sync naimportuje celou; u **existující** rezervace už jen doplní prázdná pole, aktualizuje datumy (obsazenost) a promítne storno — ruční edity (jméno, cena, stav) nepřepisuje. Jediný zápisový směr do MotoPressu je push stavu platby (`MotoPressPaymentSyncListener`, gated `push_payments`). V budoucnu zvážit skutečný obousměrný sync (push změn zpět do REST API).

## Glosář

- **Vejminek** — historická malá obytná stavba u statku, dnes pronajímaná jako apartmán.
- **MotoPress (HB)** — WordPress plugin pro správu ubytování a rezervací s napojením na OTAs.
- **OTA** — Online Travel Agency (Booking, Airbnb, …).

## Stav projektu

Aktuální rozsah — co MVP umí, v2 progress a doporučené pořadí dalších kroků: **`docs/stav-projektu.md`**.

**Chybí:** iCal sync (sanity check obsazenosti / Airbnb cancellations).

**Produkce běží na sdíleném hostingu** (viz `docs/deploy.md`). Důsledek: **všechny změny DB schématu výhradně přes Doctrine migrace** — žádné `schema:update` ani ad-hoc SQL; produkce se aktualizuje `doctrine:migrations:migrate`.

**Check‑in UX:** online check‑in sbírá doklady **jen u cizinců** (Ubyport), čistě česká skupina dokončí bez vyplňování. MRZ sken: foto/upload + **live kamera** (vodítko, grading jas/glare/ostrost, auto‑cvak). Fakturační adresu **objednatele** si host doplní sám, když chybí (`CheckinBillingType`), s **ARES** autofillem z IČO (`App\Ares\AresClient`).
