<?php

namespace AICodingGuard\Support;

use AICodingGuard\Exceptions\EnvironmentException;

final class GuardStorage
{
    /**
     * Create the guard's storage directory with a .gitignore inside, so the
     * files the guard writes (memory, pending patch) never dirty the working
     * tree the guard itself requires to be clean.
     */
    public static function ensure(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new EnvironmentException("Cannot create directory: {$directory}");
        }

        $gitignore = $directory.DIRECTORY_SEPARATOR.'.gitignore';

        if (! file_exists($gitignore)) {
            file_put_contents($gitignore, "*\n!.gitignore\n");
        }
    }

    /**
     * Write a file atomically (temp file + rename) under an exclusive lock.
     */
    public static function write(string $file, string $contents): void
    {
        self::ensure(dirname($file));

        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (file_put_contents($tmp, $contents, LOCK_EX) === false || ! rename($tmp, $file)) {
            @unlink($tmp);

            throw new EnvironmentException("Cannot write file: {$file}");
        }
    }
}
