<?php

declare(strict_types=1);

namespace Patrimeter\Domain\BankStatement;

/** A file from which no closing balance can be read; the message (in French, a translation key) says what to do. */
final class UnreadableBankStatement extends \RuntimeException {}
