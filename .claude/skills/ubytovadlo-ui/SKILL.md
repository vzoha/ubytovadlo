---
name: ubytovadlo-ui
description: Závazný vizuální jazyk Ubytovadla — barvy tlačítek podle kategorie akce, signály +/✎/→/↗/←, modaly, nadpisy, formulářová pole, copy. Použij před každou prací na Twig šablonách, formulářích, dashboardu nebo layoutu a při kontrole hotového UI.
---

# UI

Stack: Twig + Bootstrap 5. Referenční vzor stránky: `app/templates/account/index.html.twig`.
Plný manuál: **`docs/design-system.md`** — při větším zásahu do UI si ho přečti celý, tohle je provozní výtah.

**Cíl:** netechnický majitel musí z každého prvku poznat, co udělá — *„otevře se okno a měním data"* vs *„přejdu jinam"*. Sedí to i s přístupností: navigace je `<a>`, akce je `<button>`.

## Barva = kategorie akce

| Barva | Třída | Význam |
|---|---|---|
| Modrá plná | `btn-primary` | hlavní akce sekce (mění data), `Uložit` |
| Modrá rámeček | `btn-outline-primary` | vedlejší akce / otevře modal |
| Šedá | `btn-outline-secondary` | neutrální utility, filtry, segmentové přepínače, roky |
| Červená | `btn-outline-danger` | mazání |
| Zelená | `btn-outline-success` | pozitivní potvrzení stavu (`Zaplaceno`, `Hotovo`) |

Aktivní segmentový přepínač nese `.active`, **ne** modrou.

## Ikona = kam to vede

- `+` = otevře okno pro **nový** záznam · `✎` = otevře okno pro **úpravu**
- `→` = navigace na jinou stránku, **jen na textovém odkazu** (`text-decoration-none small`), nikdy na orámovaném tlačítku, nikdy spolu s `+`/`✎`
- `↗` = externí odkaz / nový tab · `←` = zpět, textový odkaz **nad nadpisem**, pojmenovaný podle cíle (`← Účty`)
- Výjimka: pager (`Starší →`) a wizard (`Pokračovat →`) smí mít šipku na tlačítku

## Stránka a modaly

- Nadpis stránky `h1.h3`, sekce `h2.h5`.
- Zpět nad nadpisem; stránky přímo z hlavního menu zpět **nemají**.
- Krátký formulář (1 entita, pár polí) = modal, footer vždy `Zrušit` (`btn-outline-secondary`) + `Uložit` (`btn-primary`). Dlouhý / vícesekční = samostatná stránka s dvojicí `Uložit` + `Zrušit`.
- Vstup do detailu z tabulky: textový odkaz `Detail →` v posledním sloupci; husté finanční tabulky smí mít klikací buňku.

## Formulářová pole

Preferuj **nativní HTML5** před vlastní JS komponentou: `type="number"` (má spinner), `date`/`time`, `email`/`tel`/`url`, `datalist`, `<details>`/`<summary>`, `required`, `min`/`max`/`step`, `pattern`. Míň kódu, validace a přístupnost zdarma. Vlastní widget až když nativní opravdu nestačí.

## Copy

- Aktivní sloveso podle toho, co se stane: `Uložit`, ne `Odeslat`. Název akce drž stejný v celém toku.
- Popisy, nápovědy i changelog piš **k aktuálnímu stavu, ne ke změně oproti minulosti**. Žádné „místo X", „už není potřeba", „nově", „nahrazuje" — uživatel vidí jen současný stav.
- Konzistenci drž **napříč stránkami**, ne po jedné. Než přidáš nový vzor, najdi, jak to řeší existující stránka.

## Mobil

Cílová šířka **360 px**. Tabulka vždy v `.table-responsive` (mimo PDF/e-mail šablony), přebytečné sloupce `d-none d-md-table-cell`, tabulka nad ~5 sloupců `.table-stack` + `data-label` na buňkách (pod `md` karty, vzor `reservation/list.html.twig`), datum a částka `text-nowrap`, toolbar `d-flex flex-wrap gap-2`, formulářová mřížka `col-md-*`. Žádný vodorovný posuv stránky, samostatné tlačítko aspoň 38 px (`btn-sm` v tabulce je OK), text ne pod 12 px (`badge` výjimka, ale ne pod `.small`).

## Prázdné stavy

Řádek s hláškou v prázdné tabulce nese `class="table-empty"` — hlavička sloupců se skryje, ať nad hláškou nevisí prázdné sloupce.

## Kontrola hotového UI

Sáhl jsi na šablonu? Projdi to očima, ne jen kódem:

1. `docker compose up -d`, přihlas se jako `admin@example.com` / `heslo123` (po `app:dev:seed-demo`).
2. Otevři dotčenou stránku přes chrome-devtools MCP a udělej screenshot — ve výchozí i úzké šířce (`resize_page` 360 px).
3. Zkontroluj proti tabulkám výše: barva odpovídá kategorii? Šipka jen na odkazu? Nadpisy `h3`/`h5`? Zpět nad nadpisem?
4. Projdi prázdný a chybový stav, ne jen naplněný.

Drift proti manuálu se v tomhle repu už jednou opravoval hromadně zpětně — levnější je zkontrolovat to hned.
