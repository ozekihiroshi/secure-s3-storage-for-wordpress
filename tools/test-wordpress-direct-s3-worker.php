<?php
try { require '/repo/tests/manual/test-wordpress-recovery.php'; }
catch (Throwable $e) { fwrite(STDERR,'Direct worker failed: '.get_class($e)."\n");exit(1); }
