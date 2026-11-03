<?php

namespace AICodingGuard;

final class EvaluationResult
{
    /**
     * @param  list<array{metric: string, expected: mixed, actual: mixed, passed: bool, reason: ?string}>  $checks
     */
    public function __construct(public readonly array $checks)
    {
    }

    public function passed(): bool
    {
        foreach ($this->checks as $check) {
            if (! $check['passed']) {
                return false;
            }
        }

        return $this->checks !== [];
    }

    /** @return list<array{metric: string, expected: mixed, actual: mixed, passed: bool, reason: ?string}> */
    public function mismatches(): array
    {
        return array_values(array_filter($this->checks, fn (array $check) => ! $check['passed']));
    }

    public function summary(): string
    {
        return implode('; ', array_map(
            fn (array $c) => "{$c['metric']}: {$c['reason']} (actual ".json_encode($c['actual']).')',
            $this->mismatches()
        ));
    }
}
