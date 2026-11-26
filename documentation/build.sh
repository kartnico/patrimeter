#!/bin/sh
# Builds the documentation into documentation/site/: the two twin sites (fr/ and en/, Zensical in strict mode: a
# broken link or anchor makes the build fail), then the root (racine/) that opens the browser's language and
# redirects the old addresses. Usage: sh documentation/build.sh; ZENSICAL names the command (by default
# "zensical", installed by pip install zensical).
set -eu
cd "$(dirname "$0")"
zensical="${ZENSICAL:-zensical}"

sh prepare.sh
rm -rf site
# shellcheck disable=SC2086 # the command can have several words (python3 -m zensical).
$zensical build --clean --strict
# shellcheck disable=SC2086
$zensical build --clean --strict --config-file zensical.en.toml
cp racine/index.html racine/langue.js site/
# GitHub Pages does not process GitLab's _redirects. Keep existing bookmarks working with static pages.
python3 - site <<'PYREDIRECT'
from pathlib import Path
import os
import sys
from html import escape
site = Path(sys.argv[1])
for page in (site / 'fr').rglob('index.html'):
    path = page.relative_to(site / 'fr')
    if path.parts[0] not in {'demarrage-rapide', 'utilisation', 'exploitation', 'CHANGELOG', 'SECURITY'}:
        continue
    dest = site / path
    dest.parent.mkdir(parents=True, exist_ok=True)
    url = escape(os.path.relpath(page.parent, dest.parent) + '/', quote=True)
    dest.write_text(f'<!doctype html><html lang="fr"><title>Patrimeter</title>'
                    f'<meta http-equiv="refresh" content="0; url={url}"><a href="{url}">Documentation</a></html>')
PYREDIRECT
touch site/.nojekyll
echo 'Documentation construite : documentation/site/ (fr/, en/ et racine)'
