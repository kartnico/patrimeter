<?php

declare(strict_types=1);

namespace Patrimeter\Application;

/** Missing resource, or one belonging to another user: the two cases cannot be told apart. */
final class NotFound extends \RuntimeException {}
