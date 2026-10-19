<?php
namespace SecureS3StorageForWordpress\Backup\Recovery;
// Test-only syscall fault injection. Production uses PHP's normal unlink.
function unlink(string $path): bool {
    if (($GLOBALS['cleanup_unlink_fault'] ?? null) === basename($path)) { return false; }
    return \unlink($path);
}
