<?php

namespace SecureS3StorageForWordpress\WordPress {
    // Controller-only deterministic clock; production still uses real microtime.
    function microtime(bool $asFloat = false): float {
        return ($GLOBALS['batch_test_clock'])();
    }
}

namespace {
    use SecureS3StorageForWordpress\Backup\Job\BackupJob;
    use SecureS3StorageForWordpress\Backup\Job\JobStore;
    use SecureS3StorageForWordpress\Backup\Media\MediaManifest;
    use SecureS3StorageForWordpress\Backup\Media\MediaPreparationStep;
    use SecureS3StorageForWordpress\Backup\Media\MediaSource;
    use SecureS3StorageForWordpress\WordPress\MediaJobController;

    define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
    require __DIR__ . '/test-media-upload.php';

    final class BatchTestStore implements JobStore {
        public ?string $record = null;
        public int $swaps = 0;
        public function read(): ?string { return $this->record; }
        public function compareAndSwap(?string $expected, string $replacement): bool {
            if ($expected !== $this->record) { return false; }
            ++$this->swaps; $this->record = $replacement; return true;
        }
    }

    $base = sys_get_temp_dir() . '/odbfs3-batch-' . bin2hex(random_bytes(12));
    mkdir($base, 0700); $checks = 0; $exit = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) { throw new RuntimeException($message); } ++$checks;
    };
    try {
        foreach (['web', 'web/uploads', 'private', 'remote', 'restored'] as $dir) { mkdir($base . '/' . $dir, 0700); }
        define('ABSPATH', $base . '/web/');
        $root = $base . '/web/uploads';
        // Normal WordPress year/month directories exist BEFORE source snapshot.
        mkdir($root . '/2026'); mkdir($root . '/2026/08');
        for ($i = 0; $i < 600; ++$i) { file_put_contents($root . '/f-' . $i, 'fixture-' . $i); }
        $GLOBALS['test_upload_root'] = $root;
        $GLOBALS['test_options']['secure_s3_storage_settings'] = ['region' => 'test', 'bucket' => 'test-bucket', 'prefix' => 'batch/'];
        $GLOBALS['batch_test_clock'] = static fn (): float => 0.0;
        $remote = new UploadTestS3($base . '/remote'); $created = 0;
        $store = new BatchTestStore();
        $controller = new MediaJobController($store, static function ($region) use ($remote, &$created) { ++$created; return $remote; });
        $job = $controller->enqueuePreparation($base . '/private');
        $check(!file_exists($job->checkpoint['directory'] . '/paths.jsonl'), 'Enqueue remains read-only/no enumeration.');
        $before = $store->swaps;
        $check($controller->run($job->id) === 'running', 'Large small-file fixture yields before finishing.');
        $check($store->swaps - $before === 2000, 'Exactly 1000 durable claim/commit steps per preparation batch.');
        $check(($controller->current()->checkpoint['files'] ?? 0) > 100, 'Cheap preparation no longer stalls at roughly 25 files.');
        $check($created === 0, 'No S3 client before preparation completes.');
        $handoff = false;
        for ($i = 0; $i < 20 && !$controller->current()->terminal(); ++$i) {
            $prior = $controller->current();
            $controller->run($job->id); $current = $controller->current();
            $check($current->processedFiles - $prior->processedFiles <= 100, 'Upload keeps its 100-step cap.');
            if (isset($prior->checkpoint['phase']) && !isset($current->checkpoint['phase'])) {
                $handoff = true;
                $check($current->processedFiles <= 100, 'Handoff shares the upload cap instead of inheriting 1000.');
            }
        }
        $check($handoff && $controller->current()->status === 'succeeded', 'Preparation including preexisting year/month completes.');
        $manifest = $job->checkpoint['directory'] . '/inventory.jsonl';
        $count = 0;
        foreach ((new MediaManifest())->entries($manifest) as $entry) {
            $key = 'test-bucket/batch/backups/media/' . $job->id . '/files/' . hash('sha256', $entry->path);
            copy($remote->objects[$key]['path'], $base . '/restored/' . $entry->path);
            $check(hash_file('sha256', $base . '/restored/' . $entry->path) === hash_file('sha256', $root . '/' . $entry->path), 'Independent restored content digest.');
            ++$count;
        }
        $check($count === 600 && (new MediaManifest())->verify(new MediaSource($base . '/restored'), $manifest, $base)->successful(), 'All restored paths and bytes match.');

        // The larger step cap must never turn the shared 20-second budget into 200 seconds.
        $timed = new BatchTestStore(); $timer = new MediaJobController($timed);
        $queued = $timer->enqueuePreparation($base . '/private'); $calls = 0;
        $GLOBALS['batch_test_clock'] = static function () use (&$calls): float { return ++$calls === 1 ? 0.0 : 20.0; };
        $before = $timed->swaps;
        $check($timer->run($queued->id) === 'running' && $timed->swaps - $before === 2, 'Time budget yields after one completed step.');
        $check(!file_exists($queued->checkpoint['directory'] . '/ready.json'), 'Time yield does not publish a partial plan.');

        // Reproduce the AWS failure, late enough to reach final directory validation.
        $GLOBALS['batch_test_clock'] = static fn (): float => 0.0;
        $changed = new BatchTestStore(); $badController = new MediaJobController($changed);
        $bad = $badController->enqueuePreparation($base . '/private');
        $source = new MediaSource($root); $step = new MediaPreparationStep($source, $bad->checkpoint['directory'], ABSPATH);
        for ($i = 0; $i < 10000 && $badController->current()->checkpoint['phase'] !== 'validate_directories'; ++$i) {
            $check($step->tick($changed, $bad->id) === 'running', 'Manual preparation reaches final validation.');
        }
        $check($badController->current()->checkpoint['phase'] === 'validate_directories', 'Final validation reached.');
        mkdir($root . '/2027'); mkdir($root . '/2027/01');
        $check($step->tick($changed, $bad->id) === 'failed', 'New empty year/month tree still fails closed.');
        $check(!file_exists($bad->checkpoint['directory'] . '/ready.json') && $badController->current()->processedFiles === 0, 'Changed input never hands off or reports upload.');
        echo 'PASS preparation batch checks=' . $checks . ' peak_bytes=' . memory_get_peak_usage(true) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $exit = 1;
    } finally {
        // Only this generated, owned temporary tree, never links or caller paths.
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($base);
    }
    exit($exit);
}
