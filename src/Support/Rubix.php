<?php

declare(strict_types=1);

namespace Beziworld\Ml\Support;

use Closure;
use Composer\InstalledVersions;
use Throwable;

/**
 * Helpers around Rubix ML runtime behaviour.
 */
final class Rubix
{
    /**
     * Run a callback while Rubix performance warnings are silenced.
     *
     * Rubix raises E_USER_WARNING from several constructors when the optional Tensor C
     * extension is missing. Laravel turns warnings into ErrorException, which would make
     * those estimators unusable, so only that specific notice is swallowed.
     *
     * @template T
     *
     * @param Closure(): T $callback
     * @return T
     */
    public static function quietly(Closure $callback): mixed
    {
        $previous = null;

        $previous = set_error_handler(static function (int $level, string $message, string $file = '', int $line = 0) use (&$previous): bool {
            if ($level === E_USER_WARNING && str_contains($message, 'Tensor')) {
                return true;
            }

            return is_callable($previous) ? (bool) $previous($level, $message, $file, $line) : false;
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }

    public static function version(): string
    {
        return self::installed('rubix/ml');
    }

    public static function packageVersion(): string
    {
        return self::installed('beziworld/laravel-ml');
    }

    private static function installed(string $package): string
    {
        try {
            return InstalledVersions::isInstalled($package)
                ? (InstalledVersions::getPrettyVersion($package) ?? 'unknown')
                : 'unknown';
        } catch (Throwable) {
            return 'unknown';
        }
    }
}
