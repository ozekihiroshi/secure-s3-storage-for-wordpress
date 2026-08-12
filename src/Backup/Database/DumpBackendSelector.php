<?php

namespace SecureS3StorageForWordpress\Backup\Database;

use SecureS3StorageForWordpress\Backup\Database\Php\PhpMySqlDumper;

final class DumpBackendSelector
{
    /**
     * @param list<string> $binaryCandidates
     */
    public function __construct(
        private array $binaryCandidates = [
            'mysqldump',
            'mariadb-dump',
        ]
    ) {
    }

    public function select(): DatabaseDumper
    {
        if ($this->hasNativeDumpUtility()) {
            return new NativeMySqlDumper(
                $this->binaryCandidates
            );
        }

        return new PhpMySqlDumper();
    }

    public function getSelectedBackendName(): string
    {
        return $this->hasNativeDumpUtility()
            ? 'native'
            : 'php';
    }

    public function getDetectedUtility(): ?string
    {
        foreach ($this->binaryCandidates as $candidate) {
            $path = $this->findExecutable($candidate);

            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function hasNativeDumpUtility(): bool
    {
        return $this->getDetectedUtility() !== null;
    }

    private function findExecutable(string $binary): ?string
    {
        $process = proc_open(
            ['which', $binary],
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes
        );

        if (! is_resource($process)) {
            return null;
        }

        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode !== 0) {
            return null;
        }

        $path = trim((string) $stdout);

        return $path !== '' ? $path : null;
    }
}
