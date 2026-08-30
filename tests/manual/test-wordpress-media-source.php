<?php

namespace SecureS3StorageForWordpress\WordPress {
    function is_multisite(): bool
    {
        return $GLOBALS['odbfs3_test_multisite'] ?? false;
    }
    function wp_get_upload_dir(): array
    {
        return $GLOBALS['odbfs3_test_uploads'];
    }
}

namespace {
    use SecureS3StorageForWordpress\WordPress\WordPressMediaSourceFactory;

    spl_autoload_register(static function (string $class): void {
        $prefix = 'SecureS3StorageForWordpress\\';
        if (str_starts_with($class, $prefix)) {
            require_once __DIR__ . '/../../src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    });
    $root = sys_get_temp_dir() . '/odbfs3-wp-media-' . bin2hex(random_bytes(12));
    mkdir($root, 0700);
    file_put_contents($root . '/fixture.txt', 'media');
    $checks = 0;
    $exitCode = 0;
    try {
        $factory = new WordPressMediaSourceFactory();
        $GLOBALS['odbfs3_test_uploads'] = ['basedir' => $root, 'error' => false];
        $_GET['path'] = '/not-the-upload-root';
        $entries = iterator_to_array($factory->create()->entries());
        if (count($entries) !== 1 || $entries[0]->path !== 'fixture.txt') {
            throw new \RuntimeException('WordPress upload-root resolution failed.');
        }
        ++$checks;
        foreach ([
            ['basedir' => $root, 'error' => 'PRIVATE SERVER DETAIL'],
            ['basedir' => '', 'error' => false],
            ['basedir' => [], 'error' => false],
            ['basedir' => $root . '/missing', 'error' => false],
            [],
        ] as $invalid) {
            $GLOBALS['odbfs3_test_uploads'] = $invalid;
            $failed = false;
            try { $factory->create(); } catch (\RuntimeException $e) {
                $failed = ! str_contains($e->getMessage(), 'PRIVATE SERVER DETAIL');
            }
            if (! $failed) { throw new \RuntimeException('Invalid upload directory was accepted or leaked details.'); }
            ++$checks;
        }
        if (file_exists($root . '/missing')) { throw new \RuntimeException('Factory must not create directories.'); }
        ++$checks;
        $GLOBALS['odbfs3_test_multisite'] = true;
        $GLOBALS['odbfs3_test_uploads'] = ['basedir' => $root, 'error' => false];
        $failed = false;
        try { $factory->create(); } catch (\RuntimeException $e) {
            $failed = str_contains($e->getMessage(), 'single-site');
        }
        if (! $failed) { throw new \RuntimeException('Network-wide enumeration must not be implicit.'); }
        ++$checks;
        echo "WordPress media source verification: OK ($checks checks)\n";
    } catch (\Throwable $e) {
        fwrite(STDERR, 'WordPress media source verification failed: ' . $e->getMessage() . PHP_EOL);
        $exitCode = 1;
    } finally {
        // Only the two exact fixture paths created above, no recursive deletion.
        unlink($root . '/fixture.txt');
        rmdir($root);
    }
    exit($exitCode);
}
