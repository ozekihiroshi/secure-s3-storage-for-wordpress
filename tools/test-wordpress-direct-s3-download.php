<?php
// Fresh process and empty /remote; never reuses the source's read cache.
try {
    require '/repo/vendor/autoload.php';
    require '/repo/tests/manual/wordpress-direct-s3-reader.php';
    require '/repo/tests/manual/wordpress-recovery-package.php';
    if (getenv('ODBFS3_DIRECT_S3')!=='1' || scandir('/remote')!==['.','..']) { throw new RuntimeException('Empty download directory required.'); }
    $locator=json_decode(file_get_contents('/expected/locator.json'),true,8,JSON_THROW_ON_ERROR);
    $reader=new WordPressDirectS3Reader(\SecureS3StorageForWordpress\Aws\MediaS3Client::create('ap-northeast-1'));
    mkdir('/private/stage',0700);
    [$manifest,$entries]=fixturePackage($locator,'/private/stage',[$reader,'path']);
    $collector=json_decode(file_get_contents('/expected/collector.json'),true,24,JSON_THROW_ON_ERROR);
    if ($collector['phase']!=='complete' || $collector['manifest']!==$manifest->encode() || count($reader->objects)!==8) { throw new RuntimeException('Worker manifest mismatch.'); }
    file_put_contents('/expected/direct-s3-report.json',json_encode(['transport'=>'direct_wordpress_worker','prefix'=>getenv('FIXTURE_S3_PREFIX'),'objects'=>$reader->objects,'worker_manifest_unchanged'=>true,'fresh_download_verified'=>true,'checked_at'=>gmdate(DATE_ATOM)],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo 'PASS direct S3 fresh download objects='.count($reader->objects)."\n";
} catch (Throwable $e) { fwrite(STDERR,'Direct download failed: '.get_class($e)."\n");exit(1); }
