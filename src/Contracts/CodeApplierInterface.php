<?php

namespace AICodingGuard\Contracts;

interface CodeApplierInterface
{
    /**
     * Verify the diff can be applied (and the project is in a state to try it)
     * without changing anything.
     *
     * @throws \AICodingGuard\Exceptions\PatchRejectedException when the diff does not apply
     * @throws \AICodingGuard\Exceptions\EnvironmentException when the project is not ready (e.g. dirty tree)
     */
    public function check(string $diff): void;

    /**
     * Apply the diff and remember it so rollback() can undo it.
     */
    public function apply(string $diff): void;

    /**
     * Undo the last applied diff, restoring the files exactly as they were.
     */
    public function rollback(): void;

    /**
     * Whether there is an applied diff that rollback() can undo.
     */
    public function canRollback(): bool;
}
