<?php

declare(strict_types=1);

return [
    'prefix' => 'SecureS3StorageForWordpressVendor',
    'php-version' => '8.1',
    'exclude-namespaces' => [
        'SecureS3StorageForWordpress',
    ],
    'exclude-classes' => [
        'WP_CLI',
        // WordPress supplies the real DB object; never prefix its type hint.
        'wpdb',
    ],
    'patchers' => [
        static function (
            string $filePath,
            string $prefix,
            string $contents
        ): string {
            /*
             * AwsClient::parseClass() builds service exception names as a
             * string. PHP-Scoper cannot rewrite that dynamic class name, so
             * derive it from the scoped Aws namespace at runtime instead.
             */
            $search =
                '"Aws\\\\{$service}\\\\Exception\\\\{$service}Exception"';
            $replacement =
                '__NAMESPACE__ . "\\\\{$service}\\\\Exception\\\\{$service}Exception"';

            $patched = $contents;

            if (str_contains($patched, $search)) {
                $patched = str_replace(
                    $search,
                    $replacement,
                    $patched,
                    $replacementCount
                );

                if ($replacementCount !== 1) {
                    throw new RuntimeException(
                        'Unable to patch the AWS SDK dynamic exception class.'
                    );
                }
            }

            /*
             * PHP-Scoper interprets the SignatureV4 ISO 8601 date format as
             * a class-string and prefixes it. Restore the literal format so
             * AWS request dates and credential scopes remain valid.
             */
            $scopedIso8601Format =
                "'" . $prefix . "\\Ymd\\THis\\Z'";
            $iso8601Format = "'Ymd\\THis\\Z'";

            if (str_contains($patched, $scopedIso8601Format)) {
                $patched = str_replace(
                    $scopedIso8601Format,
                    $iso8601Format,
                    $patched,
                    $dateFormatReplacementCount
                );

                if ($dateFormatReplacementCount !== 1) {
                    throw new RuntimeException(
                        'Unable to restore the AWS SignatureV4 date format.'
                    );
                }
            }

            return $patched;
        },
    ],
    'expose-global-constants' => true,
    'expose-global-classes' => true,
    'expose-global-functions' => true,
];
