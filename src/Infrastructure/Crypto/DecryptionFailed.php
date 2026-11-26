<?php

declare(strict_types=1);

namespace Patrimeter\Infrastructure\Crypto;

/** Ciphertext altered, moved or encrypted with another key. */
final class DecryptionFailed extends \UnexpectedValueException {}
