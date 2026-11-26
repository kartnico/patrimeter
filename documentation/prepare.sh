#!/bin/sh
# Prepares the two twin sites of the documentation, fr/ and en/: takes into them the repository's pages that the
# documentation publishes as they are (installation, backup and operations procedures run by the CI, CHANGELOG,
# SECURITY), generates the API reference and copies the favicon. Nothing is copied by hand: these files remain the
# only source. Usage: sh documentation/prepare.sh (then Zensical, see build.sh).
set -eu
cd "$(dirname "$0")/.."

# Technical pages of Patrimeter's development, without an English version: the English site links to them in the
# French site. Any other page of docs/ has its translation docs/<page>.en.md (DocumentationTranslationTest).
french_only='architecture auth-flows choix-techniques ci data-model developpement'
french_site="$(sed -n 's/^site_url = "\(.*\)"$/\1/p' documentation/zensical.toml)"

for lang in fr en; do
    target="documentation/$lang"
    rm -rf "$target/exploitation"
    mkdir -p "$target/exploitation" "$target/assets"
    # Configuration files (nginx, php-fpm, systemd, backup scripts), shared by both languages: the pages' relative
    # links stay valid.
    for directory in docs/*/; do
        cp -R "$directory" "$target/exploitation/"
    done
    cp .env.example "$target/"
    # Favicon: the application's (public/ remains the only source).
    cp public/favicon.svg "$target/assets/favicon.svg"
done

# French: docs/<page>.md, CHANGELOG.md, SECURITY.md.
for page in docs/*.md; do
    case "$page" in
        *.en.md) ;;
        *) cp "$page" documentation/fr/exploitation/ ;;
    esac
done
cp docs/openapi.yaml documentation/fr/exploitation/
cp CHANGELOG.md SECURITY.md documentation/fr/

# English: docs/<page>.en.md, published at the same path as the French page (the language selector stays on the same
# page), CHANGELOG.en.md and SECURITY.en.md.
for page in docs/*.en.md; do
    cp "$page" "documentation/en/exploitation/$(basename "$page" .en.md).md"
done
cp docs/openapi.en.yaml documentation/en/exploitation/openapi.yaml
cp CHANGELOG.en.md documentation/en/CHANGELOG.md
cp SECURITY.en.md documentation/en/SECURITY.md

english="documentation/en/exploitation/*.md documentation/en/CHANGELOG.md documentation/en/SECURITY.md"
french="documentation/fr/CHANGELOG.md documentation/fr/SECURITY.md"
# shellcheck disable=SC2086 # lists of files, split on purpose.
{
    # Links between English pages: X.en.md (or .en.yaml) in the repository, X.md in the site.
    sed -i 's#\(]([^)]*\)\.en\.\(md\|yaml\)\([)\#]\)#\1.\2\3#g' $english
    # In the repository, CHANGELOG and SECURITY point to docs/; in the site, these pages are under exploitation/.
    sed -i 's#](docs/#](exploitation/#g' $french documentation/en/CHANGELOG.md documentation/en/SECURITY.md
    # Pages that only exist in French: link to the French site (address of the page, anchor kept).
    for name in $french_only; do
        sed -i "s#](\(\.\./\)\{0,1\}\(exploitation/\)\{0,1\}$name\.md#]($french_site""exploitation/$name/#g" $english
    done
}

# API reference, generated from the OpenAPI description of each language (also copied, to download).
python3 documentation/openapi_reference.py fr docs/openapi.yaml documentation/fr/exploitation/api-reference.md
python3 documentation/openapi_reference.py en docs/openapi.en.yaml documentation/en/exploitation/api-reference.md
echo 'Pages reprises dans documentation/fr et documentation/en : exploitation/ (avec api-reference.md), CHANGELOG.md, SECURITY.md, assets/favicon.svg'
