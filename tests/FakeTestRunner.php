<?php

namespace AICodingGuard\Tests;

use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\TestRunners\CommandTestRunner;
use Closure;

/**
 * Measures the real file content: "tests pass" when Calculator::add adds.
 */
class FakeTestRunner implements TestRunnerInterface
{
    public int $runs = 0;

    public array $lastOptions = [];

    public function __construct(private readonly Closure $measure)
    {
    }

    public static function calculator(string $repo): self
    {
        return new self(function () use ($repo) {
            $pass = str_contains((string) file_get_contents($repo.'/app/Calculator.php'), 'return $a + $b;');

            return [
                'test_pass' => $pass,
                'exit_code' => $pass ? 0 : 1,
                'tests' => 12,
                'failures' => $pass ? 0 : 1,
                'errors' => 0,
                'duration_ms' => 1500,
                'failed_tests' => $pass ? [] : ['CalculatorTest::test_add'],
                'output' => $pass ? 'OK' : 'FAILURES!',
            ];
        });
    }

    public function run(array $options = []): array
    {
        $this->runs++;
        $this->lastOptions = $options;

        return ($this->measure)($options);
    }

    public function metrics(): array
    {
        return CommandTestRunner::METRICS;
    }
}
