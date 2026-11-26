#!/bin/sh
# Fails if the compilation of Symfony's container, in debug mode, reports an unjustified deprecation (method that
# will have to change signature at the next major version, removed option…). PHPUnit does not see them: they come
# from the debug class loader, active only here.
#
# Justified deprecation, left aside: SqlOnlySchemaDiffProvider implements an internal interface of Doctrine
# Migrations, the only way to turn off the automatic schema comparison (docs/architecture.md).
#
# Usage: sh tools/container-deprecations.sh [php]
set -eu

php="${1:-php}"
output="$($php bin/console debug:container --deprecations --env=dev --no-ansi)"
echo "$output"

unexpected="$(echo "$output" | grep -E '^ \* [0-9]+x: ' | grep -vF 'Doctrine\Migrations\Provider\SchemaDiffProvider' || true)"
if [ -n "$unexpected" ]; then
    echo "container-deprecations : dépréciation à corriger :" >&2
    echo "$unexpected" >&2
    exit 1
fi
