<?php

// Synthetic WordPress fixture only, run by tools/test-wordpress-recovery-local.sh.
// This local disk-transport fixture needs only product source, not the AWS SDK.
spl_autoload_register(static function(string $class):void {
    $prefix='SecureS3StorageForWordpress\\';
    if(str_starts_with($class,$prefix))require_once '/repo/src/'.str_replace('\\','/',substr($class,strlen($prefix))).'.php';
});
require __DIR__ . '/wordpress-recovery-remote.php';
require __DIR__ . '/wordpress-recovery-package.php';
require __DIR__ . '/wordpress-observation-run.php';
use SecureS3StorageForWordpress\Backup\Recovery\{ObservedRecoveryRun, RecoveryManifest};
use SecureS3StorageForWordpress\WordPress\WordPressRecoveryCatalog;

use SecureS3StorageForWordpress\Backup\Recovery\{RecoverySet, RecoveryWorkspace, RecoveryPipeline, RecoveryCollector, RecoveryPublisher, RecoveryObservation};
use SecureS3StorageForWordpress\Backup\Database\{DatabaseConnection, NativeMySqlDumper};
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\Media\{MediaSource, MediaManifest, MediaEntry};
use SecureS3StorageForWordpress\WordPress\WordPressJobStore;

$mode = $argv[1] ?? '';
$backend = getenv('FIXTURE_BACKEND');
if (getenv('ODBFS3_WP_FIXTURE') !== '1' || ! in_array($mode, ['source', 'restore'], true)
    || ! in_array($backend, ['native', 'php'], true) || ! is_file('/site/wp-includes/version.php')) { throw new RuntimeException('Isolated fixture required.'); }
umask(0077); chmod('/private', 0700);
$_SERVER['HTTP_HOST'] = 'fixture.invalid'; $_SERVER['SERVER_NAME'] = 'fixture.invalid'; $_SERVER['SERVER_PORT'] = '80'; $_SERVER['REQUEST_URI'] = '/';
$checks = 0;
function verify(bool $ok, string $label): void { global $checks; if (! $ok) { throw new RuntimeException($label); } ++$checks; }
function rejects(callable $action, string $label): void {
    try { $action(); } catch (Throwable $e) { verify(true, $label); return; }
    throw new RuntimeException('Accepted invalid input: ' . $label);
}
function bootFixture(string $prefix, bool $install): void {
    $config = "<?php\n";
    foreach (['DB_NAME' => 'fixture', 'DB_USER' => 'fixture', 'DB_PASSWORD' => getenv('FIXTURE_PASSWORD'), 'DB_HOST' => 'fixture-db',
        'DB_CHARSET' => 'utf8mb4', 'DB_COLLATE' => '', 'DISABLE_WP_CRON' => true, 'WP_HTTP_BLOCK_EXTERNAL' => true,
        'AUTOMATIC_UPDATER_DISABLED' => true, 'DISALLOW_FILE_MODS' => true, 'WP_DISABLE_FATAL_ERROR_HANDLER' => true, 'WP_ENVIRONMENT_TYPE' => 'local'] as $key => $value) {
        $config .= 'define(' . var_export($key, true) . ', ' . var_export($value, true) . ");\n";
    }
    $config .= '$table_prefix = ' . var_export($prefix, true) . ";\nif (!defined('ABSPATH')) { define('ABSPATH', '/site/'); }\nrequire_once ABSPATH . 'wp-settings.php';\n";
    file_put_contents('/site/wp-config.php', $config);
    if (! is_dir('/site/wp-content/mu-plugins')) { mkdir('/site/wp-content/mu-plugins', 0700); }
    copy(__DIR__ . '/wordpress-recovery-guard.php', '/site/wp-content/mu-plugins/fixture-guard.php');
    if ($install) { define('WP_INSTALLING', true); }
}

