## Co a proč

<!-- Stručně co PR mění a jaký problém řeší. Odkaz na issue: Closes #… -->

## Checklist

- [ ] `vendor/bin/phpunit` prochází
- [ ] `vendor/bin/phpstan analyse` bez nových chyb
- [ ] `vendor/bin/php-cs-fixer fix --dry-run --diff` čisté
- [ ] Změny schématu jen přes **Doctrine migrace** (žádné ad-hoc SQL)
- [ ] Žádné osobní údaje ani tajemství v diffu (placeholdery + `.env.local`)
- [ ] Dokumentace/README aktualizované, pokud je potřeba
