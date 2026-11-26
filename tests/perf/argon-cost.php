<?php

declare(strict_types=1);

/*
 * Real cost of Argon2id (64 MiB, 3 passes: app.crypto.kdf_*) on the machine: it weighs on every sign-in and every
 * unlocking. Usage: php tests/perf/argon-cost.php <output file>
 */

$runs = [];
for ($i = 0; $i < 5; ++$i) {
    $start = hrtime(true);
    sodium_crypto_pwhash(32, 'mot de passe de mesure', random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES), 3, 67_108_864, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
    $runs[] = (int) ((hrtime(true) - $start) / 1_000_000);
}
sort($runs);
$result = ['operations' => 3, 'memory_mib' => 64, 'median_ms' => $runs[2], 'max_ms' => $runs[4]];
echo sprintf("Argon2id : %d ms (médiane), %d ms (maximum)\n", $result['median_ms'], $result['max_ms']);
if (isset($argv[1])) {
    file_put_contents($argv[1], json_encode($result, JSON_THROW_ON_ERROR));
}
