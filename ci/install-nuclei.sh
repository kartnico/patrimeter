#!/bin/sh
# Installs Nuclei (ProjectDiscovery, MIT license; jobs "nuclei" and "nuclei-production", docs/ci.md) from its
# official release, in a pinned version with a verified checksum. CI tool only, never in production.
# Usage: sh ci/install-nuclei.sh
set -eu

version=3.11.1
sha256=ea63d4ae232808cd7c6bc00d0142428e231fab59dae01042246097d195835ab6
archive="/tmp/nuclei_${version}_linux_amd64.zip"

php -r '
$context = stream_context_create(["http" => ["follow_location" => 1, "timeout" => 120]]);
$data = file_get_contents($argv[1], false, $context);
if ($data === false || file_put_contents($argv[2], $data) === false) {
    exit(1);
}
' "https://github.com/projectdiscovery/nuclei/releases/download/v$version/nuclei_${version}_linux_amd64.zip" "$archive"
echo "$sha256  $archive" | sha256sum -c -
unzip -o -q "$archive" nuclei -d /usr/local/bin
chmod 0755 /usr/local/bin/nuclei
nuclei -version 2>&1 | tail -n 1
