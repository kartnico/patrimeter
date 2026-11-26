#!/bin/sh
# Operate only on the disposable archive, never the runner's checkout. Use the same examples in the image and Pages.
set -eu
: "${CI_SERVER_HOST:?}"
case "$CI_SERVER_HOST" in *[!A-Za-z0-9.-]*|.*|*.) exit 1;; esac
domain=${CI_SERVER_HOST#*.}
case "$domain" in *.*) ;; *) echo 'Expected a project-specific GitLab domain.' >&2; exit 1;; esac
cd "$1"
test -f VERSION && test ! -e .git
pattern=$(printf '%s' "$domain" | sed 's/\./\\./g')
find . -type f -exec sh -ec '
    pattern=$1; shift
    for file do
        if grep -Iqi "$pattern" "$file"; then
            sed -i "s/$pattern/example.org/Ig" "$file"
        fi
    done
' sh "$pattern" {} +
# Treat a remaining hostname in binary content as a release blocker rather than rewriting binary files.
if grep -aril -- "$pattern" .; then exit 1; else test "$?" = 1; fi
