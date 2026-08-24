---
name: ubytovadlo-feature
description: Definition of done pro novou funkci v Ubytovadle — testy, demo fixtures, revize, mechanické kontroly, changelog, co nesmí do veřejného repa, release a deploy. Použij, když v tomhle repu přidáváš nebo měníš funkci a před commitem.
---

# Cesta nové funkce

Repo je **veřejné OSS** (FSL). Každá funkce projde tímhle, ať se nic nerozbije a neunikne tajemství.

## 1. Definition of done

- [ ] Kód v `app/`, schéma **jen** přes Doctrine migrace (skill `ubytovadlo-migrace`)
- [ ] **Rozumné testy** — unit i funkční, ne jen happy path: hraniční stavy, idempotence, prázdné vstupy
- [ ] **Demo fixtures aktualizované** (`app:dev:seed-demo`, `app:dev:import-fixtures`), ať demo i screenshoty ukazují novou funkci na **neutrálních datech** (žádné reálné PII)
- [ ] **UI podle design manuálu** (skill `ubytovadlo-ui`), pokud se sáhlo na šablony
- [ ] **`docker compose exec app composer check` zelené** (cs:check + PHPStan L6 + PHPUnit)
- [ ] **Revize kódu s opravou** — skill `ubytovadlo-revize`: pusť `tools/review-changed.sh` a projdi checklist, **závažné a střední nálezy oprav rovnou** (drobné jen zmiň), pak revizi i testy zopakuj. Na větší diff `/code-review --fix`. Revize patří **před** changelog — do changelogu jde až hotový tvar funkce.
- [ ] Záznam do `CHANGELOG.md`, sekce `[Unreleased]`
- [ ] `git status` přečtený — vím, co přidávám

## 2. Co NEsmí do repa

Tajemství (hesla, klíče, IMAP/MotoPress creds) žijí **jen v `app/.env.local`** — nikdy v kódu ani v `.env`. Runtime konfigurace a přístupy patří přednostně do **nastavení v DB/UI** (šifrovaný credential store), ne do env; míříme na SaaS, kde `.env` není k dispozici.

Mimo repo (gitignored): `/sources/` (vzorky e-mailů, CSV, backfilly), `/docs/private/` (runbooky, plány), `/CLAUDE.local.md`, `app/public/assets/logo.png` (značka instance), `config/secrets/prod`, `/www/`.

Per-instance věci řeš přes env/soubor s **graceful fallbackem**, ne natvrdo. Žádné reálné PII v kódu, testech ani fixtures — demo jména neutrální.

pre-commit hook tohle hlídá (privátní soubory, tajemství, code style, PHPMD nad stagovaným kódem), ale spoléhat se na něj nestačí — posuzovací vrstvu revize stroj neudělá.

## 3. Release

SemVer, anotované tagy `vX.Y.Z`. Do `CHANGELOG.md` sekce verze (Keep a Changelog, česky: Přidáno / Změněno / Opraveno + odkaz na GitHub release). Tag → push → GitHub release. Drobné funkce se smí kupit do příští verze — netagovat každý commit.

## 4. Deploy

Sdílený hosting, runbook `docs/deploy.md` (per-instance detaily `docs/private/`):

```
git pull
composer install --no-dev --optimize-autoloader
composer deploy-www
bin/console doctrine:migrations:migrate --env=prod
```

Nová funkce s cronem → doplnit cron úlohu (deploy.md §7). Po deployi projít smoke checklist (deploy.md §8).

## Priority při návrhu

- **Owner UX první.** Funkce se dělá primárně jako UI pro netechnického majitele; CLI command je doplněk, ne hlavní rozhraní.
- **Bez placených SaaS** — faktury i maily si obsluhujeme sami.
- **Žádný daemon** — cron + krátké idempotentní commandy.
- **Všechny rezervace v naší DB**, bez ohledu na zdroj. Evidence žije tady.
- **MotoPress je jen jeden ze vstupů** — žádná primární logika ve WP pluginu.
