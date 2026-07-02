# Deploy na sdílený hosting

Runbook nasazení Symfony aplikace (`app/`) na **sdílený hosting** s PHP, MySQL/
MariaDB, IMAP a cronem — bez daemon procesů a bez SSH root práv. Sepsáno podle
reálného nasazení na **hukot.net (tarif WH‑03)**, který slouží jako konkrétní
příklad; postup je přenositelný na podobné sdílené hostingy.

Placeholdery: `<ssh-host>`, `<ssh-user>`, `<home>` (domovský adresář účtu),
`<domena>` (subdoména aplikace), `<DB>` / `<DB_USER>` / `<DB_HESLO>`, `<repo>`
(URL git repozitáře). Konkrétní hodnoty drž jen lokálně (např. `~/.ssh/config`,
`CLAUDE.local.md`), nikdy v repu.

---

## 0. Co se nasazuje

Nasazuje se **jen obsah `app/`** (Symfony). `docker-compose.yml`, `docker/`
(lokální dev) a `sources/` (gitignored) se NEnasazují.

---

## 1. Prostředí (příklad: hukot WH‑03)

Přístup: `ssh <alias>` (doporučeno přes alias v `~/.ssh/config` s vyhrazeným
klíčem). Aplikace běží na samostatném účtu/subdoméně `<domena>`:

