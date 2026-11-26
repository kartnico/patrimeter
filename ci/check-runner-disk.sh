#!/bin/sh
# Stops the pipeline at the first job if the runner's disk is almost full. Otherwise, the jobs fail much later and
# with no apparent link: images impossible to download, truncated artifacts, one-hour timeouts exceeded (ZFS becomes
# very slow near its quota).
#
# Usage: sh ci/check-runner-disk.sh [free GB required, 5 by default]
set -eu

required_gb="${1:-5}"
free_kb=$(df -Pk . | tail -n 1 | tr -s ' ' | cut -d ' ' -f 4)
free_gb=$((free_kb / 1024 / 1024))

if [ "$free_gb" -lt "$required_gb" ]; then
    echo "Disque du runner presque plein : $free_gb Go libres, $required_gb Go exigés." >&2
    echo "Agrandir le disque ou purger les images Docker du runner (docs/ci.md, « Prérequis côté runner »)." >&2
    exit 1
fi

echo "Disque du runner : $free_gb Go libres."
