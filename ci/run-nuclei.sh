#!/bin/sh
# Nuclei scan of a running Patrimeter (jobs "nuclei" and "nuclei-production", docs/ci.md): the HTTP templates of the
# day (CVEs, misconfigurations, exposed files, technologies), from "low" severity up, without the intrusive ones
# (denial of service, fuzzing, intrusive, OSINT), rate limited. JSON Lines report, an empty file if nothing is found;
# a summary per severity on the output.
# Usage: sh ci/run-nuclei.sh <address> <report.jsonl> [requests per second] [severities that fail, e.g. high,critical]
set -eu

target="$1"
report="$2"
rate="${3:-50}"
fail_on="${4:-}"
templates="${NUCLEI_TEMPLATES_DIR:-/tmp/nuclei-templates}"

mkdir -p "$(dirname "$report")"
# Templates of the day: the latest known flaws (the "code" templates, which run commands, stay disabled by default).
nuclei -update-templates -update-template-dir "$templates" -silent
: > "$report"
nuclei -target "$target" -templates "$templates/http" -disable-update-check -silent -no-color \
    -severity low,medium,high,critical -exclude-tags dos,fuzz,intrusive,osint \
    -rate-limit "$rate" -concurrency 10 -timeout 10 -retries 1 \
    -jsonl -output "$report"

php -r '
$counts = ["critical" => 0, "high" => 0, "medium" => 0, "low" => 0];
$failing = array_filter(explode(",", $argv[2]));
$failed = 0;
foreach (file($argv[1], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $finding = json_decode($line, true);
    $severity = is_array($finding) ? (string) ($finding["info"]["severity"] ?? "") : "";
    if (isset($counts[$severity])) {
        ++$counts[$severity];
        echo "[$severity] ", $finding["template-id"] ?? "?", " : ", $finding["matched-at"] ?? "?", PHP_EOL;
        $failed += in_array($severity, $failing, true) ? 1 : 0;
    }
}
printf("Nuclei : %d critical, %d high, %d medium, %d low.%s", ...[...array_values($counts), PHP_EOL]);
exit($failed > 0 ? 1 : 0);
' "$report" "$fail_on"
