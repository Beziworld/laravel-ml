<?php

declare(strict_types=1);

namespace Beziworld\Ml\Exceptions;

use RuntimeException;

/**
 * Base exception of the package; every other package exception extends it.
 */
class MlException extends RuntimeException {}
