#!/bin/sh
# Installs k6 (load test) from its official release, in a pinned version with a verified checksum.
# Usage: sh ci/install-k6.sh
set -eu

version=2.3.0
sha256=39c3117b6af817592dcd0ce4242105c0a7af10948c2a425306f0be8f7a8a8ab1
archive="/tmp/k6-v$version-linux-amd64.tar.gz"

php -r '
$context = stream_context_create(["http" => ["follow_location" => 1, "timeout" => 120]]);
$data = file_get_contents($argv[1], false, $context);
if ($data === false || file_put_contents($argv[2], $data) === false) {
    exit(1);
}
' "https://github.com/grafana/k6/releases/download/v$version/k6-v$version-linux-amd64.tar.gz" "$archive"
echo "$sha256  $archive" | sha256sum -c -
tar -xzf "$archive" -C /usr/local/bin --strip-components=1 "k6-v$version-linux-amd64/k6"
k6 version