$remote = new WordPressRecoveryRemote();
if ($mode === 'source') {
    bootFixture('fx_', true);
    require '/site/wp-load.php';
    require '/site/wp-admin/includes/upgrade.php';
    wp_install('復旧検証サイト', 'fixtureadmin', 'fixture@example.invalid', true, '', 'synthetic-test-only');
    update_option('home', 'http://fixture.invalid'); update_option('siteurl', 'http://fixture.invalid');
    require_once '/site/wp-admin/includes/image.php';
    $image = imagecreatetruecolor(480, 320); $color = imagecolorallocate($image, 20, 100, 130); imagefill($image, 0, 0, $color);
    ob_start(); imagepng($image); $png = ob_get_clean(); imagedestroy($image);
    $upload = wp_upload_bits('検証画像.png', null, $png);
    verify(! $upload['error'], 'Fixture upload');
    $attachment = wp_insert_attachment(['post_title' => '検証画像 🌱', 'post_mime_type' => 'image/png', 'post_status' => 'inherit'], $upload['file'], 0, true);
    verify(! is_wp_error($attachment), 'Attachment inserted');
    wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $upload['file']));
    update_post_meta($attachment, '_wp_attachment_image_alt', '地域のエネルギー検証画像');
    $content = '<!-- wp:paragraph --><p>復旧確認：日本語と絵文字 🌱</p><!-- /wp:paragraph -->'
        . '<!-- wp:image {"id":' . $attachment . '} --><figure class="wp-block-image"><img src="' . esc_url(wp_get_attachment_url($attachment)) . '" alt="地域のエネルギー検証画像" class="wp-image-' . $attachment . '"/></figure><!-- /wp:image -->';
    $page = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_title' => '会社紹介と復旧確認', 'post_content' => $content], true);
    $post = wp_insert_post(['post_type' => 'post', 'post_status' => 'publish', 'post_title' => 'コラム：負荷電流', 'post_content' => '測定と改善 🌱'], true);
    verify(! is_wp_error($page) && ! is_wp_error($post), 'Page and post inserted');
    wp_update_post(['ID' => $attachment, 'post_parent' => $page]); set_post_thumbnail($page, $attachment);
    $term = wp_insert_term('エネルギー', 'category');
    verify(! is_wp_error($term), 'Category inserted');
    wp_set_post_terms($post, [$term['term_id']], 'category');
    $author = wp_insert_user(['user_login' => 'fixtureeditor', 'user_pass' => 'synthetic-editor-only', 'user_email' => 'editor@example.invalid', 'role' => 'editor', 'display_name' => '検証担当者']);
    verify(! is_wp_error($author), 'Editor inserted');
    wp_update_post(['ID' => $post, 'post_author' => $author]);
    wp_insert_comment(['comment_post_ID' => $post, 'comment_author' => '検証読者', 'comment_content' => '確認しました 🌱', 'comment_approved' => 1]);
    $nested = ['日本語' => ['message' => '復元後も保持 🌱', 'enabled' => true, 'count' => 7], 'url' => 'http://fixture.invalid/案内'];
    update_option('fixture_nested', $nested, false); update_post_meta($page, 'fixture_nested', $nested);
    update_option('home', 'http://fixture.invalid'); update_option('siteurl', 'http://fixture.invalid');
    update_option('show_on_front', 'page'); update_option('page_on_front', $page);
    wp_schedule_single_event(time() - 1, 'odbfs3_fixture_cron');
    file_put_contents(dirname($upload['file']) . '/空ファイル.txt', '');
    $savedContent = get_post($page)->post_content;
    verify(str_contains($savedContent, '復旧確認：日本語と絵文字') && str_contains($savedContent, 'wp-image-' . $attachment), 'Fixture retains text and image block after WordPress sanitization');
    $expected = ['page' => $page, 'post' => $post, 'attachment' => $attachment, 'author' => $author,
        'content' => $savedContent, 'nested' => $nested, 'attachment_meta' => wp_get_attachment_metadata($attachment),
        'attachment_relative' => get_post_meta($attachment, '_wp_attached_file', true), 'core' => $GLOBALS['wp_version'], 'theme' => get_stylesheet()];
    file_put_contents('/expected/content.json', json_encode($expected, JSON_THROW_ON_ERROR));
    // Exercise the real WordPress CAS store, including dedicated recovery slots.
    require __DIR__ . '/test-wordpress-job-store.php';
    $checks = 0;
    $uploads = wp_get_upload_dir()['basedir'];
    $connection = new DatabaseConnection('fixture-db', 3306, 'fixture', 'fixture', getenv('FIXTURE_PASSWORD'));
    $configuration = ['source_id'=>str_repeat('a',32), 'profile'=>'database_uploads', 'bucket'=>'fixture-bucket', 'region'=>'us-east-1', 'prefix'=>'wordpress', 'backend'=>$backend,
        'environment'=>['wordpress'=>$GLOBALS['wp_version'], 'php'=>PHP_VERSION, 'plugin'=>'development', 'database_server'=>'MariaDB', 'table_prefix'=>'fx_']];
    $adapterFactory = static function(RecoverySet $set, string $backend) use($connection,$remote,$uploads):array {
        $workspace = new RecoveryWorkspace('/private', $set, '/site', $uploads);
        return [new RecoveryPipeline($workspace, $backend==='native'?new NativeMySqlDumper():new PhpMySqlDumper(chunkSize:20), $connection,$backend,$remote,new MediaSource($uploads),'/site'), new RecoveryPublisher($remote,$workspace)];
    };
    // Exercise opt-in registration and WordPress callback dispatch.
    $cronResult = null;
    $hook=\SecureS3StorageForWordpress\WordPress\WordPressRecoverySchedule::register($GLOBALS['wpdb'],$configuration,$adapterFactory,
        static function(array $result)use(&$cronResult):void{$cronResult=$result;});
    for ($i = 0; $i < 1000; ++$i) {
        do_action($hook);
        verify(!in_array($cronResult['phase'],['failed','unknown','blocked'],true),'Cron collection did not fail');
        if ($cronResult['phase'] === 'complete') { break; }
    }
    $store=WordPressJobStore::forRecovery($GLOBALS['wpdb'],$cronResult['set_id']);
    $collector=new RecoveryCollector($store);
    $set=RecoverySet::fromIntent($collector->current()['intent']);
    verify($collector->current()['phase']==='complete','Cron bounded loop independently published manifest');
    $before=$store->read();do_action($hook);
    verify($cronResult['reason_code']==='already_complete' && $store->read()===$before,'Duplicate Cron preserves complete set');
    $bytes = $collector->current()['manifest'];
    file_put_contents('/expected/locator.json', json_encode(['bucket' => $set->bucket, 'key' => $set->root() . 'manifest.json', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)], JSON_THROW_ON_ERROR));
    file_put_contents('/expected/collector.json', $store->read());
    $manifest=RecoveryManifest::decode($bytes);$catalog=new WordPressRecoveryCatalog($GLOBALS['wpdb']);
    $run=new ObservedRecoveryRun(new FixtureObservationJournal('/private/availability-run.json'),$manifest,'availability',null,
        static fn(array $r):string=>$catalog->appendObservation($set->id,$r,gmdate('Y-m-d\TH:i:s\Z')));
    mkdir('/private/availability-stage',0700);
    $availability=$run->execute(static function(ObservedRecoveryRun $run) use($remote,$manifest):void {
        $locator=json_decode(file_get_contents('/expected/locator.json'),true,8,JSON_THROW_ON_ERROR);
        $run->check('database_object',static function() use($locator,$remote):bool {
            fixturePackage($locator,'/private/availability-stage',[$remote,'path']);return true;
        });
        $m=$manifest->toArray();
        $run->check('inventory_object',static fn():bool=>hash_file('sha256','/private/availability-stage/inventory')===$m['media']['inventory']['sha256']);
        $run->check('marker_object',static fn():bool=>hash_file('sha256','/private/availability-stage/marker')===$m['media']['marker']['sha256']);
        $run->check('all_media_objects',static fn():bool=>(new MediaManifest())->verify(new MediaSource('/private/availability-stage/uploads'),'/private/availability-stage/inventory','/private')->successful());
    });
    verify($availability===['operation_passed'=>true,'registration'=>'registered'],'Actual availability automatically registered');
    verify($catalog->observations($set->id,gmdate('Y-m-d\TH:i:s\Z'))['availability']['state']==='available','Availability visible in aggregation');
    verify($store->read()===file_get_contents('/expected/collector.json'),'Availability registration preserved complete evidence');
    $cleanup=new \SecureS3StorageForWordpress\Backup\Recovery\RecoveryCleanup($store,WordPressJobStore::forRecoveryCleanup($GLOBALS['wpdb'],$set->id));
    $clean=$cleanup->execute($set->id,'/private',['/site',$uploads]);
    verify($clean['state']==='complete' && $clean['files']===['database.sql'=>'deleted','database.sql.gz'=>'deleted'],'Actual captured SQL/gzip cleanup verified');
    verify($store->read()===file_get_contents('/expected/collector.json'),'Cleanup preserves completion evidence');
    do_action($hook);verify($cronResult['reason_code']==='already_complete','Cron does not recreate cleaned files');
    echo "PASS WordPress source backend=$backend core={$GLOBALS['wp_version']} checks=$checks\n";
    exit;
}

