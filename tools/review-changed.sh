#!/bin/sh
#
# Ubytovadlo — mechanická revize ZMĚNĚNÝCH PHP souborů.
#
# Měřitelné signály clean code / SOLID (délka a složitost metod, počet
# parametrů, mrtvý kód, vazby mezi třídami) pouští přes PHPMD jen na tom, co
# se v téhle větvi změnilo. Je to branka pro nový kód, ne audit historie.
#
#   tools/review-changed.sh [base]     # výchozí base: origin/main, jinak main
#   tools/review-changed.sh --staged   # jen stagované soubory (pouští pre-commit hook)
#
# Co PHPMD neumí — pojmenování, skutečné SRP, duplicitní *záměr* napříč soubory —
# dělá druhá vrstva revize, viz skill `ubytovadlo-revize`.

set -eu

cd "$(dirname "$0")/.."

base=${1:-}

# --staged: branka pro pre-commit hook — revidují se soubory připravené k commitu.
if [ "$base" = "--staged" ]; then
    files=$(git diff --cached --name-only --diff-filter=ACMR -- 'app/src/**/*.php' 'app/src/*.php')
    if [ -z "$files" ]; then
        echo "✓ Ve stagovaných souborech není nic z app/src — není co revidovat."
        exit 0
    fi
    base=""
fi

if [ -z "${files:-}" ] && [ -z "$base" ]; then
    if git rev-parse --verify --quiet origin/main >/dev/null; then
        base=origin/main
    elif git rev-parse --verify --quiet main >/dev/null; then
        base=main
    else
        base=HEAD~1
    fi
fi

if [ -z "${files:-}" ]; then
    merge_base=$(git merge-base "$base" HEAD 2>/dev/null || echo "$base")
    files=$(git diff --name-only --diff-filter=ACMR "$merge_base" -- 'app/src/**/*.php' 'app/src/*.php')
fi

if [ -z "$files" ]; then
    echo "✓ Žádné změněné soubory v app/src oproti $base — není co revidovat."
    exit 0
fi

# PHPMD chce cesty relativní ke svému pracovnímu adresáři (app/).
list=$(printf '%s\n' "$files" | sed 's|^app/||' | tr '\n' ',' | sed 's/,$//')

echo "Revize ${base:-stagovaných souborů}:"
printf '%s\n' "$files" | sed 's/^/  /'
echo

# PHPMD 2.x hlásí vlastní deprecations na PHP 8.4 — potlačíme, ať je vidět nález.
# Baseline drží nálezy, které v repu byly před zavedením branky — hlásí se jen to nové.
phpmd_cmd='php -d error_reporting="E_ALL & ~E_DEPRECATED" vendor/bin/phpmd'
phpmd_args='text phpmd.xml --baseline-file phpmd.baseline.xml'

if [ -f /.dockerenv ] || [ -n "${CI:-}" ]; then
    cd app
    # shellcheck disable=SC2086
    eval $phpmd_cmd "'$list'" $phpmd_args
else
    docker compose exec -T app sh -c "$phpmd_cmd '$list' $phpmd_args"
fi
