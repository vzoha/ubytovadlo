# Stav projektu

V `app/` běží Symfony 7.4 projekt s Doctrine + MySQL.

## Co MVP umí

- **IMAP poller** `app:imap:poll` — čte automatizační schránku (viz `app/.env.local`), idempotentní podle Message-ID v `email_log`. Volby: `--all` (i SEEN), `--dry-run` (nemarkovat Seen).
- **Airbnb parser** (`App\Email\AirbnbReservationParser`) — z HTML body Airbnb potvrzovacího e-mailu vytáhne potvrzující kód, jméno, region, příjezd/odjezd s časy, počet hostů (dospělí/děti/kojenci), cenu/noc, počet nocí, celkem, provizi Airbnb 3 %, čistou výplatu. Region hosta se extrahuje **strukturálně** (region = „Město/kraj, jednoslovná země" za značkou „Totožnost ověřena") — bez konfigurace názvu inzerátu.
- **Booking trigger parser** — z předmětu vytáhne `res_id` + datum příjezdu (víc Booking neposkytuje), vytvoří rezervaci ve stavu `needs_details`.
- **Dashboard** (Bootstrap 5, lokálně v `app/public/assets/`) — seznam s filtrem podle stavu, detail, formulář na doplnění adresy/firmy/IČO/DIČ.
- **52 PHPUnit testů** zelených (jednotky + functional `VatControllerTest`).

## Hotové nad rámec MVP (v2 progress)

- **Login + security** — `App\Entity\User` + form login (`/login`, `/logout`).
- **MotoPress REST sync** — `app:motopress:sync` import rezervací z vlastního webu (kompletní údaje hosta).
- **Booking extranet import** — strukturovaná adresa, doplnění z formuláře po triggeru.
- **DPH modul reverse charge** (§6h ZDPH, identifikovaná osoba):
  - per-rezervační base/DPH 21 % na `Reservation` (kurz ČNB k DUZP)
  - import měsíční Booking faktury z PDF přílohy (smalot/pdfparser)
  - ruční upload Airbnb měsíčního receiptu
  - `/dph` přehled měsíců + detail s reconciliation (rezervace vs. faktura)
  - `VatPeriod` agregace + `filed_at` značka odeslání na FÚ
  - commandy `app:vat:recalculate`, `app:vat:reconcile`
