# Changelog

Všechny podstatné změny v tomto projektu se zaznamenávají sem.
Formát vychází z [Keep a Changelog](https://keepachangelog.com/cs/1.1.0/),
verzování dle [SemVer](https://semver.org/lang/cs/).

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

[0.2.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.2.0
[0.1.1]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.1
[0.1.0]: https://github.com/vzoha/ubytovadlo/releases/tag/v0.1.0
