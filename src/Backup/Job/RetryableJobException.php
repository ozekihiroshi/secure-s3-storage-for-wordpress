<?php

namespace SecureS3StorageForWordpress\Backup\Job;

use RuntimeException;

/** Safe internal signal; retries retain the last committed checkpoint. */
final class RetryableJobException extends RuntimeException
{
}
