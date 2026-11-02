<?php

namespace AICodingGuard;

final class RunResult
{
    /** Change applied, tests run, prediction matched. */
    public const SUCCESS = 'success';

    /** Change applied, prediction did not match, change rolled back. */
    public const FAILED = 'failed';

    /** Response, manifest or diff refused before anything was changed. */
    public const REJECTED = 'rejected';

    /** Dry run: everything validated, nothing changed. */
    public const CHECKED = 'checked';

    /** Applied without running tests (--no-test). Not evaluated, not recorded. */
    public const UNVERIFIED = 'unverified';

    /** Stopped by the user or the environment, nothing changed. */
    public const ABORTED = 'aborted';

    /** Unexpected error after applying; rolled back when possible. */
    public const ERROR = 'error';

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<string>  $files
     * @param  array<string, mixed>  $actual
     */
    public function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly array $manifest = [],
        public readonly array $files = [],
        public readonly array $actual = [],
        public readonly ?EvaluationResult $evaluation = null,
        public readonly bool $applied = false,
        public readonly bool $rolledBack = false,
    ) {
    }

    public function successful(): bool
    {
        return in_array($this->status, [self::SUCCESS, self::CHECKED, self::UNVERIFIED], true);
    }
}
