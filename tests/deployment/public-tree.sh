#!/bin/sh
set -eu
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
touch "$work/VERSION"
printf '%s\n' 'https://Service.Internal.Test/' > "$work/.example"
CI_SERVER_HOST=gitlab.internal.test sh ci/prepare-public-tree.sh "$work"
grep -Fxq 'https://Service.example.org/' "$work/.example"
printf '\000Internal.Test' > "$work/fixture"
if CI_SERVER_HOST=gitlab.internal.test sh ci/prepare-public-tree.sh "$work"; then
    echo 'Expected the binary hostname to block publication.' >&2
    exit 1
fi
