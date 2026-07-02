# Changelog

Všechny podstatné změny v tomto projektu se zaznamenávají sem.
Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování dle [SemVer](https://semver.org/lang/cs/).

## [Unreleased]

### Přidáno

- **Evidence účtů, výdajů a uzávěrek (cashflow modul).** Nová sekce `/ucty`:
  univerzální **účty** (banka / hotovost, uživatelsky definované; entita `Account`),
  **výdaje a převody** mezi vlastními účty v jednotném ledgeru (`LedgerEntry`:
  výdaj / převod / korekce), a **uzávěrky** (`BalanceStatement`) — ruční snapshot
  reálného stavu účtu, ke kterému systém dopočítá **očekávaný stav** a ukáže rozdíl;
  rozdíl lze jedním klikem srovnat korekcí. Výdaje mají kategorie (`ExpenseCategory`)
  s rozlišením provozních (jdou do Ekonomiky) a nevýdělkových (splátka úvěru, osobní
  výběr — jen snižují stav účtu). Ekonomika (`/ekonomika`) nově ukazuje samostatný
  blok **„Obecné výdaje"** (provozní kategorie), mimo per-rezervační zisk.
- **Reálně přijaté platby per rezervace (`ReservationReceipt`).** **Dílčí platby** —
  víc řádků na rezervaci, každý s **vlastním datem přijetí**, upsertované podle
  původu (`originType` + `originId`) s rozlišením kanálu:
  - **Přímá objednávka (web):** reálný příjem = **každá zaplacená faktura** — u web
    klasiky **záloha** (přichází dřív, typicky jiný měsíc) a **doplatek** (při
    příjezdu) jako dva samostatné řádky se svými daty, takže měsíční cashflow sedí;
    jinak spárované bankovní platby. Dokud host nezaplatí, na účtu nic není.
    Napojeno na `InvoiceService::markPaid` a spárování platby (`PaymentSettledEvent`).
  - **Airbnb / Booking:** faktura vystavená v průběhu pobytu je jen doklad; příjem
    se vede jako **odhad net (hrubá − provize)** a **nahradí se reálnou výplatou** —
    Airbnb automaticky z výplatního mailu, u Bookingu (a obecně) **ručně** přes
    formulář „Reálná výplata" na detailu rezervace. Ruční výplata odhad „zamkne".
  Idempotentní přepočet (`app:cashflow:recompute-incomes`) auto-platby synchronizuje,
  ruční nechává být → stejná platba se nikdy nezapočte dvakrát. Do **stavu účtu**
  vstupují jen **skutečně přijaté** platby (odhad = výhled, mimo zůstatek);
  **zrušené a nedotažené** rezervace se nepočítají. `/ucty` zobrazuje přijaté platby
  i očekávané výplaty (výhled); detail rezervace ukazuje rozpis dílčích plateb.
  Demo seed zakládá účty, výdaje, převod a uzávěrku na neutrálních datech.
- **Cashflow UI — editace, filtr, měsíční souhrn, CSV export.** Pohyby (`LedgerEntry`)
  i účty (`Account`) lze **upravit**, ne jen přidat/smazat. Přehled pohybů i tabulka
  **přijatých příjmů** mají **stránkování** (nezávislé); pohyby navíc **filtr**
  (účet / typ / období). Nová stránka **měsíční souhrn** (`/ucty/souhrn/{rok}`):
  přijaté platby proti výdajům (provozní vs. osobní odliv) po měsících;
  **CSV export** filtrovaných pohybů.
- **Nerezervační příjmy** (`LedgerEntryType::INCOME` — „ostatní příjem"): úroky
  z účtu, storno-poplatky, náhrady. Formulář na `/ucty`, vstupují do stavu účtu
  i do měsíčního souhrnu (ne do per-rezervačního zisku v Ekonomice).
- **Kategorie výdajů — skupiny a lepší členění.** Výdaje rozdělené do skupin
  **„Provoz ubytování"** vs **„Osobní a finanční"** (`ExpenseGroup`): dropdown je
  seskupený (optgroup) a osobní odliv (splátka úvěru, výběr majitele) je v přehledu
  vizuálně odlišený. Přibyly kategorie **úklid, spotřební materiál a drogerie,
  pojištění** a osobní **„ostatní"** (osobní nákupy mimo výběr/splátku); popisky zpřesněny.
- **Zrušená rezervace se zaplacenou fakturou vede příjem** — nevrácená záloha /
  storno-poplatek je reálný příjem (peníze přišly a nevrátily se); u zrušeného
  pobytu se nevede jen odhad budoucí OTA výplaty.

### Opraveno

- **Airbnb `price_total` = hrubá tržba hostitele, ne guest total.** Parser bral cenu
  z „Celkem (CZK)" — jenže Airbnb tak označuje **částku zaplacenou hostem** (včetně
  servisního poplatku hosta a daní, které jdou Airbnb a hostiteli nikdy nedojdou).
  Nově bere **výdělkovou stranu** (čistý výdělek „Vyděláš si" + servisní poplatek
  hostitele 3 %). Opravuje nadhodnocený Airbnb příjem v Cashflow i Ekonomice
  (např. odhad 3 967 → reálných 3 298 Kč).

- **Párování příchozích plateb (notifikace ČS) → automatické vystavení faktury.**
  IMAP poller nově zpracovává e-mail České spořitelny „Přišla platba"
  (`App\Email\CsPaymentParser`): vytáhne částku, variabilní symbol, účet protistrany
  a směr platby. `App\Payment\PaymentProcessor` platbu zaeviduje (nová entita
  `Payment` jako source of truth „peníze dorazily", i pro nespárované platby) a
  napáruje podle VS — buď na fakturu (VS = číslo faktury, host platil z QR), nebo
  na rezervaci (VS = MotoPress booking ID). U webové klasiky (záloha + doplatek)
  ve výši zálohy vystaví (chybí-li) a označí **zálohovou fakturu uhrazenou**,
  doplní časovou osu. Vystavení přeskočí, pokud rezervaci chybí údaje hosta
  (platba se přesto zaeviduje). Odpadá ruční potvrzování platby. Demo seed
  zakládá ukázkové platby na neutrálních datech.
- **Volitelný push stavu platby do MotoPressu.** Po spárování platby vyšle jádro
  doménovou událost `PaymentSettledEvent`; konektor `MotoPressPaymentSyncListener`
  na ni reaguje a přes REST API označí odpovídající platbu v MotoPressu jako
  `completed` (MotoPress si rezervaci sám potvrdí). Zapíná se přepínačem
  `MOTOPRESS_PUSH_PAYMENTS` (default vyp.; vyžaduje API klíč s právem Write).
  Jádro o MotoPressu neví — instance bez něj listener nemá a událost zůstane bez
  efektu. Selhání MotoPressu jen zaloguje, zpracování platby neshodí.

- **Přístupové údaje v UI místo .env (šifrovaně v DB).** Nová stránka
  `/nastaveni/pripojeni` — IMAP schránka a MotoPress REST API se zadají v UI a uloží
  **šifrovaně** v tabulce `credential` (libsodium secretbox; master klíč
  `APP_CREDENTIALS_KEY` zůstává v env). `CredentialProvider` čte přednostně z DB,
  fallback je `.env` (self-host/vývoj funguje beze změny). Tajemství se v UI nikdy
  nezobrazují zpět (write-only, prázdné = beze změny). Připraveno na hostovaný/
  multi-tenant provoz, kde si creds vyplní uživatel a nesmí do env.

### Změněno

- **Region hosta z Airbnb e-mailu se extrahuje strukturálně** (region = „Město/kraj,
  jednoslovná země" za značkou „Totožnost ověřena") — odpadá konfigurace
  `AIRBNB_LISTING_NAME`, která je tím zrušena.
- **`cache:clear` odebrán z composer `auto-scripts`** (post-install). Na PHP 8.5
  hostu spouštěl composer warmup v subprocessu s nezapisovatelným temp direm →
  `tempnam()` `E_NOTICE`, kterou symfony/translation 7.4.x nepotlačuje (fix až
  Symfony 8), a warmup padal. Ruční `bin/console cache:clear` (čisté CLI) funguje;
  je proto povinným krokem deploye (viz `docs/deploy.md`).

## [0.5.0] — 2026-06-15

### Přidáno

- **Markdown toolbar editoru** — nad tělem zprávy (`/nastaveni/zpravy/{kind}`)
  i patičkou e-mailů (`/nastaveni/mail`) lišta s ikonami: zpět/vpřed (undo/redo),
  tučně, kurzíva, nadpis, odkaz, citace, kód, odrážky, číslovaný seznam, oddělovač.
  Formátování řeší web component `@github/markdown-toolbar-element` (27 kB, bez
  závislostí, vendorováno, ESM modul), undo/redo a oddělovač `markdown-editor.js`.
  Sdílený Twig partial `_partials/markdown_editor.html.twig`. Pracuje nad nativní
  `<textarea>`, takže paleta proměnných i živý náhled fungují beze změny (úprava
  dispatchne `input` event).
- **CTA tlačítko do e-mailu** — v těle zprávy lze přes syntaxi `[[button:Text|odkaz]]`
  (typicky `[[button:Dokončit check-in|{{ checkin_url }}]]`) vložit výrazné tlačítko;
  vyrenderuje se jako **vycentrované** table-based tlačítko v barvě akcentu tématu
  (kompatibilní s poštovními klienty). Vkládá ho i tlačítko v toolbaru editoru. Popisek i odkaz se HTML-escapují.
  Ukázková rezervace pro náhled dostala check-in token, takže `{{ checkin_url }}` v náhledu
  i testovacím odeslání vede na reálnou adresu. Výchozí šablona „Před příjezdem" používá
  pro odkaz na online check-in právě toto tlačítko.
- **Server-side náhled patičky** (`/nastaveni/mail`) — patička se v náhledu
  vzhledu renderuje z Markdownu na serveru (`GuestMessageRenderer::renderFooterPreview`,
  endpoint `mail_settings_footer_preview`), shodně se skutečným e-mailem a s dosazením
  proměnných z ukázkové rezervace. Dřív se ukazovala jen jako prostý text.

### Změněno

- **Drobné úpravy popisů v nastavení** — odebrán nadbytečný popis u „Jméno odesílatele"
  (opakoval label), z intro „Dodavatel na faktuře" vypuštěn vývojářský žargon o `.env`.
  CTA tlačítko v náhledu nastavení e-mailů vycentrované.
- **Šablony zpráv jsou nově defaultně s vypnutým odesíláním** (`enabled = false`).
  Žádná zpráva hostům se neodešle, dokud konkrétní šablonu ručně nezapneš —
  bezpečnější výchozí stav (nehrozí nechtěné automatické odeslání). Týká se nových
  i dosud nenakonfigurovaných druhů zpráv; uložené šablony si svůj stav drží.
- **Zprávy hostům e-mailem** (roadmap bod 4) — odesílání naplánovaných zpráv přes
  Symfony Mailer (SMTP hostingu, `MAILER_DSN` v `.env.local`). Tři vrstvy:
  - **Nastavení e-mailů** (`/nastaveni/mail`) — odesílatel, Reply-To, patička,
    zobrazení loga a barevné téma (5 presetů + vlastní hexy). Klíče `mail.*`
    v tabulce `setting`, `MailSettingsProvider` s fallbackem na dodavatele faktury.
  - **Šablony zpráv** (`/nastaveni/zpravy`) — editovatelný předmět + tělo v Markdownu
    s proměnnými (`{{ guest_first_name }}`, `{{ check_in }}`, `{{ balance_due }}`, …)
    pro 5 druhů (před příjezdem, po pobytu, připomínka doplatku, faktura, vlastní).
    Výchozí texty v kódu (`MessageTemplateDefaults`), DB drží jen override
    (`message_template`). Paleta proměnných, **živý náhled** a **testovací odeslání**
    na vlastní e-mail. Proměnné se dosazují bezpečně (whitelist, ne Twig nad textem).
  - **Master layout** (`templates/email/_layout.html.twig`) — table-based HTML
    s tématem; tělo (Markdown→HTML přes `league/commonmark`, `html_input: escape`)
    a patička se vloží dovnitř, logo přes `cid`.
  - Odeslání zapojeno do `app:actions:run`: pre-arrival / post-stay / vlastní zpráva
    se v okně platnosti odešlou (jinak SKIPPED), připomínka doplatku pošle hostovi
    jednu výzvu, dokud není uhrazeno. Audit + pojistka proti duplicitě v `guest_message`.
  - **Faktura e-mailem** (roadmap bod 2, poslední kus) — tlačítko na detailu
    rezervace odešle hostovi vystavenou fakturu v PDF příloze (druh zprávy `invoice`).

## [0.4.0] — 2026-06-14

### Přidáno

- **Ochrana importovaných faktur před regenerací** — `Invoice.pdfSource`
  (`generated` / `external`). `app:invoice:regenerate-pdf` přeskakuje externí
  (importované / ručně nahrané) PDF, která aplikace neumí reprodukovat; `--force`
  vynutí přepis. Které faktury jsou externí = data instance (backfill), ne kód.

## [0.3.0] — 2026-06-14

### Přidáno

- **Dodavatel na faktuře v nastavení aplikace** — fakturační identita (jméno,
  adresa, IČO/DIČ, kontakt, číslo účtu, IBAN) se nastavuje v UI
  `/nastaveni/dodavatel` a ukládá do DB (tabulka `setting`) místo editace `.env`.
  Každá instance si dodavatele nastaví sama. `IssuerProfileProvider` s fallbackem
  na `.env` (`INVOICE_ISSUER_*`) pro nenakonfigurované instance / vývoj.

## [0.2.1] — 2026-06-14

### Změněno

- **Okno platnosti zpráv hostům** — naplánovaná zpráva (pre-arrival/post-stay/
  custom) se po vypršení okna označí `SKIPPED` místo věčného visení jako „po
  termínu". Pre-arrival platí do příjezdu, post-stay do 3 dnů po odjezdu. Pojistka,
  aby se po nasazení odesílání nikdy neodeslaly prošlé zprávy zpětně.

### Opraveno

- **Deploy na sdílený hosting s nezapisovatelným `/tmp`** — `config/tmpdir.php`
  přesměruje `TMPDIR` na projektový `var/tmp`, když je systémový temp mimo
  open_basedir. Bez toho padal `cache:clear` (Symfony XliffUtils → `tempnam()`)
  na PHP 8.5. Zapojeno do všech vstupních bodů (CLI, web, shim, cron bootstrap).
  Aktivuje se jen na postižených hostech, jinde no-op.

## [0.2.0] — 2026-06-14

### Přidáno

- **Časová osa rezervace + CRM poznámky** — na detailu rezervace jeden chronologický
  feed: systémové události (založení, faktura, check-in, výplata) + ruční typované
  poznámky (poznámka/hovor/e-mail/zpráva/osobně) + naplánované akce (zpráva před
  příjezdem/po pobytu, doplatková faktura, připomínka doplatku, Ubyport u cizinců)
  s možností odložit/upravit/zrušit/spustit. Automatické akce zakládá idempotentní
  plánovač (cron `app:actions:plan`), `app:actions:run` vyhodnocuje akce po termínu.
- **Doplatek u ceny** — kolik hostovi zbývá doplatit (cena − zaplacené faktury).

### Známá omezení

- Odesílání zpráv hostům e-mailem zatím není v provozu — akce typu zpráva se jen
  plánují a zobrazují (čeká na samostatnou funkci „Zprávy hostům").

## [0.1.1] — 2026-06-12

### Zabezpečení

- Aktualizace závislostí — oprava 33 security advisories (`composer update`),
  sync `composer.lock` content-hashe s `composer.json`.

## [0.1.0] — 2026-06-12

První veřejné vydání. Ubytovadlo běží v reálném provozu; tohle je čistý
iniciální snapshot vyčleněný z interního vývoje (bez přenosu git historie).

### Přidáno

- **Sběr rezervací ze 3 kanálů** — vlastní web (MotoPress REST sync), Booking.com
  (IMAP trigger z notifikačního e-mailu) a Airbnb (parser potvrzovacího e-mailu).
  Všechny rezervace v jedné databázi bez ohledu na zdroj.
- **Fakturace hostům** — generování PDF (mPDF), QR Platba (SPAYD), vlastní číselná
  řada, přepočet EUR→CZK kurzem ČNB, pět fakturačních toků (web klasika se zálohou,
  FKSP, admin/známí, Airbnb, Booking) mapovaných z platební brány.
- **DPH modul pro identifikovanou osobu** (§6h ZDPH) — reverse charge z provizí
  Booking/Airbnb, import měsíční Booking faktury z PDF, ruční upload Airbnb receiptu,
  agregace `VatPeriod`, reconciliation a přehled `/dph`.
- **Ekonomika** — zisk per rezervace (po odečtu elektřiny, úklidu, rekreačního
  poplatku, provizí a DPH), přehled `/ekonomika` dělený na uskutečněno vs. očekáváno.
- **Online check-in** — sběr dokladů jen u cizinců (Ubyport), MRZ sken pasu
  (upload i live kamera), doplnění fakturační adresy objednatele s ARES autofillem z IČO.
- **Úklid** — evidence úklidů s konfigurovatelným ceníkem podle typu.
- **Login + security**, **dashboard** s přehledem nadcházejících pobytů, úklidů,
  faktur k vystavení a daňových lhůt.
- **Deploy na sdílený hosting** — bez daemon procesů, úlohy přes cron
  (IMAP poller, MotoPress sync). Runbook v [`docs/deploy.md`](docs/deploy.md).
- **CI** — php-cs-fixer, PHPStan (level 6) a PHPUnit (186 testů) přes GitHub Actions.

### Známá omezení

- Odesílání faktur a zpráv hostům e-mailem zatím není automatizované.
- iCal sanity sync (kontrola obsazenosti, detekce Airbnb storen) chybí.
- Cílí zatím na jednu ubytovací jednotku (multi-unit je v plánu).

[0.5.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.5.0
[0.4.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.4.0
[0.3.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.3.0
[0.2.1]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.2.1
[0.2.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.2.0
[0.1.1]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.1
[0.1.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.0
