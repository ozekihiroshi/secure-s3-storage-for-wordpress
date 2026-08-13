<?php

namespace SecureS3StorageForWordpress\Backup\Database;

use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;

final class DumpBackendSelector
{
    /**
     * @var list<string>
     */
    private array $binaryCandidates;

    private ExecutableFinder $executableFinder;

    /**
     * @param list<string> $binaryCandidates
     */
    public function __construct(
        array $binaryCandidates = [
            'mysqldump',
            'mariadb-dump',
        ],
        ?ExecutableFinder $executableFinder = null
    ) {
        $this->binaryCandidates =
            $binaryCandidates;

        $this->executableFinder =
            $executableFinder
            ?? new ExecutableFinder();
    }

    public function select(): DatabaseDumper
    {
        $binary =
            $this->getDetectedUtility();

        if ($binary === null) {
            return new PhpMySqlDumper();
        }

        return new NativeMySqlDumper(
            [$binary],
            $this->executableFinder
        );
    }

    public function getSelectedBackendName(): string
    {
        return $this->getDetectedUtility() !== null
            ? 'native'
            : 'php';
    }

    public function getDetectedUtility(): ?string
    {
        if (! $this->canExecuteProcesses()) {
            return null;
        }

        foreach (
            $this->binaryCandidates
            as $candidate
        ) {
            $path =
                $this->executableFinder->find(
                    $candidate
                );

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function canExecuteProcesses(): bool
    {
        return function_exists('proc_open')
            && is_callable('proc_open');
    }
}