| Co | Hodnota (příklad) |
|---|---|
| Home | `<home>` (např. `/srv/www/<domena>/app`) |
| **DocumentRoot** | `~/www` — na hukotu fixní (panel ho nepřepíše) |
| PHP CLI | **8.5.x** (`/usr/bin/php8.5`), memory 512 MB, max_execution 180 s |
| PHP rozšíření | imap, intl, gd, zip, pdo_mysql, mbstring, dom/xml, curl, openssl, gmp, bcmath |
| Composer | 2.9.x |
| DB server | **MariaDB 11.8.x** (pozor: CLI klient `mysql` může hlásit jinou verzi — řiď se serverem) |
| .htaccess | povolené (přepínač „Povolit .htaccess" ON) |
| HTTPS | LetsEncrypt, auto‑prodloužení |

### Omezený shell — lshell (specifikum hukotu)

Některé sdílené hostingy běží v omezeném shellu (hukot používá **lshell**).
Typická omezení, na která narazíš:

- **Interaktivní session** (`ssh <alias>`, pak psát příkazy) — whitelist funguje.
- **Jednorázové `ssh <alias> "příkaz"` je blokované** (porušení se logují).
  Deploy dělat v interaktivní session nebo přes `scp`/`git`.
- **`rsync` nemusí fungovat** (i s čistým klíčem `Permission denied`); **`scp`
  ano**. Seznam povolených příkazů: `help` v session.
- **Může chybět:** `crontab` (cron přes panel — krok 7), `ln` (proto shim místo
  symlinku — krok 2), `ssh-keygen`, `find`/`sed`/`awk`/`curl`, `cat` (použij
  `tail`/`less`).
- Pozor na zakázané znaky: `|`, `>`, `;` atd. lshell odmítne i uvnitř příkazu.

> scp/git volat s čistým ssh configem — `Host *` v `~/.ssh/config` přidává cizí
> klíče → MaxAuthTries. Použij `-F /dev/null -i ~/.ssh/<klíč> -o IdentitiesOnly=yes`.

---

## 2. Nasazení kódu — git clone + shim ve `www/`

Když je DocumentRoot fixně `~/www`, Symfony app je v repu pod `app/`, a `ln`
chybí: app **git clonujeme do `~/src`** a do `~/www` dáváme tenký
front‑controller shim. Vše verzované v `app/deploy/shared-host/`, `~/www` plní
`composer deploy-www`.

```
<home>/                              ← home
├── src/                             ← git clone repa
│   └── app/                         ← Symfony project root (composer install zde, .env.local)
│       └── deploy/shared-host/{www-index.php, htaccess, sync-www.php}
└── www/                             ← DocumentRoot (plní `composer deploy-www`)
    ├── index.php   ← shim → ../src/app/vendor/autoload_runtime.php
    ├── .htaccess   ← Symfony rewrite
    └── assets/ …   ← zrcadlo src/app/public/ (bez index.php/.htaccess)
```

> Shim počítá app jako `dirname(__DIR__).'/src/app'` → **clone musí být `~/src`**.
> Jinde → `DEPLOY_APP_DIR` v `~/www/.htaccess` + `DEPLOY_WWW_DIR` při deploy-www.

**První nasazení** (interaktivní `ssh <alias>`):

```sh
cd <home>
git clone https://<PAT>@github.com/<repo>.git src   # privátní repo → HTTPS + token
cd src/app
composer install --no-dev --optimize-autoloader
# .env.local — krok 5; import DB — krok 3; var/ soubory — krok 4
composer deploy-www                 # naplní ~/www (shim + .htaccess + assets)
```

Po clonu **sundej token z remote** (zůstane v `.git/config`):
`git remote set-url origin https://github.com/<repo>.git` a PAT v GitHubu
zneplatni (při dalším pullu zadáš nový, nebo nastav credential helper).

V panelu ověř, že **„Povolit .htaccess" je ON**. apache-pack netřeba — `.htaccess`
i shim dodává `deploy/shared-host/`.

**Další deploy:**

```sh
cd <home>/src && git pull
cd app && composer install --no-dev --optimize-autoloader
composer deploy-www
php bin/console cache:clear --env=prod
```

> `cache:clear` **není** v composer `auto-scripts` (post-install) — na PHP 8.5 host
> spouští composer warmup v subprocessu s nezapisovatelným temp direm, `tempnam()`
> spadne na `E_NOTICE` (symfony/translation 7.4.x ji nepotlačuje, fix až Symfony 8).
> Ruční `php bin/console cache:clear --env=prod` (čisté CLI, temp dir `/srv/www/tmp`)
> projde i na studené cache → je proto **povinný krok deploye**.

---

## 3. Přenos databáze

Data (rezervace, faktury, DPH, …) jsou jen v lokální dev DB → `mysqldump`.

> ⚠️ **Lokál MySQL 8.4 → server MariaDB 11.8.x.** MySQL 8 collation
> `utf8mb4_0900_ai_ci` MariaDB nezná → v dumpu přepsat na `utf8mb4_unicode_ci`.
> (Default collation prod DB může být `utf8mb3_czech_ci` — nevadí, tabulky si
> nesou vlastní `CHARSET=utf8mb4` per tabulka.)

```sh
# lokálně: dump + oprava collation
docker compose exec db mysqldump -u<DB_USER> -p<DB_HESLO> --single-transaction \
  --default-character-set=utf8mb4 <DB> > dump.sql
sed -i 's/utf8mb4_0900_ai_ci/utf8mb4_unicode_ci/g' dump.sql
```

Import buď přes **phpMyAdmin** (vybrat DB → Importovat → soubor; dump nemá
`CREATE DATABASE`, proto musí být DB předvybraná), nebo `scp` + `mysql` CLI:

```sh
scp -F /dev/null -i ~/.ssh/<klíč> -o IdentitiesOnly=yes \
  dump.sql <ssh-user>@<ssh-host>:<home>/
# na serveru: mysql -u<DB_USER> -p <DB> < <home>/dump.sql
```

> ⚠️ **DB user NENÍ automaticky stejný jako název DB.** Uživatele s heslem se
> zakládá v panelu zvlášť — vezmi přesně to, čím se přihlásíš do phpMyAdmin
> (jinak `Access denied for user … (using password: YES)`). Host v
> `DATABASE_URL` = `localhost`.

Po importu schéma už existuje → `doctrine:migrations:migrate` NEpouštět.
(Čistá DB bez dumpu → naopak migrate.) Testovací DB `_test` nepřenášet.

---

## 4. Přenos runtime souborů (`var/`)

PDF a doklady nejsou v DB. `scp -r` do `~/src/app/var/`:

| Cesta | Obsah |
|---|---|
| `var/invoices/` (booking/, airbnb/) | faktury hostům + OTA měsíční doklady |
| `var/ubyport/receipts/` | doručenky Ubyport |

```sh
scp -r -F /dev/null -i ~/.ssh/<klíč> -o IdentitiesOnly=yes \
  var/invoices var/ubyport \
  <ssh-user>@<ssh-host>:<home>/src/app/var/
```

> Cesty k PDF se v DB ukládají **relativně** (`var/invoices/…`, viz
> `App\Storage\PdfStorage`) → nezávislé na prostředí, `mysqldump` je přenese bez
> přepisu.

---

## 5. Produkční `.env.local`

Založit `~/src/app/.env.local` (gitignored). Vychází z lokálního + prod:

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<nový náhodný secret>
DATABASE_URL="mysql://<DB_USER>:<DB_HESLO>@localhost:3306/<DB>?serverVersion=11.8.6-MariaDB&charset=utf8mb4"
IMAP_HOST=<imap-host>
IMAP_USERNAME=<automatizační schránka>
IMAP_PASSWORD=<heslo schránky>
MOTOPRESS_BASE_URL=<https://vas-web>
MOTOPRESS_CONSUMER_KEY=<…>
MOTOPRESS_CONSUMER_SECRET=<…>
```

Nesecret defaulty (porty, service IDs) jsou v commitnutém `.env`; tajemství a
identity patří **jen** do `.env.local`.

---

## 6. Finalizace na serveru

```sh
cd <home>/src/app
composer dump-env prod                 # .env → .env.local.php
php bin/console cache:clear --env=prod
composer deploy-www                  # po dump-env / změně assetů dorovná ~/www
php bin/console app:user:create <email> --env=prod   # admin login (vyzve skrytě na heslo, min 8)
```

`app:user:create` je upsert podle e‑mailu — slouží i ke **změně hesla**.

---

## 7. Cron

Některé hostingy (vč. hukotu) spouští cron **PHP souborem cestou, bez
argumentů** (`crontab` v lshell není). Proto jsou v repu wrappery
`app/cron/{imap-poll,motopress-sync}.php` (volají `_kernel.php`, který bootne
kernel z `.env` a spustí console command).

V panelu **Cron úlohy** založ tyto úlohy (čtyři základní + tři pro notifikace níže):

| pole | imap | motopress | actions-plan | actions-run |
|---|---|---|---|---|
| Soubor | `/src/app/cron/imap-poll.php` | `/src/app/cron/motopress-sync.php` | `/src/app/cron/actions-plan.php` | `/src/app/cron/actions-run.php` |
| Time / Memory limit | 300 s / 256 MB | 300 s / 256 MB | 300 s / 256 MB | 300 s / 256 MB |
| Minuta | každých 15 min | každých 15 min | každých 15 min | každých 15 min |
| Hodina/Den/Měsíc/Den v týdnu | Každý | Každý | Každý | Každý |

(Cesta je relativní z úrovně `www`, proto `/src/app/...`.)

`actions-plan` doplní automatické akce na časovou osu (pre-arrival/post-stay zprávy,
doplatek, Ubyport u cizinců) i u rezervací potvrzených přes MotoPress sync;
`actions-run` vyhodnotí akce, kterým nadešel čas (v MVP self-resolving připomínky —
odeslání zpráv hostům čeká na roadmap bod „Zprávy hostům").

Pro **e-mailové notifikace ubytovateli** (`/nastaveni/notifikace`) přidej dvě úlohy:

| pole | notifications-dispatch | notifications-daily |
|---|---|---|
| Soubor | `/src/app/cron/notifications-dispatch.php` | `/src/app/cron/notifications-daily.php` |
| Time / Memory limit | 300 s / 256 MB | 300 s / 256 MB |
| Minuta | každých 15 min | `0` |
| Hodina | Každá | `7` (1× denně) |
| Den/Měsíc/Den v týdnu | Každý | Každý |

`notifications-dispatch` rozešle okamžité notifikace z fronty (á 15 min).
`notifications-daily` běží jednou denně a dělá dvě věci: nejdřív `app:vat:remind`
(sám se zkratuje mimo ~20. den, připomínku DPH založí jen za měsíc s přijatou
provizí, idempotentně jednou za období), pak `app:notifications:digest` (sloučí
nasbírané notifikace do denního souhrnu) — takže i DPH připomínka v režimu
„souhrn" odejde týž den.

**Ověření běhu:** hukot píše výstup do `~/_log/cron/<soubor>.php.log`:

```sh
tail ~/_log/cron/imap-poll.php.log
tail ~/_log/cron/motopress-sync.php.log
```

Zdravý výstup: `Found N message(s) … [OK] Done. processed=…` resp.
`[OK] Sync hotov — total N, novych …`.

---

## 8. Smoke checklist

- [ ] `https://<domena>` → login, přihlášení adminem
- [ ] dashboard zobrazí rezervace, `/dph` sedí s lokálem
- [ ] detail rezervace → existující PDF faktury se otevřou (var/invoices přenesené, cesty relativní)
- [ ] `php bin/console app:imap:poll --dry-run --env=prod` projde (IMAP creds)
- [ ] `~/_log/cron/*.log` po čtvrthodině obsahují úspěšné běhy

---

## Zbývá

- **Odesílání e-mailů** — mailer není nakonfigurovaný (chybí `symfony/mailer` +
  `MAILER_DSN`). Faktury/zprávy hostům se negenerují k odeslání.
- **iCal sync** — kontrola obsazenosti / Airbnb+Booking cancellations.
  ⚠️ Otevřená otázka: OTA iCal nejspíš storno **nevrací explicitně** — zrušení =
  blok zmizí z feedu. Detekce by tedy musela jet diffem (dřív viděný blok ve
  feedu chybí) proti naší DB. Před stavbou ověřit reálné chování feedů.
