<?php

namespace SecureS3StorageForWordpress\Backup\Recovery;

/** Waiting for an existing child lease is not a failed transfer attempt. */
final class RecoveryPending extends \RuntimeException {}
