<?php

namespace AICodingGuard\Support;

use AICodingGuard\Exceptions\PatchRejectedException;
use Illuminate\Support\Str;

/**
 * Decides which files an AI change may touch.
 *
 * Patterns use Str::is() syntax ("*" also matches "/"), relative to the
 * project root: "tests/*", ".env*", "config/auth.php".
 */
final class PathGuard
{
    /**
     * @param  list<string>  $protected  paths the AI may never change
     * @param  list<string>  $allowed  if not empty, every changed path must match one of these
     */
    public function __construct(
        private readonly array $protected = [],
        private readonly array $allowed = [],
    ) {
    }

    /**
     * @param  list<string>  $paths
     *
     * @throws PatchRejectedException
     */
    public function check(array $paths): void
    {
        $violations = [];

        foreach ($paths as $path) {
            if ($reason = $this->violation($path)) {
                $violations[] = "{$path} ({$reason})";
            }
        }

        if ($violations !== []) {
            throw new PatchRejectedException('CODE_DIFF touches paths the AI is not allowed to change: '.implode(', ', $violations));
        }
    }

    public function violation(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/') || preg_match('#^[a-zA-Z]:/#', $normalized) || in_array('..', explode('/', $normalized), true)) {
            return 'path escapes the project';
        }

        foreach ($this->protected as $pattern) {
            if (Str::is($pattern, $normalized)) {
                return "protected by \"{$pattern}\"";
            }
        }

        if ($this->allowed !== [] && ! Str::is($this->allowed, $normalized)) {
            return 'not in allowed paths';
        }

        return null;
    }

    /** @return list<string> */
    public function protectedPatterns(): array
    {
        return $this->protected;
    }

    /** @return list<string> */
    public function allowedPatterns(): array
    {
        return $this->allowed;
    }
}
