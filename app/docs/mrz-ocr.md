# MRZ OCR — co je hotové a jak testovat

Čtení MRZ z fotek dokladů (občanky / pasy) pro automatický check-in. Cesta
**obrázek → MRZ text** běží v prohlížeči (tesseract.js, GDPR + sdílený hosting
bez binárek), cesta **MRZ text → pole** je čistě v PHP (`App\Mrz\MrzParser`).

## Co bylo uděláno (stručně)

- **Parser `MrzParser`** (`app/src/Mrz/MrzParser.php`):
  - formáty TD3 (pas 2×44), TD2 (2×36), TD1 (ID 3×30) + **francouzské CNI 1994**
    (`IDFRA`, non-ICAO; `parseFrenchFromRaw` kotví řádek 2 na checksum-validní
    12místné číslo)
  - **confidence** = počet platných ICAO check-digitů + kvalita jména
  - **date-repair** (`repairCheckDigit`) — opraví 1 chybnou číslici v datu přes
    vizuálně záměnné znaky + kalendářní validaci (jen data, ne čísla dokladu)
  - **cleanMrzLine** — bere nejdelší souvislý MRZ blok na řádku (zahodí
    mezerou-oddělený OCR šum z okraje karty)
  - **parseMany(texts[])** — hlasování per pole napříč variantami (víc OCR čtení
    téhož dokladu → modální hodnota); použité i v produkci
- **Browser OCR** (`app/templates/checkin/host.html.twig`): Canvas
  předzpracování (deskew-less, band detekce, **lokální adaptivní práh** +
  **unsharp**), 3 varianty (band-adaptive, band-otsu, full), PSM6, raw texty →
  POST `/checkin/{token}/mrz` → `parseMany`.
- **Test harness** (`app/src/Command/MrzTestCommand.php`): OCR + parser nad
  korpusem fotek, per-field accuracy proti ground-truth. Varianty viz níže.
- **Korpus** `app/var/mrz-corpus/` (gitignored, lokální): 22 dokladů +
  `ground-truth.json`. Postaven z originálů přes `mrz-corpus-build.sh`.

Aktuální stav: **16/22 vše přesně** (18/22 „as-good-as-MRZ" — gaebler_volkmar
a eich_bartholome_fr mají jen křestní jméno zkrácené v MRZ, nedostupné).
Faily: 2× zkrácené jméno (limit MRZ), 1× FR line-split (arsene), 3× glare
(geppl_johanna, kravanja, sulzer_christian).

## Jak spustit test CELÉ sady

Předpoklad: `docker compose up -d` (app + db běží).

### Paralelně (rychlé, ~4 min) — DOPORUČENO

```bash
cd /path/to/repo
rm -rf /tmp/par && mkdir -p /tmp/par
ls app/var/mrz-corpus/*.jpg | xargs -n1 basename > /tmp/par/files.txt
cat /tmp/par/files.txt | xargs -P6 -I{} sh -c \
  'docker compose exec -T app bin/console app:mrz:test var/mrz-corpus --only "{}" > /tmp/par/"{}".txt 2>&1'
```