// No WordPress bootstrap or SQL mutation before all package components pass.
$locator = json_decode(file_get_contents('/expected/locator.json'), true, 8, JSON_THROW_ON_ERROR);
$db = new mysqli('fixture-db', 'fixture', getenv('FIXTURE_PASSWORD'), 'fixture');
verify($db->query('SHOW TABLES')->num_rows === 0, 'Restore DB must be empty');
verify(! is_dir('/site/wp-content/uploads'), 'Restore uploads must be absent');
mkdir('/private/stage', 0700);
[$manifest, $entries] = fixturePackage($locator, '/private/stage', [$remote, 'path']);
$m = $manifest->toArray();
verify($m['environment']['table_prefix'] === 'fx_', 'Prefix comes from manifest');
// Negative preflights must never touch the target database or uploads.
file_put_contents('/private/corrupt', 'corrupt fixture');
foreach (['database', 'inventory', 'marker', 'media'] as $kind) {
    $badKey = match ($kind) {
        'database' => $m['database']['artifact']['key'], 'inventory' => $m['media']['inventory']['key'],
        'marker' => $m['media']['marker']['key'], 'media' => $m['media']['prefix'] . 'files/' . hash('sha256', $entries[0]->path),
    };
    mkdir('/private/bad-' . $kind, 0700);
    rejects(static fn () => fixturePackage($locator, '/private/bad-' . $kind,
        static fn ($bucket, $key) => $key === $badKey ? '/private/corrupt' : $remote->path($bucket, $key)), 'Corrupt ' . $kind);
}
mkdir('/private/missing', 0700);
rejects(static fn () => fixturePackage($locator, '/private/missing', static fn () => '/private/not-present'), 'Missing manifest');
foreach (['../escape', '/absolute', 'a/../escape', 'a\\b', 'php://filter'] as $path) { rejects(static fn () => MediaEntry::validatePath($path), 'Unsafe relative path'); }
mkdir('/private/link-stage', 0700); symlink('/private/corrupt', '/private/link-stage/link');
rejects(static fn () => fixturePackage($locator, '/private/link-stage', [$remote, 'path']), 'Nonempty extraction destination');
verify($db->query('SHOW TABLES')->num_rows === 0 && ! is_dir('/site/wp-content/uploads'), 'Failed preflights have no target side effects');
$db->close();
$environmentId=bin2hex(random_bytes(16));
$observedRestore=new ObservedRecoveryRun(new FixtureObservationJournal('/private/restore-run.json'),$manifest,'restore',$environmentId,
    static fn(array $r):string=>fixtureRestoreCatalog($manifest)->appendObservation($m['set_id'],$r,gmdate('Y-m-d\TH:i:s\Z')));
