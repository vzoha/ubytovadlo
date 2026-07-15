# Design manuál (UI)

Krátká, závazná pravidla pro konzistentní interní UI. Stack: Twig + Bootstrap 5.
Referenční vzor: [`app/templates/account/index.html.twig`](../app/templates/account/index.html.twig).

**Cíl:** netechnický majitel musí z každého prvku poznat, co udělá — *„otevře se okno a měním data"* vs *„přejdu na jinou stránku"*. Sedí to i s přístupností: navigace je `<a>`, akce je `<button>`.

## Barva tlačítka = kategorie akce

| Barva | Třída | Význam | Příklad |
|---|---|---|---|
| Modrá plná | `btn-primary` | hlavní akce sekce (mění data) | `+ Výdaj`, `Uložit` |
| Modrá rámeček | `btn-outline-primary` | vedlejší akce / otevře modal | `+ Příjem`, `✎ Upravit` |
| Šedá | `btn-outline-secondary` | neutrální utility + segmentové přepínače | `Filtrovat`, `CSV`, filtry, roky |
| Červená | `btn-outline-danger` | mazání | `×` |
| Zelená | `btn-outline-success` / `btn-success` | pozitivní potvrzení stavu | `Zaplaceno`, `Hotovo` |

Segmentové přepínače (filtry stavů, výběr roku) jsou **šedé**; aktivní prvek nese `.active`, ne modrou.

## Ikona / šipka = kam to vede

- `+` prefix = tlačítko otevře okno pro **nový** záznam.
- `✎` prefix = tlačítko otevře okno pro **úpravu**.
- `→` suffix = **navigace** na jinou stránku — **jen na textovém odkazu** (`text-decoration-none small`), **nikdy** na orámovaném tlačítku a **nikdy v kombinaci s `+`/`✎`**.
- `↗` = externí odkaz / nový tab.
- `←` = zpět: textový odkaz **nad nadpisem**, pojmenovaný podle cíle (`← Účty`, `← Rezervace`).
- Výjimka: pager (`Starší →`) a krokový wizard (`Pokračovat →`) smí mít šipku i na tlačítku — je to směrový idiom.

## Modaly

- Krátký formulář (1 entita, pár polí) = modal; dlouhý / vícesekční = samostatná stránka.
- Footer vždy: `Zrušit` (`btn-outline-secondary`) + `Uložit`/`Naplánovat`/… (`btn-primary`).

## Stránka

- Nadpis stránky `h1.h3`, nadpis sekce `h2.h5`.
- Zpět nad nadpisem (viz `←`); stránky dostupné přímo z hlavního menu zpět **nemají**.
- Podstránkový formulář nese dvojici `Uložit` + `Zrušit`.
- Vstup do detailu z tabulky: textový odkaz `Detail →` v posledním sloupci. Husté finanční tabulky smí místo toho mít klikací buňku (datum/jméno).

## Texty (copy)

- Aktivní sloveso podle toho, co se stane: `Uložit`, ne `Odeslat`; název akce drž stejný v celém toku.
- Popisy piš k aktuálnímu stavu, ne ke změně oproti minulosti (viz `CLAUDE.md`).
