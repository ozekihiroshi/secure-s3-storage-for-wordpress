<?php
declare(strict_types=1);
require __DIR__.'/../../vendor/autoload.php';
use SecureS3StorageForWordpress\Backup\Database\DatabaseConnection;
use SecureS3StorageForWordpress\Backup\Database\DatabaseDumper;
use SecureS3StorageForWordpress\Backup\Database\DumpResult;
use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;
use SecureS3StorageForWordpress\Backup\Database\NativeMySqlDumper;
$c=new DatabaseConnection('localhost',3306,'fixture','fixture','not-a-credential');
$d=new PhpMySqlDumper();
$r=new DumpResult('/tmp/fixture.sql',1,'fixture','mysql',new DateTimeImmutable());
$checks=[
 $c instanceof \Ozeki\BackupCore\V0_1_0\Database\DatabaseConnection,
 $d instanceof DatabaseDumper,
 $d instanceof \Ozeki\BackupCore\V0_1_0\Database\DatabaseDumper,
 (new NativeMySqlDumper()) instanceof DatabaseDumper,
 $r instanceof \Ozeki\BackupCore\V0_1_0\Database\DumpResult,
 str_contains((new ReflectionClass($d))->getFileName(),'/libs/backup-core/'),
 !defined('ABSPATH') && !function_exists('add_action'),
];
if (in_array(false,$checks,true))throw new RuntimeException('Compatibility check failed');
echo json_encode(['kind'=>'shared_core_legacy_compatibility','passed'=>true,'checks'=>count($checks)]),"\n";