$restoreResult=$observedRestore->execute(static function(ObservedRecoveryRun $observedRestore) use($manifest,$entries,$m):void {
$process = proc_open(['mariadb', '-hfixture-db', '-ufixture', 'fixture'],
    [0 => ['file', '/private/stage/database.sql', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
    ['MYSQL_PWD' => getenv('FIXTURE_PASSWORD'), 'PATH' => getenv('PATH')]);
if (! is_resource($process)) { throw new RuntimeException('Import launch failed.'); }
$out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
$observedRestore->check('sql_import',static fn():bool=>proc_close($process) === 0 && $err === '');
mkdir('/site/wp-content/uploads', 0700);
foreach ($entries as $entry) {
    $destination = '/site/wp-content/uploads/' . $entry->path;
    if (! is_dir(dirname($destination))) { mkdir(dirname($destination), 0700, true); }
    verify(copy('/private/stage/uploads/' . $entry->path, $destination), 'Install verified media');
}
bootFixture($m['environment']['table_prefix'], false);
require '/site/wp-load.php';
$expected = json_decode(file_get_contents('/expected/content.json'), true, 16, JSON_THROW_ON_ERROR);
verify($GLOBALS['wp_version'] === $expected['core'], 'Separately supplied matching WordPress core');
verify(get_stylesheet() === $expected['theme'] && wp_get_theme()->exists(), 'Separately supplied theme');
verify(get_post($expected['page'])->post_content === $expected['content'], 'Page block content');
verify(get_post($expected['post'])->post_title === 'コラム：負荷電流', 'Japanese post');
verify(get_option('fixture_nested') === $expected['nested'] && get_post_meta($expected['page'], 'fixture_nested', true) === $expected['nested'], 'Serialized options and postmeta');
verify((int) get_option('page_on_front') === $expected['page'] && get_option('show_on_front') === 'page', 'Front page setting');
verify(get_option('home') === 'http://fixture.invalid' && get_option('siteurl') === 'http://fixture.invalid', 'URLs preserved without unsafe string replacement');
$observedRestore->check('attachment_references',static fn():bool=>get_post($expected['attachment'])->post_parent == $expected['page'] && get_post_thumbnail_id($expected['page']) === $expected['attachment']);
verify(wp_get_attachment_metadata($expected['attachment']) === $expected['attachment_meta'], 'Attachment metadata');
verify(get_post_meta($expected['attachment'], '_wp_attachment_image_alt', true) === '地域のエネルギー検証画像', 'Image alt text');
verify(is_file(get_attached_file($expected['attachment'])) && getimagesize(get_attached_file($expected['attachment'])) !== false, 'Actual attached image decodes');
foreach ($expected['attachment_meta']['sizes'] as $size) { verify(is_file(dirname(get_attached_file($expected['attachment'])) . '/' . $size['file']), 'Generated image variant exists'); }
verify(str_contains(wp_get_attachment_image($expected['attachment'], 'thumbnail'), '地域のエネルギー検証画像'), 'WordPress renders image markup');
verify(get_userdata($expected['author'])->display_name === '検証担当者' && get_post($expected['post'])->post_author == $expected['author'], 'Author reference');
verify(in_array('editor', get_userdata($expected['author'])->roles, true), 'Serialized role capability');
verify(wp_check_password('synthetic-editor-only', get_userdata($expected['author'])->user_pass, $expected['author']), 'User password hash');
verify(wp_get_post_terms($expected['post'], 'category')[0]->name === 'エネルギー', 'Taxonomy reference');
verify(get_comments(['post_id' => $expected['post']])[0]->comment_content === '確認しました 🌱', 'Comment reference');
verify(DISABLE_WP_CRON && wp_next_scheduled('odbfs3_fixture_cron') !== false, 'Restored scheduled job is not dispatched');
verify(is_wp_error(wp_remote_get('https://example.invalid')) && ! wp_mail('recipient@example.invalid', 'fixture', 'blocked'), 'HTTP and mail blocked');
$observedRestore->check('media_hashes',static fn():bool=>(new MediaManifest())->verify(new MediaSource(wp_get_upload_dir()['basedir']), '/private/stage/inventory', '/private')->successful());
// HTTP requests stay on the restore container's loopback, with no published port.
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:8765', '-t', '/site'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/private/http.log', 'a'], 2 => ['file', '/private/http.log', 'a']], $serverPipes);
if (! is_resource($server)) { throw new RuntimeException('Fixture web server failed.'); }
try {
    $listening = false;
    for ($i = 0; $i < 50; ++$i) {
        $socket = @fsockopen('127.0.0.1', 8765, $errno, $error, 0.1);
        if ($socket !== false) { fclose($socket); $listening = true; break; }
        usleep(100000);
    }
    verify($listening, 'Restored WordPress HTTP server starts');
    $context = stream_context_create(['http' => ['header' => "Host: fixture.invalid\r\n", 'timeout' => 15, 'follow_location' => 0]]);
    $html = file_get_contents('http://127.0.0.1:8765/', false, $context);
    $observedRestore->check('wordpress_content',static fn():bool=>str_contains($http_response_header[0] ?? '', '200') && str_contains($html, '復旧確認：日本語と絵文字'));
    verify(str_contains($html, 'wp-image-' . $expected['attachment']), 'Rendered page retains image reference');
    $imagePath = implode('/', array_map('rawurlencode', explode('/', $expected['attachment_relative'])));
    $response = file_get_contents('http://127.0.0.1:8765/wp-content/uploads/' . $imagePath, false, $context);
    verify(str_contains($http_response_header[0] ?? '', '200') && hash('sha256', $response) === hash_file('sha256', get_attached_file($expected['attachment'])), 'Actual image served with matching bytes');
} finally { proc_terminate($server); proc_close($server); }
});
verify($restoreResult===['operation_passed'=>true,'registration'=>'registered'],'Actual restore automatically registered');
$aggregated=fixtureRestoreCatalog($manifest)->observations($m['set_id'],gmdate('Y-m-d\TH:i:s\Z'));
verify($aggregated['restore']['state']==='passed' && $aggregated['restore']['environment_id']===$environmentId,'Actual restore visible in aggregation');
file_put_contents('/private/restore-observation.json',json_encode($aggregated,JSON_THROW_ON_ERROR));
echo "PASS WordPress restore backend=$backend core={$GLOBALS['wp_version']} checks=$checks media_files=" . count($entries) . " manifest_sha256={$locator['sha256']}\n";
