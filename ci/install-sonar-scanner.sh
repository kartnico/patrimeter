#!/bin/sh
# Installs SonarScanner CLI (SonarQube analysis, job "sonarqube") from SonarSource's binaries, in a pinned version
# with a verified checksum. The linux-x64 archive brings its own Java: nothing else to install.
# Usage: sh ci/install-sonar-scanner.sh (then: sonar-scanner)
set -eu

version=8.4.0.16527
sha256=2a28f118e2e9375dfd36eb27b803e1d37f93daba4db068aca6eef9853041bb24
archive="/tmp/sonar-scanner-cli-$version-linux-x64.zip"

php -r '
$context = stream_context_create(["http" => ["follow_location" => 1, "timeout" => 300]]);
$data = file_get_contents($argv[1], false, $context);
if ($data === false || file_put_contents($argv[2], $data) === false) {
    exit(1);
}
' "https://binaries.sonarsource.com/Distribution/sonar-scanner-cli/sonar-scanner-cli-$version-linux-x64.zip" "$archive"
echo "$sha256  $archive" | sha256sum -c -
unzip -q "$archive" -d /opt
ln -sf "/opt/sonar-scanner-$version-linux-x64/bin/sonar-scanner" /usr/local/bin/sonar-scanner
sonar-scanner --version