- **Fakturace hostům** — mPDF PDF generátor + SPAYD QR Platba, číselná řada `RRRR###` (start `2026012` navazuje na dosavadní číslování), 3 webové toky (`STANDARD_WITH_DEPOSIT`/`FKSP`/`ADMIN_BOOKING`) mapované z `payment.gateway_id`, OTA toky (Airbnb v CZK, Booking EUR→CZK přes ČNB). `BillingMode` na rezervaci, server-side guard proti duplicitnímu vystavení, snapshot odběratele/dodavatele do faktury. Commandy `app:invoice:smoke`, `app:invoice:regenerate-pdf`, `app:invoice:refresh-qr`.
- **Časová osa rezervace + CRM poznámky** (`App\Timeline`) — na detailu rezervace jeden chronologický feed: odvozené systémové události (založení, faktura, check-in, výplata — neukládají se, skládá je `ReservationTimelineBuilder`) + ruční typované poznámky (`ReservationNote`: poznámka/hovor/e-mail/zpráva/osobně) + naplánované akce (`ReservationAction`) s tlačítky odložit/upravit/zrušit/spustit. Automatické akce (pre-arrival/post-stay zpráva, doplatková faktura, připomínka doplatku, Ubyport u cizinců) zakládá idempotentně `ReservationActionPlanner` (při potvrzení v UI + cron `app:actions:plan`). Cron `app:actions:run` vyhodnocuje akce, kterým nadešel čas — self-resolving připomínky (doplatek uhrazen / faktura vystavena / host nahlášen) i **odeslání zpráv hostům e-mailem** (pre-arrival/post-stay/vlastní v okně platnosti, připomínka doplatku; viz `App\Mail`). Doplatek (`App\Invoice\BalanceCalculator`, cena − zaplacené faktury, jen CZK) je i u ceny v kartě Finance. Plán: `docs/private/plan-timeline-crm.md`.
- **Ekonomika** (`App\Profit`) — zisk per rezervace dle vzorce: Zisk = Příjem − (elektřina + úklid + rekreační poplatek 15 Kč × dospělí × noci + provize + DPH z provize). Příjem: faktura (FULL, nebo FINAL+záloha) > cena v CZK > Booking EUR × uložený ČNB kurz (= odhad, značeno `*`); starší EUR faktury se přepočítávají kurzem. On-the-fly (`ReservationProfitCalculator`, batch bez N+1, žádné DB sloupce), sazba poplatku v Setting `recreation_fee.per_adult_night`. UI: `/ekonomika/{rok}` (tabulka jako CSV + souhrn dle kanálu), karta na detailu rezervace, souhrn na dashboardu — vše dělené na **uskutečněno vs. očekáváno** (budoucí pobyty bez elektřiny/reálného úklidu = jen výhled). Ověřeno proti CSV 2023–2026 (audit `sources/compare_ekonomika.php`): složky sedí na koruny; CSV 2023–2025 nezapočítávala DPH do výdajů — app ano, vědomě.
- **Cashflow / účty** (`App\Cashflow`, `/ucty`) — univerzální účty (`Account`: banka/hotovost), jednotný ledger (`LedgerEntry`: výdaj/**ostatní příjem** (úroky, storno-poplatky)/převod mezi vlastními účty/korekce) s kategoriemi (`ExpenseCategory`, `isOperating()` = provozní vs. nevýdělkové jako splátka úvěru/osobní výběr), uzávěrky (`BalanceStatement`) s dopočtem očekávaného stavu a srovnáním rozdílu korekcí (`AccountBalanceCalculator`, `BalanceStatementReconciler`). **Nespárované příchozí bankovní platby se do stavu účtu ZÁMĚRNĚ nepočítají** — na účet chodí i příjmy nesouvisející s pronájmem (soukromé, vklady); do cashflow vstoupí až ručním spárováním s rezervací nebo zaúčtováním, zbytek srovná uzávěrka. Reálně přijaté platby per rezervace jsou **dílčí platby** `ReservationReceipt` — víc řádků na rezervaci, každý s vlastním datem přijetí, upsertované dle **původu** (`ReceiptOrigin` `originType`+`originId`, idempotentně) a **kanálu** (`IncomeUpserter`): **web** = reálný příjem z **každé zaplacené faktury** (u web klasiky **záloha dřív + doplatek při příjezdu = dva řádky se svými daty** → měsíční cashflow sedí), jinak spárované bankovní platby; **OTA (Airbnb/Booking)** = odhad net (hrubá − provize) **nahrazený reálnou výplatou** (Airbnb auto z mailu, jinak ručně přes „Reálná výplata" na detailu rezervace → `recordManualPayout`, `ReceiptOrigin::MANUAL`, `manuallyOverridden`). Priorita zdrojů (`IncomeSource`): OTA výplata > zaplacená faktura > bankovní kredit > odhad. UI: editace pohybů/účtů, filtr pohybů (účet/typ/období) + stránkování, **měsíční souhrn** `/ucty/souhrn/{rok}` (`CashflowSummary`), **CSV export** pohybů. Napojení: `InvoiceService::markPaid`, `PaymentIncomeListener` na `PaymentSettledEvent`, `EmailDispatcher` (Airbnb výplata). Ekonomika má blok „Obecné výdaje" (jen provozní kategorie). **Odloženo (chce rozhodnutí):** přepojení `ReservationProfitCalculator` příjmu na zaplacené faktury — Airbnb `ReservationReceipt` je net (provize zvlášť), Ekonomika počítá hrubě → přímé použití by odečetlo provizi dvakrát. **Chybí:** backfill historie z CSV (`sources/backfill_accounts.php`, privátní, negitovat).

## Doporučené pořadí pro v2

1. ~~**Login + security**~~ — ✅ hotové.
2. ~~**Fakturace**~~ — ✅ hotové (mPDF, číselná řada, QR Platba, ČNB EUR→CZK, 5 toků). ARES doplnění firmy z IČO ✅ (check‑in billing). Faktura PDF e‑mailem hostovi ✅ (`GuestMessageSender`, šablona `invoice`).
3. ~~**DPH modul**~~ — ✅ hotové (Booking PDF import, Airbnb manual upload, ČNB kurz, `VatPeriod`, reconciliation).
4. ~~**Zprávy hostům**~~ — ✅ hotové (nastavení e-mailů + barevné téma, editovatelné Markdown šablony s proměnnými, náhled + test, master layout; odeslání zapojeno do `app:actions:run` přes Symfony Mailer; faktura PDF e-mailem hostovi). Plán/stav: `docs/private/plan-zpravy-hostum.md`.
5. ~~**MotoPress REST sync**~~ — ✅ hotové (`app:motopress:sync`).
6. ~~**iCal sync**~~ — ✅ hotové (obousměrný: import obsazenosti z Booking/Airbnb/eChalupy/CS chalupy přes `app:ical:sync`, export `.ics` feedu do OTA extranetů, auto‑storno zmizelých bloků, kontrola dvojího prodeje na dashboardu).
7. ~~**Deploy na sdílený hosting**~~ — ✅ hotové (viz `docs/deploy.md`; cron IMAP poller + MotoPress sync á 15 min).
8. ~~**Ekonomika / evidence příjmů a výdajů**~~ — ✅ hotové (`/ekonomika`, zisk per rezervace, viz výše).
