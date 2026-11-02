<?php

namespace AICodingGuard\Contracts;

interface TestRunnerInterface
{
    /**
     * Run the experiment and return the measured metrics. Must at least
     * contain "test_pass" (bool). Extra non-metric keys such as "output" or
     * "failed_tests" are informational.
     *
     * Implementations must fail closed: when the tests cannot run,
     * report test_pass = false, never true.
     *
     * @param  array{filter?: ?string}  $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array;

    /**
     * Names of the metrics run() measures; a prediction may only use these.
     *
     * @return list<string>
     */
    public function metrics(): array;
}
