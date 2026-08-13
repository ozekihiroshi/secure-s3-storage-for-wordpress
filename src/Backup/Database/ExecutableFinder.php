<?php

namespace SecureS3StorageForWordpress\Backup\Database;

final class ExecutableFinder
{
    public function find(string $binary): ?string
    {
        $binary = trim($binary);

        if ($binary === '') {
            return null;
        }

        if ($this->containsDirectorySeparator($binary)) {
            return $this->normalizeExecutablePath($binary);
        }

        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return null;
        }

        $extensions =
            $this->getExecutableExtensions($binary);

        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            $directory = trim($directory);

            if ($directory === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate =
                    rtrim(
                        $directory,
                        DIRECTORY_SEPARATOR
                    )
                    . DIRECTORY_SEPARATOR
                    . $binary
                    . $extension;

                $resolved =
                    $this->normalizeExecutablePath(
                        $candidate
                    );

                if ($resolved !== null) {
                    return $resolved;
                }
            }
        }

        return null;
    }

    private function containsDirectorySeparator(
        string $value
    ): bool {
        return str_contains($value, '/')
            || str_contains($value, '\\');
    }

    private function normalizeExecutablePath(
        string $path
    ): ?string {
        if (! is_file($path)) {
            return null;
        }

        if (
            DIRECTORY_SEPARATOR !== '\\'
            && ! is_executable($path)
        ) {
            return null;
        }

        $realPath = realpath($path);

        return $realPath !== false
            ? $realPath
            : $path;
    }

    /**
     * @return list<string>
     */
    private function getExecutableExtensions(
        string $binary
    ): array {
        if (DIRECTORY_SEPARATOR !== '\\') {
            return [''];
        }

        if (
            pathinfo(
                $binary,
                PATHINFO_EXTENSION
            ) !== ''
        ) {
            return [''];
        }

        $pathExt = getenv('PATHEXT');

        if (
            ! is_string($pathExt)
            || $pathExt === ''
        ) {
            return [
                '.exe',
                '.bat',
                '.cmd',
                '.com',
            ];
        }

        $extensions = [];

        foreach (
            explode(
                PATH_SEPARATOR,
                $pathExt
            ) as $extension
        ) {
            $extension =
                trim($extension);

            if ($extension === '') {
                continue;
            }

            $extensions[] =
                strtolower($extension);
        }

        return $extensions !== []
            ? $extensions
            : [''];
    }
}