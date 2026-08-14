<?php

use SecureS3StorageForWordpress\Backup\Compression\GzipCompressor;
use SecureS3StorageForWordpress\Backup\Database\Php\SqlWriter;

require __DIR__ . '/../../vendor/autoload.php';

function assertPrivateFile(string $path): void
{
    if (! is_file($path)) {
        throw new RuntimeException('Expected temporary file was not created.');
    }

    if (DIRECTORY_SEPARATOR === '\\') {
        return;
    }

    clearstatcache(true, $path);
    $permissions = fileperms($path);

    if ($permissions === false || ($permissions & 0777) !== 0600) {
        throw new RuntimeException(
            sprintf(
                'Expected mode 0600, received %04o.',
                $permissions === false ? 0 : ($permissions & 0777)
            )
        );
    }
}

$suffix = bin2hex(random_bytes(8));
$sqlPath = sys_get_temp_dir()
    . '/secure-s3-permission-test-'
    . $suffix
    . '.sql';
$gzipPath = $sqlPath . '.gz';
$writer = null;

try {
    $writer = new SqlWriter($sqlPath);

    // The SQL file must be private before the first data write.
    assertPrivateFile($sqlPath);

    $writer->writeHeader();
    $writer->close();
    $writer = null;

    assertPrivateFile($sqlPath);

    $result = (new GzipCompressor())->compress($sqlPath);

    if ($result->getPath() !== $gzipPath) {
        throw new RuntimeException('Unexpected compressed file path.');
    }

    assertPrivateFile($gzipPath);

    echo "Temporary file permission verification: OK\n";
} catch (Throwable $e) {
    fwrite(
        STDERR,
        'Temporary file permission verification failed: '
        . $e->getMessage()
        . PHP_EOL
    );

    $cause = $e->getPrevious();

    while ($cause !== null) {
        fwrite(
            STDERR,
            'Caused by: ' . $cause->getMessage() . PHP_EOL
        );

        $cause = $cause->getPrevious();
    }
    exit(1);
} finally {
    if ($writer instanceof SqlWriter) {
        $writer->close();
    }

    foreach ([$gzipPath, $sqlPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}
