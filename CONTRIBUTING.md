# Jak přispět

Díky za zájem o **Ubytovadlo**. Projekt vznikl z reálné potřeby provozu malého
ubytování a vyvíjí se dál — issue, návrhy i pull requesty jsou vítané.

## Komunikace

Probíhá v **češtině**. V kódu drž identifikátory anglicky; česky jen tam, kde je
to přirozené (doménové pojmy, šablony zpráv hostům).

## Lokální vývoj (Docker)

Žádný PHP/Composer/MySQL na hostu — vše běží v kontejnerech.

```bash
docker compose up -d                                                 # app na http://localhost:8000
docker compose exec app bin/console doctrine:migrations:migrate      # schéma
docker compose exec app bin/console app:dev:import-fixtures          # demo data
docker compose exec app vendor/bin/phpunit                           # testy
```

Composer: `docker compose run --rm app composer …`.
Konzole: `docker compose exec app bin/console …`.

Konfigurace: zkopíruj potřebné řádky z `app/.env.example` do `app/.env.local`
(necommitovaný) a vyplň. **Tajemství a osobní/fakturační údaje patří jen do
`.env.local`**, nikdy do committovaných `.env*`.

## Než pošleš PR

Lokálně musí projít to samé, co kontroluje CI:

```bash
docker compose exec app composer check     # code style + statická analýza + testy
tools/review-changed.sh                    # clean code na změněných souborech
```

`composer check` spojuje php-cs-fixer, PHPStan (level 6) a PHPUnit; jednotlivě
jdou pustit jako `composer cs:check`, `composer stan` a `composer test`.
`composer cs` (bez `:check`) styl rovnou opraví.

`tools/review-changed.sh` pustí **PHPMD** (`app/phpmd.xml`) jen na soubory
v `app/src`, které se v tvé větvi změnily — hlídá délku a složitost metod, počet
parametrů, mrtvý kód a vazby mezi třídami. Nález, který je vědomé rozhodnutí,
umlč cíleně u té metody nebo třídy anotací `@SuppressWarnings(PHPMD.NazevPravidla)`.

Testy v CI běží proti **MySQL 8.4 i MariaDB 11.8** — vývoj probíhá na MySQL,
produkce na MariaDB. Do migrací proto nepiš collation (`utf8mb4_0900_ai_ci`
MariaDB nezná), funkční indexy ani nativní JSON funkce.

## Pravidla

- **Změny DB schématu výhradně přes Doctrine migrace** — žádné `schema:update`
  ani ad-hoc SQL. Produkce se aktualizuje `doctrine:migrations:migrate`.
- **Nová logika patří do `src/`**, ne do WordPressu/MotoPressu — WP je jen jeden
  ze vstupů.
- **Jednorázové backfilly dat NEcommituj** (CSV importy, ruční SQL nad
  historickými daty). Audit-trail smí zůstat v `sources/` (gitignored).
- **Žádné osobní údaje** v commitech, fixtures ani testech — používej placeholder
  jména (Novák, Svobodová, …) a `example.com`.
- Drž se existujících vzorů (Symfony 7 + Doctrine, Twig + Bootstrap, mPDF).
  Nové závislosti pro jednoduché věci nepřidávej.

## Issues

- **Bug** — kroky k reprodukci, očekávané vs. skutečné chování, verze.
- **Návrh** — popiš problém/potřebu, ne jen řešení.
- **Bezpečnostní zranitelnosti hlas neveřejně** — viz [`SECURITY.md`](SECURITY.md).

## Licence příspěvků

Odesláním příspěvku souhlasíš, že bude licencován pod
[Functional Source License 1.1 (ALv2 Future License)](LICENSE.md) — stejně jako
zbytek projektu.
