<?php

namespace Ozeki\BackupCore\V0_1_0\Database;

interface DatabaseDumper
{
    public function dump(DatabaseConnection $connection): DumpResult;
}