# Ubytovadlo — automatizace rezervací

Automatizace rezervací, fakturace a evidence pro malé ubytování. Cíl: *jedna rezervace = jediný vstup*, ze kterého se vygeneruje faktura, řádek v evidenci i plán zpráv hostům — bez placených SaaS.

Ubytování se prodává třemi kanály (vlastní web přes WordPress + MotoPress, Booking.com, Airbnb) a obsazenost se drží přes iCal. Protože OTA neposílají údaje hosta ani cenu, ruční zadání nezmizí — zužujeme ho na **jediný formulář** a zbytek automatizujeme.

## Komunikace a jazyk

- S uživatelem mluv **česky**.
- V kódu: **identifikátory anglicky**, česky jen doménové pojmy a texty pro uživatele (šablony zpráv hostům, UI).
- **Popisy a texty v aplikaci** (UI, nápovědy, changelog) piš, jako by předchozí verze neexistovala — popiš, co věc *je* a *dělá*. Žádné „místo X", „už není potřeba", „nově", „nahrazuje". Changelog smí uvést fakt („přesunuto z env do UI"), ne hodnotící rámování.

## Stack

PHP 8 / **Symfony 7** + Doctrine ORM + MySQL · Twig + Bootstrap 5 · **mPDF** pro faktury · Symfony Mailer + `webklex/php-imap` pro příchozí poštu · Symfony Scheduler/Messenger z **cronu** · **ARES API** pro firemní údaje z IČO · **ČNB API** pro kurzy.

Lokálně **Docker** (`docker-compose.yml`), na hostu není PHP ani MySQL. Produkce = **sdílený hosting** vedle WordPressu, vlastní subdoména a DB, žádné daemon procesy.

Repo: `https://github.com/vzoha/ubytovadlo` (public, FSL).

## Tvrdá pravidla

- **Schéma DB jen přes Doctrine migrace.** Žádné `schema:update`, žádné ad-hoc SQL na produkci.
- **Žádný daemon** — cron + krátké idempotentní commandy.
- **Bez placených SaaS** (fakturace ani SMTP). Faktury generujeme sami.
- **Všechny rezervace v naší DB**, bez ohledu na zdroj. MotoPress je jen jeden ze vstupů, ne autorita; žádná primární logika ve WP pluginu.
- **Tajemství jen v `app/.env.local`**, nikdy v kódu ani v `.env`. Konfigurace a přístupy patří přednostně do nastavení v DB/UI (šifrovaný credential store) — míříme na SaaS, kde `.env` není.
- **Žádné reálné PII** v kódu, testech ani fixtures.
- **Owner UX první** — funkce se dělá jako UI pro netechnického majitele, CLI je doplněk.
- **Jednorázové backfilly dat necommitovat** — pustí se lokálně, na produkci dojdou v `mysqldump`.

## Kde co je

| Potřebuješ | Kam |
|---|---|
| spustit appku, testy, seed, dev login | skill `ubytovadlo-dev` |
| sáhnout na entitu / schéma | skill `ubytovadlo-migrace` |
| rezervace, faktury, DPH, kanály, parsery | skill `ubytovadlo-domena` |
| přidat funkci, commitnout, releasnout, nasadit | skill `ubytovadlo-feature` |
| ověřit nový kód proti clean code / SOLID / DRY | skill `ubytovadlo-revize` |
| Twig šablony, formuláře, dashboard, layout | skill `ubytovadlo-ui` + [`docs/design-system.md`](docs/design-system.md) |
| co už appka umí a co je další na řadě | [`docs/stav-projektu.md`](docs/stav-projektu.md) |
| runbook nasazení | [`docs/deploy.md`](docs/deploy.md) |
| identita instance, přístupy, provozní detaily | `@CLAUDE.local.md` (gitignored) |

Skilly se načtou samy, když na dané téma sáhneš; když si nejsi jistý, načti je explicitně.
