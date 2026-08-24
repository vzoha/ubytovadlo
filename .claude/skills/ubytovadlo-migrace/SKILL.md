---
name: ubytovadlo-migrace
description: Pravidla pro změny DB schématu v Ubytovadle — Doctrine migrace, číslování verzí a kolize, kompatibilita dev MySQL 8.4 vs produkční MariaDB, backfill dat. Použij při zásahu do entit, psaní migrace nebo ladění rozdílu schématu.
---

# Změny schématu

**Produkce běží na sdíleném hostingu a aktualizuje se `doctrine:migrations:migrate`.** Z toho plyne jediné tvrdé pravidlo:

> Schéma se mění **výhradně přes Doctrine migrace**. Žádné `doctrine:schema:update`, žádné ad-hoc SQL na produkci.

## Postup

```
# 1) uprav entitu v app/src/Entity/
docker compose exec app bin/console make:migration
# 2) PŘEČTI vygenerovaný soubor — Doctrine často navrhne i nesouvisející diff
docker compose exec app bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app bin/console --env=test doctrine:migrations:migrate --no-interaction
docker compose exec app composer test
```

Migraci na test DB pusť vždy — jinak funkční testy padají na chybějícím sloupci.

## Číslování a kolize

Soubory jsou `VersionYYYYMMDDHHMMSS.php` a Doctrine je řadí **lexikograficky**. Ručně psané migrace v repu mají zaokrouhlený čas (`…120000`), takže při souběžné práci na dvou větvích snadno vznikne verze, která je **starší než už nasazená** — pak se na produkci nikdy nespustí, nebo se spustí v jiném pořadí než lokálně.

Před vytvořením migrace:

```
ls app/migrations | tail -3
```

Nová verze musí být striktně vyšší než poslední existující. Když není (rebase, merge cizí větve), soubor **přejmenuj** — třída, název souboru i případný odkaz musí sedět — a ověř `bin/console doctrine:migrations:status`.

## Embeddable bez migrace

Rozpad `Reservation` na value objecty (`Address`, `GuestContact`, `BillingIdentity`, `ElectricityUsage`, …) se dělá jako Doctrine `#[Embeddable]` s `columnPrefix: false` a explicitními `name:` u sloupců, takže **negeneruje migraci** — schéma zůstává beze změny. Po refaktoru ověř `make:migration` → musí hlásit „No changes detected".

## Kompatibilita: dev MySQL 8.4 → produkce MariaDB

Lokál i jeden CI job běží na **MySQL 8.4**, produkce na **MariaDB 11.8.x**. Druhý CI job jede na MariaDB právě proto, aby se rozdíl chytil v PR, ne až při deployi. Když test projde na MySQL a padne na MariaDB, je to tohle:

- **Collation** — MySQL 8 default `utf8mb4_0900_ai_ci` MariaDB nezná. V migracích collation **nevypisuj**, nech default z `charset=utf8mb4`. (V `mysqldump` pro produkci se přepisuje na `utf8mb4_unicode_ci` — viz `docs/deploy.md`.)
- **JSON** — MariaDB má `JSON` jako alias pro `LONGTEXT`; nespoléhej na nativní JSON funkce ani na `JSON_TABLE`.
- **Funkční / výrazové indexy**, `CHECK` s funkcemi, `ALTER … ALGORITHM=INSTANT` — syntaxe se liší, nepoužívej.
- **Délky indexů** — u `VARCHAR(255)` v utf8mb4 hlídej limit prefixu u složených unique indexů.

Když si nejsi jistý konstrukcí, napiš ji v čistém SQL, které umí obě DB.

## Backfilly dat

Jednorázové importy CSV a ruční SQL nad historickými rezervacemi **nejsou schema change** — do `src/` ani do migrací nepatří. Pusť je jednou lokálně proti dev DB; na produkci se přenesou v `mysqldump` při deployi. Audit-trail skriptů smí zůstat v `sources/` (gitignored).
