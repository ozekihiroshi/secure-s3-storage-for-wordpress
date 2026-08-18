<?php

namespace SecureS3StorageForWordpress\Backup\Retention;

final class RetentionSetting
{
    public const DISABLED = 0;

    /**
     * Normalize a saved or submitted retention setting.
     *
     * Zero disables retention. Any positive integer supported by PHP is
     * accepted; invalid, fractional, negative, and overflowing values disable
     * retention instead of being silently truncated.
     */
    public static function normalize(mixed $value): int
    {
        $validated = filter_var(
            $value,
            FILTER_VALIDATE_INT,
            [
                'options' => [
                    'min_range' => self::DISABLED,
                    'max_range' => PHP_INT_MAX,
                ],
            ]
        );

        if ($validated === false) {
            return self::DISABLED;
        }

        return $validated;
    }
}
