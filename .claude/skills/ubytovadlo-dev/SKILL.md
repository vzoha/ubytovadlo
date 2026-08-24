---
name: ubytovadlo-dev
description: Lokální provoz Ubytovadla — docker compose, bin/console, composer check, testy, demo data, dev přihlášení, testovací DB. Použij vždy, když v tomhle repu spouštíš příkaz, test nebo samotnou aplikaci.
---

# Lokální provoz

Na hostu **není** PHP, Composer ani MySQL. Všechno běží v kontejnerech (`docker-compose.yml` v rootu repa).

| Co | Příkaz |
|---|---|
| Nastartovat | `docker compose up -d` |
| Aplikace | http://localhost:8000 (built-in server, root `app/public`) |
| Console | `docker compose exec app bin/console <cmd>` |
| Composer | `docker compose run --rm app composer <cmd>` |
| Logy | `docker compose logs -f app` |
| DB shell | `docker compose exec db mysql -uubytovadlo -pubytovadlo ubytovadlo` |

Kontejner `app` = PHP 8.4 CLI (`docker/php/Dockerfile`), `db` = MySQL 8.4. Composer install proběhne sám při startu, když chybí `vendor/`.

## Kontroly před commitem

```
docker compose exec app composer check      # cs:check + phpstan L6 + phpunit
docker compose exec app composer cs         # oprav styl
docker compose exec app composer test       # jen testy
docker compose exec app vendor/bin/phpunit --filter NazevTestu
```

CI (`.github/workflows/ci.yml`) tohle zrcadlí — co projde lokálně, projde i tam. Výjimka: CI navíc pouští testy proti MariaDB (viz skill `ubytovadlo-migrace`).

Git hooky (jednorázově po clonu): `git config core.hooksPath .githooks`. pre-commit = guard na privátní soubory + `cs:check`, pre-push = phpstan + phpunit. Obejití `--no-verify`.

## Testovací databáze

`config/packages/doctrine.yaml` přidává v test prostředí suffix `_test` → DB se jmenuje **`ubytovadlo_test`**. Když je rozbitá nebo chybí schéma:

```
docker compose exec app bin/console --env=test doctrine:database:create --if-not-exists
docker compose exec app bin/console --env=test doctrine:migrations:migrate --no-interaction
```

Funkční testy si DB **nevytvoří samy** — po nové migraci ji vždy zmigruj, jinak padají na chybějícím sloupci.

## Demo data a dev přihlášení

```
docker compose exec app bin/console app:dev:seed-demo        # pestrá neutrální demo data (screenshoty, prezentace)
docker compose exec app bin/console app:dev:import-fixtures  # přehraje vzorové .eml přes dispatcher
docker compose exec app bin/console app:user:create          # vlastní uživatel
```

Dev login po seedu: **admin@example.com / heslo123** (výchozí, neměnit — je zadrátovaný v seedu i v návodech). Demo data musí zůstat **neutrální** — žádné reálné PII.

## Úlohy, které v produkci pouští cron

Lokálně se spouští ručně stejným příkazem:

```
app:imap:poll [--all] [--dry-run]   app:motopress:sync    app:ical:sync
app:actions:plan                    app:actions:run       app:occupancy:check
app:vat:recalculate                 app:vat:reconcile     app:vat:remind
app:notifications:dispatch          app:notifications:digest
```

Plný seznam: `docker compose exec app bin/console list app`.