Agregace (POZOR: multibyte „✓"/„—" v grepu zlobí — počítej přes per-file
verdikt „Vše správně: 1 / 1"):

```bash
cd /tmp/par; allok=0; fails=""
for f in $(cat files.txt); do
  if grep -qF "správně: 1 / 1" "$f.txt"; then allok=$((allok+1)); else fails="$fails ${f%.jpg}"; fi
done
echo "ALL-CORRECT: $allok / 22"; echo "FAILS:$fails"
```

Detail jednoho dokladu (per-field řádek + varianta):

```bash
grep -E "NAZEV.jpg |Vše správně" /tmp/par/NAZEV.jpg.txt
```

### Sekvenčně (celá tabulka najednou, pomalé ~10–20 min na tomto hostu)

```bash
docker compose exec -T app bin/console app:mrz:test var/mrz-corpus
```

Volby: `--only <soubor.jpg>` (1 doklad), `--verbose-raw` (raw OCR text všech
variant), `--keep-preview` (ponechá předzpracované `.preview-*.png` vedle vstupu).

### Verbose pro ladění jednoho dokladu

```bash
docker compose exec -T app bin/console app:mrz:test var/mrz-corpus \
  --only sulzer_christian.jpg --verbose-raw 2>&1 | less
```

## Pozor / pasti (z minula)

- **Nikdy nespouštěj dva běhy `app:mrz:test` na stejný korpus zároveň** — sdílí
  `/tmp/mrz_*` cesty (i když mají PID v názvu, host bývá přetížený). Když test
  „regreduje" na ~9–11/22, je to skoro jistě **nedoběhlý paralelní běh** nebo
  **chybná agregace přes multibyte grep**, ne reálná regrese — přepočítej přes
  „Vše správně: 1 / 1" až po dokončení všech souborů.
- Ověř dokončení: `for f in $(cat files.txt); do grep -qF "Vše správně" "$f.txt" || echo "INCOMPLETE: $f"; done`
- `TaskStop` na host příkaz **nezabije** in-container `php` proces — když je
  potřeba čistý stav, `docker compose restart app`.

## Unit testy parseru (bez DB, rychlé)

```bash
docker compose exec -T app vendor/bin/phpunit tests/Mrz/MrzParserTest.php
```

(Plná suite `vendor/bin/phpunit` má ~50 errorů z funkčních testů bez namigrované
test-DB — to je pre-existing infra, netýká se MRZ.)

## Browser pipeline harness (měří produkční frontend cestu)

`app:mrz:test` měří **kontejnerovou** cestu (ImageMagick + tesseract CLI, deskew,
rotační fallback, 6 variant). Produkce ale po migraci běží **v prohlížeči**
(Canvas + tesseract.js, 3 passy, bez deskew/rotace) — tu kontejnerový harness
NEtestuje. Pro měření reálné produkční cesty slouží dev-only harness:

- Controller `App\Controller\DevMrzBrowserTestController` (`#[When('dev')]`):
  - `GET /dev/mrz-browser-test` — stránka, co pustí **přesně** produkční
    preprocessing + OCR (kód kopírovaný 1:1 z `checkin/host.html.twig`) nad
    korpusem a porovná s `ground-truth.json`. Query: `?only=a.jpg,b.jpg`,
    `?debug=1` (raw čtení do `window.__MRZ_RAW`).
  - `GET /dev/mrz-corpus-image/{file}` — servíruje korpusové fotky.
  - `POST /dev/mrz-parse` — `parseMany` bez tokenu (jen dev).
- V `security.yaml` je dev-gated `^/dev/mrz` PUBLIC_ACCESS (v produkci route
  neexistuje, je neškodné).
- Spuštění: otevřít `http://localhost:8000/dev/mrz-browser-test`, počkat
  (~2-3 min/fotka headless), číst `window.__MRZ_SUMMARY` / tabulku. **Drž JS
  v harnessu v synchronu s `host.html.twig`.**

### Naměřeno (květen 2026)

| Pipeline | Vše přesně |
|---|---|
| Kontejner (`app:mrz:test`) | **16/22** |
| Browser produkce (3 passy + parseMany) | **~11/22** |

Migrace na frontend OCR stála ~5 dokladů. Rozdíl drží **deskew + nativní
tesseract + spolehlivá detekce jednoho těsného pásma** v kontejneru, NE množství
variant.

### Co bylo vyzkoušeno a ZAHOZENO (net-negativní)

- **Profile-based band detekce** (port density-profilu z kontejneru) — na
  reálných fotkách chytá tištěný obsah karty / guilloche okraj místo MRZ; horší
  než původní transition detektor.
- **Multi-crop hlasování** (spodní frakce 14/20/32 % + full binarizace) — opraví
  pár těžkých případů (hoedl), ale **katastrofálně rozbije** dříve funkční:
  šum ve jménné zóně (`BVLATUSEK`, `LSAPEK`, `IROMMLER`) přebije per-field
  hlasování (full korpus spadl na ~3/22). Cropy na různých pozicích → jména se
  rozejdou. **Nepoužívat.**

### Co ZŮSTALO (bezpečné)

- **Confidence-vážené hlasování** v `MrzParser::voteFields` (`weightedModal`) —
  čistý high-confidence read přebije šum místo naivního počítání hlasů. Ověřeno:
  kontejner drží 16/22, browser ~11/22 (beze změny), unit + VAT testy zelené.
  Je to základ, kdyby se rescue cropy někdy revidovaly s lepší detekcí.

### Detekce pásma — locate-then-recrop (NASAZENO, květen 2026)

Browser zvednut na **14/22** (z 11) a hlavně prolomena **zeď jmen** (leading šum
`BVLATUSEK`/`LSAPEK`/`BBBPISSEN`). Řešení v `checkin/host.html.twig`:

1. **Locate**: OCR zmenšeného celého obrázku (≤2000 px) s `{blocks:true}` →
   tesseract vrátí bboxy řádků. MRZ řádky se poznají podle **'<' fillerů**
   (`isAnchor`, ≥2 '<') a **číselného řádku** (`isNumericMrz`, ≥45 % číslic);
   `bottomCluster` vezme spodní souvislý stack 2-3 řádků.
2. **Recrop**: těsný vysoký-res ořez **jen** těch řádků (`recropBlock`,
   konsensuální medián x-rozsahu vyřadí i stray glyfy nalevo, např. vedoucí "I").
3. **Re-OCR**: 3 čtení téhož těsného ořezu → `parseMany`: adaptive + otsu
   binarizace + **grey** (jen sharpen, BEZ binarizace). Grey drží slabé tahy a
   tvary číslic, které práh sežere — na čistém ořezu čte nejlíp (`SAPEK` místo
   `PAPEK`/`DAPEK`, správné datum nar.) a jeho checksum-solid hlas vyhraje;
   na šumném ořezu skóruje nízko a prohraje. Tím opraven sapek_ryszard (13→14).
4. **Gate + fallback**: přijme se jen checksum-solid read (confidence ≥ 30, vrací
   `checkin_mrz` endpoint); jinak spadne na původní 3-pass (band + full raw).
   Tím se zachová chování pro dříve procházející fotky — nulová regrese-by-design.

Proč to funguje: detekci řádků tesseract umí dobře, a **těsný ořez odstraní
bleed-in** obsahu nad MRZ, který jinak připisoval stray glyfy k příjmení.
Ověřeno end-to-end v reálném produkčním formuláři (latusek → LATUSEK /
CZESLAW STANISLAW / CFP812085 / 1965-05-18 / POL — vše ✓).

**Zbývající faily (8/22):** glare (geppl_johanna, sulzer_christian),
MRZ-truncated jméno (gaebler_volkmar, eich_bartholome_fr — limit MRZ),
holo pruh nad MRZ (naether — locate chytne holografický proužek jako „řádek 1"
a skutečná IDD řádka se ztratí; po de-rotaci čte grey řádky 2+3 čistě, ale
confidence zůstává 25 < gate), malé/multi-doc MRZ (hoedl, sulzer_tamara,
orlowski_mathilde — locate nenajde/splete stack). Další zlepšení:
v locate (zkusit 90/270° když 0° nenajde blok) by chytlo naether; lepší locate
na malých MRZ (vyšší res / bottom-region) by chytlo hoedl. Náčrt rotace +
doc-number konsensu je v historii harnessu.

#### Co bylo vyzkoušeno a ZAHOZENO

- **Density-profile detekce pásma** (port z kontejneru) — na reálných fotkách
  chytá tištěný obsah karty / guilloche okraj místo MRZ. Locate-then-recrop
  (tesseractovy bboxy) je spolehlivější.
- **Multi-crop hlasování** (spodní frakce + full, hlasování přes cropy) —
  **katastrofa** (~3/22): cropy na různých pozicích → jména se rozejdou a
  per-field hlasování se zaplevelí šumem. Klíč byl OCR-ovat **jen jeden těsný
  ořez** (locate), ne hlasovat přes mnoho.

Confidence-vážené `voteFields` v `MrzParser` (níže) zůstává — chrání vote uvnitř
těsného ořezu (2 binarizace) proti šumu.

## Live kamera scanner (check-in formulář)

Vedle „Vyfotit/Nahrát" má `checkin/host.html.twig` tlačítko **📹 Skenovat
kamerou (živě)**. Otevře zadní kameru (`getUserMedia`, facingMode environment),
zobrazí vodítko „umísti MRZ sem" a v `requestAnimationFrame` smyčce hodnotí každý
frame **levnými pixelovými metrikami** (bez OCR) na 320px grey kopii:

- jas (`mean<55` → „moc tmavé"),
- glare (`>6 %` skoro bílých → „odlesk – nakloň doklad"),
- ostrost (varianca Laplaceova operátoru, `<90` → „rozmazané – přibliž"),
- stabilita (frame-to-frame rozdíl jasu ≤2 → „drž klidně").

Když je frame **ostrý + světlý + stabilní** 2 ticky po sobě, spustí se na
plný-res frame **stejný locate-then-recrop OCR** jako u fotky a checksum-solid
read (confidence ≥ `LOCATE_ACCEPT`) **auto-cvakne**: zastaví kameru, uloží
snímek do náhledu a předvyplní formulář. Metriky jen rozhodují *kdy* zkusit OCR
— skutečným arbitrem je confidence gate, takže prahy nemusí být přesné (volnější
práh = zkouší častěji). Manuální **„📸 Cvaknout teď"** obejde gating, **„Zavřít
kameru"** vypne. Bez kamery (insecure context / desktop bez webkamery) se
tlačítko samo skryje, file fallback zůstává.

Ověřeno end-to-end (fake stream z korpusové fotky → auto-cvak → LATUSEK /
CZESLAW STANISLAW / 1965-05-18 / CFP812085 předvyplněno).

## Přestavba korpusu z originálů (jednorázové)

Zdroj: lokální adresář s originály dokladů (mimo repo, gitignored). Skript
`app/var/mrz-corpus-build.sh` (gitignored) auto-orientuje, ořezává multi-doc
fotky na jednotlivé doklady (PDF = Ubyport doručenky bez MRZ → vyřazeny).
`ground-truth.json` je ruční (hodnoty dle MRZ, MRZ je autoritativní).

**Pozor — naether_julia.jpg má bogus EXIF orientaci** (RightTop / Orientation 6
na *už vzpřímených* pixelech). `-auto-orient` ho proto otočí na bok a celá
pipeline (canvas `drawImage` v Chrome EXIF poslechne) vidí MRZ svisle. Správně je
**jen strhnout EXIF, nerotovat**: `mogrify -strip naether_julia.jpg` (zůstane
4000×3000, orient Undefined). Build skript auto-orientuje plošně, takže po
přestavbě korpusu je potřeba u tohoto souboru orientaci ručně vrátit strip-only.
