<?php

namespace SecureS3StorageForWordpress\WordPress {
    function is_multisite(): bool { return $GLOBALS['admin_multisite'] ?? false; }
}
namespace SecureS3StorageForWordpress\Admin {
    function delete_transient($key): void { unset($GLOBALS['admin_transients'][$key]); }
}
namespace {
    use SecureS3StorageForWordpress\Admin\MediaBackupPanel;
    use SecureS3StorageForWordpress\Backup\Job\BackupJob;
    use SecureS3StorageForWordpress\Backup\Job\JobStore;
    use SecureS3StorageForWordpress\WordPress\MediaJobController;
    use SecureS3StorageForWordpress\WordPress\MediaWorkConfiguration;

    define('ODBFS3_UPLOAD_HELPERS_ONLY', true);
    require __DIR__ . '/test-media-upload.php';

    final class AdminResponse extends RuntimeException {
        public function __construct(public string $kind, public mixed $payload, public int $status = 200) { parent::__construct($kind); }
    }
    function current_user_can($cap): bool { return $cap === 'manage_options' && ($GLOBALS['admin_allowed'] ?? false); }
    function wp_die($message, $title = '', $args = []): never { throw new AdminResponse('die', $message, $args['response'] ?? 403); }
    function check_admin_referer($action, $name): void {
        if (($_POST[$name] ?? '') !== 'nonce-' . $action) { wp_die('Invalid nonce'); }
    }
    function check_ajax_referer($action, $name): void { check_admin_referer($action, $name); }
    function wp_create_nonce($action): string { return 'nonce-' . $action; }
    function wp_nonce_field($action, $name): void { echo '<input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr(wp_create_nonce($action)) . '">'; }
    function wp_send_json_success($data): never { throw new AdminResponse('json', ['success' => true, 'data' => $data]); }
    function wp_send_json_error($data, $status): never { throw new AdminResponse('json', ['success' => false, 'data' => $data], $status); }
    function nocache_headers(): void { $GLOBALS['admin_nocache'] = true; }
    function set_transient($name, $value, $seconds): bool { $GLOBALS['admin_transients'][$name] = $value; return true; }
    function get_transient($name): mixed { return $GLOBALS['admin_transients'][$name] ?? false; }
    function get_current_user_id(): int { return $GLOBALS['admin_user'] ?? 1; }
    function admin_url($path): string { return 'http://localhost/wp-admin/' . $path; }
    function wp_safe_redirect($url): never { throw new AdminResponse('redirect', $url); }
    function __($text, $domain = ''): string { return $text; }
    function esc_html($text): string { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
    function esc_attr($text): string { return esc_html($text); }
    function esc_url($text): string { return esc_html($text); }
    function esc_html__($text, $domain): string { return esc_html($text); }
    function esc_html_e($text, $domain): void { echo esc_html($text); }
    function sanitize_text_field($text): string { return strip_tags($text); }
    function wp_unslash($text): string { return stripslashes($text); }
    function number_format_i18n($number): string { return number_format($number); }
    function size_format($bytes, $decimals = 0): string { return $bytes . ' B'; }
    function disabled($value): void { if ($value) { echo 'disabled="disabled"'; } }
    function plugins_url($path, $file): string { return 'http://localhost/plugin/' . $path; }
    function wp_enqueue_script(...$args): void { $GLOBALS['admin_scripts'][] = $args; }

    final class AdminStore implements JobStore {
        public ?string $record = null;
        public int $reads = 0;
        public int $writes = 0;
        public ?Closure $onRead = null;
        public function read(): ?string {
            ++$this->reads;
            if ($this->onRead !== null) { ($this->onRead)($this); }
            return $this->record;
        }
        public function compareAndSwap(?string $expected, string $replacement): bool {
            if ($expected !== $this->record) { return false; }
            ++$this->writes; $this->record = $replacement; return true;
        }
    }
    $base = sys_get_temp_dir() . '/odbfs3-admin-' . bin2hex(random_bytes(12));
    mkdir($base, 0700); $checks = 0; $exit = 0;
    $check = static function (bool $ok, string $message) use (&$checks): void {
        if (!$ok) { throw new RuntimeException($message); } ++$checks;
    };
    $reject = static function (callable $fn, string $message) use ($check): void {
        try { $fn(); } catch (RuntimeException $e) { $check(true, $message); return; }
        $check(false, $message);
    };
    $request = static function (callable $fn): AdminResponse {
        try { $fn(); } catch (AdminResponse $response) { return $response; }
        throw new RuntimeException('Expected terminating HTTP response.');
    };
    $render = static function ($panel): string { ob_start(); $panel->render(); return ob_get_clean(); };
    try {
        foreach (['site', 'site/wp', 'site/wp/content', 'site/wp/content/uploads', 'private', 'remote'] as $dir) { mkdir($base . '/' . $dir, 0700); }
        define('ABSPATH', $base . '/site/wp/');
        define('WP_CONTENT_DIR', $base . '/site/wp/content');
        $_SERVER['DOCUMENT_ROOT'] = $base . '/site';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $root = WP_CONTENT_DIR . '/uploads'; $GLOBALS['test_upload_root'] = $root;
        mkdir($root . '/2026'); mkdir($root . '/2026/08');
        file_put_contents($root . '/image.png', 'synthetic media');
        $GLOBALS['test_options']['secure_s3_storage_settings'] = ['region' => 'test-region', 'bucket' => 'test-bucket', 'prefix' => 'admin/'];
        $config = new MediaWorkConfiguration();
        $reject(fn () => $config->directory(), 'Unconfigured private storage fails.');
        define('ODBFS3_MEDIA_WORK_DIR', $base . '/private');
        $check($config->directory() === $base . '/private', 'Existing 0700 directory accepted.');
        $check(scandir($base . '/private') === ['.', '..'], 'Preflight writes nothing.');
        foreach ([$root, ABSPATH, $base . '/site', $base . '/missing', 'relative', $base . '/private/../private'] as $path) {
            $reject(fn () => $config->validate($path), 'Unsafe/noncanonical path rejected.');
        }
        chmod($base . '/private', 0755);
        $reject(fn () => $config->directory(), 'Shared permissions rejected.');
        chmod($base . '/private', 0700);
        symlink($base . '/private', $base . '/linked');
        $reject(fn () => $config->validate($base . '/linked'), 'Linked path rejected.');
        $GLOBALS['admin_multisite'] = true;
        $reject(fn () => $config->directory(), 'Multisite rejected.');
        $GLOBALS['admin_multisite'] = false;

        $store = new AdminStore(); $created = 0; $remote = new UploadTestS3($base . '/remote');
        $controller = new MediaJobController($store, static function () use (&$created, $remote) { ++$created; return $remote; });
        $panel = new MediaBackupPanel($controller); $panel->register(); $controller->register();
        $check(isset($GLOBALS['test_actions']['admin_post_' . MediaBackupPanel::START_ACTION], $GLOBALS['test_actions']['wp_ajax_' . MediaBackupPanel::STATUS_ACTION]), 'Authenticated handlers registered.');
        $check(!isset($GLOBALS['test_actions']['admin_post_nopriv_' . MediaBackupPanel::START_ACTION], $GLOBALS['test_actions']['wp_ajax_nopriv_' . MediaBackupPanel::STATUS_ACTION]), 'No public handlers.');
        $_POST = ['odbfs3_media_nonce' => wp_create_nonce(MediaBackupPanel::START_ACTION), 'odbfs3_previous_job' => 'none'];
        $check($render($panel) === '', 'Unauthorized panel hidden.');
        $check($request([$panel, 'handleStart'])->status === 403 && $store->reads === 0, 'Capability denied before storage access.');
        $check($request([$panel, 'handleStatus'])->status === 403, 'Status requires capability too.');
        $GLOBALS['admin_allowed'] = true;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $check($request([$panel, 'handleStart'])->status === 405, 'GET cannot start.');
        $check($request([$panel, 'handleStatus'])->status === 405, 'Status endpoint requires POST.');
        $_SERVER['REQUEST_METHOD'] = 'POST'; $_POST['odbfs3_media_nonce'] = 'wrong';
        $check($request([$panel, 'handleStart'])->status === 403, 'Invalid start nonce rejected.');
        $check($request([$panel, 'handleStatus'])->status === 403, 'Invalid status nonce rejected.');
        $check($store->writes === 0 && scandir($base . '/private') === ['.', '..'], 'Rejected requests do not initialize work.');
        $_POST['odbfs3_media_nonce'] = wp_create_nonce(MediaBackupPanel::START_ACTION);
        $_POST['odbfs3_previous_job'] = [];
        $check($request([$panel, 'handleStart'])->status === 400, 'Malformed generation rejected.');
        $_POST['odbfs3_previous_job'] = 'none';
        $html = $render($panel);
        $check(str_contains($html, 'name="odbfs3_previous_job" value="none"'), 'First form generation is none, not empty.');
        $check(!str_contains($html, $base), 'No configured private/source path in HTML.');
        $panel->enqueueScript('dashboard');
        $check(empty($GLOBALS['admin_scripts']), 'Script not loaded elsewhere.');
        $panel->enqueueScript('settings_page_ozeki-database-backup-for-s3');
        $check(count($GLOBALS['admin_scripts']) === 1, 'Script only on plugin settings page.');
        $_POST['work_directory'] = '/do-not-use-browser-path';
        $_POST['bucket'] = 'attacker-bucket';
        $before = stat($root);
        $response = $request([$panel, 'handleStart']); $job = $controller->current();
        $check($response->kind === 'redirect' && str_ends_with($response->payload, '#odbfs3-media'), 'Post/redirect/get.');
        $check($job->status === 'queued' && $job->checkpoint['phase'] === 'enumerate', 'Only queues preparation.');
        $check($job->checkpoint['bucket'] === 'test-bucket' && str_starts_with($job->checkpoint['directory'], $base . '/private/'), 'Request cannot override paths/destination.');
        $check(!file_exists($job->checkpoint['directory'] . '/paths.jsonl') && $created === 0, 'No scan/S3 on start.');
        clearstatcache(); $after = stat($root);
        $check($before['mtime'] === $after['mtime'] && $before['nlink'] === $after['nlink'], 'Start does not initialize/mutate uploads.');
        $writes = $store->writes; $saved = $store->record;
        $request([$panel, 'handleStart']);
        $check($store->record === $saved && $GLOBALS['admin_transients']['odbfs3_media_notice_1'] === 'changed', 'Double click rejects second start.');
        $_POST['odbfs3_media_nonce'] = wp_create_nonce(MediaBackupPanel::STATUS_ACTION);
        $response = $request([$panel, 'handleStatus']);
        $check($response->payload['success'] && $response->payload['data']['active'] && $GLOBALS['admin_nocache'], 'Authenticated no-cache status.');
        $check($store->writes === $writes && $store->record === $saved && $created === 0, 'Polling is read-only and never ticks.');
        // Run registered worker without any page/status request (browser closed).
        $GLOBALS['test_actions'][MediaJobController::HOOK]($job->id);
        $check($controller->current()->status === 'succeeded' && $created === 1, 'Cron completes without browser involvement.');
        $state = $panel->snapshot();
        $check($state['status'] === 'succeeded' && !$state['active'] && $state['uploaded_files'] === '1', 'Success and counters displayed.');
        $check(!str_contains(json_encode($state), $base) && !str_contains(json_encode($state), 'test-bucket'), 'Status excludes private checkpoint and destination.');
        $_POST['odbfs3_media_nonce'] = wp_create_nonce(MediaBackupPanel::START_ACTION);
        $saved = $store->record; $request([$panel, 'handleStart']);
        $check($store->record === $saved, 'Old form cannot enqueue even after completion.');
        $reject(fn () => $controller->enqueuePreparation($base . '/private', ''), 'Controller rejects stale first generation.');
        $_POST['odbfs3_previous_job'] = $job->id;
        $request([$panel, 'handleStart']);
        $next = $controller->current();
        $check($next->id !== $job->id && isset($GLOBALS['test_options']['secure_s3_storage_media_result_' . $job->id]), 'Explicit refreshed form archives and starts next job.');
        $store->record = $next->fail('preparation_requires_cli')->encode();
        $check(str_contains($panel->snapshot()['message'], 'WP-CLI'), 'CLI-required failure explains fallback.');
        $_POST['odbfs3_previous_job'] = $next->id;
        $saved = $store->record;
        $html = $render($panel);
        $check(str_contains($html, 'disabled="disabled"'), 'Failed job disables browser start.');
        $request([$panel, 'handleStart']);
        $check($store->record === $saved && $GLOBALS['admin_transients']['odbfs3_media_notice_1'] === 'changed', 'Crafted failed-job start rejected.');
        $store->record = 'corrupt checkpoint SECRET';
        $_POST['odbfs3_media_nonce'] = wp_create_nonce(MediaBackupPanel::STATUS_ACTION);
        $response = $request([$panel, 'handleStatus']);
        $check($response->status === 503 && !str_contains(json_encode($response->payload), 'SECRET'), 'Corrupt state fails safely without leaking details.');

        // Intervening terminal job between first read and submit: final recheck wins.
        $race = new AdminStore();
        $other = new BackupJob(str_repeat('a', 32), 'media', 'succeeded');
        $race->onRead = static function ($s) use ($other): void { if ($s->reads === 2) { $s->record = $other->encode(); } };
        $racing = new MediaJobController($race);
        $reject(fn () => $racing->enqueuePreparation($base . '/private', ''), 'Generation rechecked before CAS.');
        $check($race->record === $other->encode() && $race->writes === 0, 'Race preserves intervening job.');
        echo 'PASS media admin checks=' . $checks . ' peak_bytes=' . memory_get_peak_usage(true) . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"); $exit = 1;
    } finally {
        // Only this test's generated temporary tree, never source/user directories.
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($walk as $file) { $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($base);
    }
    exit($exit);
}
