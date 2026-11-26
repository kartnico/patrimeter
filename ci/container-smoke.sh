#!/bin/sh
# Smoke test of the container image ("container-smoke" job): the container, started by GitLab as a service with its
# "web" entry point, must serve the sign-in page with its security headers, answer the monitoring (database
# reachable) and keep its internal files out of reach.
#
# Usage: sh ci/container-smoke.sh http://patrimeter:8080
set -eu

base="$1"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

fail() {
    echo "container-smoke : $1" >&2
    exit 1
}

# Start: migrations, cache, then nginx and php-fpm (3 minutes at most). A new container has no account yet: the sign-in
# page leads to the installation wizard (-L follows it), which carries the same headers.
for _ in $(seq 1 90); do
    if curl -fsSL -o "$work/login.html" -D "$work/login.headers" "$base/connexion" 2> /dev/null; then
        break
    fi
    sleep 2
done
[ -s "$work/login.html" ] || fail "la page de connexion ne répond pas."
grep -q "Patrimeter" "$work/login.html" || fail "la page de connexion ne contient pas « Patrimeter »."
grep -qi "^content-security-policy:.*default-src 'none'" "$work/login.headers" || fail "en-tête Content-Security-Policy absent."
grep -qi "^x-content-type-options: nosniff" "$work/login.headers" || fail "en-tête X-Content-Type-Options absent."
echo "Page de connexion servie, en-têtes de sécurité présents."

# Monitoring: the database answers (backup and scheduled tasks have not run yet: not required here).
curl -sS -o "$work/health.json" "$base/sante" || fail "/sante ne répond pas."
grep -q '"database:responseTime":\[{"status":"pass"' "$work/health.json" || fail "base injoignable : $(cat "$work/health.json")"
echo "Supervision : base joignable."

# Compiled assets served by nginx, internal files refused.
asset="$(grep -o '/assets/styles/app-[^"]*\.css' "$work/login.html" | head -n 1)"
[ -n "$asset" ] || fail "feuille de style compilée absente de la page."
curl -fsS -o /dev/null "$base$asset" || fail "feuille de style $asset non servie."
for path in /.env /composer.json /var/patrimeter.sqlite /index.php/../.env; do
    status="$(curl -s -o /dev/null -w '%{http_code}' "$base$path")"
    case "$status" in
        2*) fail "$path est servi (HTTP $status)." ;;
    esac
done
echo "Assets servis, fichiers internes refusés. Image en état de marche."
