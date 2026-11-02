<?php

namespace AICodingGuard\Support;

use AICodingGuard\Exceptions\PatchRejectedException;

/**
 * Extracts the file paths a unified / git diff touches, without applying it.
 */
final class DiffParser
{
    /**
     * @return list<string> unique repository-relative paths (old and new path for renames)
     */
    public static function files(string $diff): array
    {
        $paths = [];

        foreach (preg_split('/\R/', $diff) as $line) {
            if (preg_match('#^diff --git (\S+) (\S+)$#', $line, $m)) {
                $paths[] = self::strip($m[1]);
                $paths[] = self::strip($m[2]);
            } elseif (preg_match('#^(?:---|\+\+\+) (.+)$#', $line, $m)) {
                $paths[] = self::strip($m[1]);
            } elseif (preg_match('#^(?:rename|copy) (?:from|to) (.+)$#', $line, $m)) {
                $paths[] = trim($m[1]);
            }
        }

        $paths = array_values(array_unique(array_filter(
            $paths,
            fn (?string $path) => $path !== null && $path !== '' && $path !== '/dev/null'
        )));

        if ($paths === []) {
            throw new PatchRejectedException('CODE_DIFF does not contain any file change (expected a unified diff with ---/+++ headers).');
        }

        return $paths;
    }

    private static function strip(string $path): ?string
    {
        // "--- a/app/Foo.php\t2024-01-01 ..." -> "app/Foo.php"
        $path = trim(explode("\t", $path, 2)[0]);
        $path = trim($path, '"');

        if ($path === '/dev/null') {
            return null;
        }

        return preg_replace('#^[ab]/#', '', $path);
    }
}
