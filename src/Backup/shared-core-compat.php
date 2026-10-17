<?php
// Generated compatibility entrypoints; implementation lives in libs/backup-core.
require_once dirname(__DIR__, 2).'/libs/backup-core/autoload.php';
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\DatabaseConnection', 'SecureS3StorageForWordpress\\Backup\\Database\\DatabaseConnection');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\DatabaseDumper', 'SecureS3StorageForWordpress\\Backup\\Database\\DatabaseDumper');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\DumpResult', 'SecureS3StorageForWordpress\\Backup\\Database\\DumpResult');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\ColumnMetadataReader', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\ColumnMetadataReader');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\PhpMySqlDumper', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\PhpMySqlDumper');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\RowReader', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\RowReader');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\SchemaReader', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\SchemaReader');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\SqlValueSerializer', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\SqlValueSerializer');
class_alias('Ozeki\\BackupCore\\V0_1_0\\Database\\Php\\SqlWriter', 'SecureS3StorageForWordpress\\Backup\\Database\\Php\\SqlWriter');
class_alias('Ozeki\\BackupCore\\V0_1_0\\CompleteStreamWriter', 'SecureS3StorageForWordpress\\Backup\\CompleteStreamWriter');
class_alias('Ozeki\\BackupCore\\V0_1_0\\SecureTemporaryFile', 'SecureS3StorageForWordpress\\Backup\\SecureTemporaryFile');
