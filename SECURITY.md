# Bezpečnostní politika

## Hlášení zranitelností

Bezpečnostní zranitelnosti hlas **neveřejně** — ne přes veřejné GitHub issues.

- E-mail: **vzoha@volny.cz** (do předmětu uveď „Ubytovadlo security").
- Nebo přes GitHub **Security advisories** (Security → Report a vulnerability).

Uveď prosím: popis problému, kroky k reprodukci, dotčenou verzi/commit a možný
dopad. Na hlášení se snažím reagovat do **7 dnů**.

Prosím, nezneužívej nalezenou zranitelnost a nezkoumej ji proti cizím provozním
instancím — jen proti vlastnímu lokálnímu nasazení.

## Rozsah

Ubytovadlo zpracovává osobní a fakturační údaje hostů (jména, adresy, doklady
cizinců pro Ubyport). Citlivá místa, kde si dávat pozor:

- autentizace a session (login, oprávnění),
- nahrávání souborů a sken dokladů (check-in, MRZ),
- generování faktur a přístup k PDF (`var/invoices/`),
- IMAP/MotoPress integrace a zacházení s tajemstvími (`.env.local`).

## Co není zranitelnost

- Chybějící konfigurace v `app/.env` (šablona) — tajemství patří do `.env.local`.
- Problémy ve WordPressu/MotoPressu nebo jiných třetích stranách — hlas u nich.
