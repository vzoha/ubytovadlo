#!/bin/sh
#
# Ubytovadlo — PostToolUse hook: srovná styl právě editovaného PHP souboru.
# Drží `composer cs:check` a pre-commit hook zelené, aby se styl neopravoval
# zpětně hromadným commitem. Selhání je vždy tiché (exit 0) — hook nesmí
# zablokovat práci, mechanickou kontrolu stejně dělá CI.

set -u

project_dir="${CLAUDE_PROJECT_DIR:-$(pwd)}"

path=$(cat | python3 -c \
    "import sys,json; print(json.load(sys.stdin).get('tool_input',{}).get('file_path',''))" \
    2>/dev/null) || exit 0

case "$path" in
    *.php) ;;
    *) exit 0 ;;
esac

# Jen soubory uvnitř app/ — php-cs-fixer má konfiguraci tam.
case "$path" in
    "$project_dir"/app/*) rel=${path#"$project_dir"/app/} ;;
    app/*) rel=${path#app/} ;;
    *) exit 0 ;;
esac

cd "$project_dir" 2>/dev/null || exit 0

# Kontejner neběží → nech to na pre-commit hooku a CI.
docker compose ps --services --filter status=running 2>/dev/null | grep -qx app || exit 0

docker compose exec -T -e PHP_CS_FIXER_IGNORE_ENV=1 app \
    vendor/bin/php-cs-fixer fix "$rel" --quiet >/dev/null 2>&1

exit 0
