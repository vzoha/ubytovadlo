---
name: ubytovadlo-revize
description: Ověření, že nový kód v Ubytovadle splňuje clean code, SOLID a DRY — mechanické metriky přes PHPMD na změněných souborech plus checklist pro to, co stroj neumí. Použij po dopsání funkce a před commitem, nebo když se ptáš „je tenhle kód v pořádku".
---

# Revize nového kódu

Dvě vrstvy. Nejdřív stroj (levné, deterministické), pak posouzení (drahé, ale umí záměr).

## 1. Mechanická vrstva

```
tools/review-changed.sh            # oproti origin/main
tools/review-changed.sh HEAD~1     # jen poslední commit
docker compose exec app composer check   # cs:check + PHPStan L6 + testy
```

`review-changed.sh` pustí **PHPMD** (`app/phpmd.xml`) jen na soubory v `app/src`, které se v téhle větvi změnily — je to branka pro nový kód, ne audit historie. Hlídá délku a složitost metod, NPath, počet parametrů, `else` větve, mrtvý kód, vazby mezi třídami.

Kde běží sama:

- **pre-commit hook** — `tools/review-changed.sh --staged` nad tím, co je připravené k commitu; nález commit zastaví,
- **CI** (job „Clean code") — proti bázi větve nebo poslednímu commitu.

Nálezy, které v repu byly dřív, drží **`app/phpmd.baseline.xml`** (soubor + pravidlo + metoda), takže projde jen to, co přibylo. Po úklidu starého dluhu baseline zúžíš:

```
docker compose exec app sh -c 'php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd src text phpmd.xml --update-baseline --baseline-file phpmd.baseline.xml'
```

Nález, který je vědomé rozhodnutí (dev seeder, generovaný mapper), umlč cíleně u té třídy nebo metody, ne globálně:

```php
/** @SuppressWarnings(PHPMD.ExcessiveClassComplexity) */
```

Prahy jsou v `app/phpmd.xml`. Když se stejný nález opakuje u celé kategorie tříd (typicky Doctrine entity nebo Symfony formuláře), je to signál upravit ruleset, ne posypat kód anotacemi.

## 2. Posuzovací vrstva

Tohle PHPMD ani PHPStan nezachytí — projdi diff (`git diff origin/main`) a ptej se:

**SRP a altitude**
- Dělá třída jednu věc? Umíš ji popsat jednou větou bez „a"?
- Sedí úroveň abstrakce uvnitř metody? (míchání „spočítej DPH" s „naformátuj řetězec" je smell)
- Je logika ve službě, ne v kontroleru? Kontroler validuje vstup, deleguje, vrátí odpověď.

**DRY — duplicita *záměru*, ne řádků**
- Existuje už metoda, která tohle umí? V tomhle repu se to opakovaně stalo u parsování částek a datumů (`EmailText`, `Money`), u CSRF (`ChecksCsrf`) a u převodu ISO kódů zemí. **Než napíšeš pomocnou funkci, zagrepuj, jestli tu není.**
- Tři výskyty stejného vzoru = extrahovat. Dva = zvážit.
- Pozor na opačný extrém: sdílená abstrakce mezi dvěma věcmi, které se náhodou podobají, je horší než duplicita.

**SOLID**
- **O** — přibývá `match`/`switch` podle typu kanálu nebo toku? Zvaž strategii/enum s chováním (`Channel::isOta()` je vzor).
- **D** — závisí to na rozhraní a injektovaných službách? `new DateTimeImmutable()` uvnitř logiky patří přes `ClockInterface`, jinak to nejde testovat.
- **I** — nenutíš implementaci znát metody, které nepoužije?

**Pojmenování**
- Název říká *co*, ne *jak*. Doménové pojmy česky jen tam, kde jsou doménové; identifikátory anglicky.
- Bool metody `is/has/can`. Žádné `data`, `info`, `manager`, `helper`, `process()`.

**Testovatelnost**
- Jde ta třída otestovat bez DB a bez sítě? Pokud ne, závislosti nejsou na správném místě.
- Pokrývají testy hraniční stavy, idempotenci a prázdné vstupy — nebo jen happy path?

## 3. Co s nálezem

Revize není zápis do protokolu — nález se **rovnou opraví**, ještě než jde funkce do changelogu a commitu. Podle závažnosti:

| Závažnost | Co to je | Co udělat |
|---|---|---|
| **Závažná** | špatná částka, DPH, datum nebo stav rezervace; ztráta dat; únik tajemství či PII; pád na běžném vstupu | oprav hned a **doplň test**, který ten stav chytá |
| **Střední** | porušené SRP, duplicitní záměr, logika v kontroleru, netestovatelná závislost (`new DateTimeImmutable()` místo `ClockInterface`), matoucí název | oprav hned; když by oprava sáhla mimo rozsah funkce, dodělej ji jako samostatný krok a **řekni to majiteli** |
| **Drobná** | kosmetika, věc vkusu, doporučení nad rámec zadání | jen zmínit v odpovědi, neopravovat |

Pravidla, ať se z toho nestane nekonečná smyčka:

- Po opravách **pusť revizi znovu** — hotovo je, až je čistá, nebo zbude jen vědomě umlčený nález (`@SuppressWarnings` u té metody s odůvodněním).
- **Cizí rozdělaný kód neopravuj.** Revize platí na to, co k funkci patří; nález v souboru, kterého se změna netýká, jen ohlas.
- Oprava nesmí měnit chování, které pokrývají zelené testy. Když ho mění, je to změna funkce — patří do changelogu, ne do úklidu.

## 4. Když je toho hodně

Na větší diff pusť `/code-review --fix` (najde i chyby, nejen kvalitu, a opraví je) nebo `/simplify` (jen kvalita, rovnou opraví). Doménová kritéria k nim doplň z tohohle checklistu — samy o projektových zvyklostech nevědí.

Skilly `clean-code`, `php-best-practices` a `php-pro` v repu drží obecná pravidla jazyka; tenhle soubor jen to, co je specifické pro Ubytovadlo.
