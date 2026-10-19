<?php
declare(strict_types=1);
namespace SecureS3StorageForWordpress\WordPress;

use wpdb;
use SecureS3StorageForWordpress\Cron\{RecoverySchedulePolicy,RecoveryScheduleStatus};

final class WordPressRecoveryScheduleStatus
{
    public static function policy(wpdb $db,string $source):RecoverySchedulePolicy
    {
        return new RecoverySchedulePolicy(WordPressJobStore::forRecoveryExpectation($db,$source),$source);
    }

    public static function read(wpdb $db,string $source,?int $now=null):array
    {
        $old=$db->suppress_errors(true);
        try {
            return (new RecoveryScheduleStatus(
                WordPressJobStore::forRecoveryExpectation($db,$source),
                WordPressJobStore::forRecoveryExecution($db,$source),
                static fn(string $id)=>WordPressJobStore::forRecovery($db,$id),$source))->read($now??time());
        } finally { $db->suppress_errors($old); }
    }
}
