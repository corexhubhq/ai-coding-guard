<?php

namespace AICodingGuard\Contracts;

interface CodeApplierInterface
{
    public function apply(string $diff): void;
    public function rollback(): void;
}