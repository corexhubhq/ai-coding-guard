<?php

namespace AICodingGuard\TestRunners;

use AICodingGuard\Contracts\TestRunnerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Runs the project's test suite (php artisan test / Pest / PHPUnit) and turns
 * the JUnit report into metrics. Fails closed: if the suite cannot run,
 * test_pass is false.
 */
class CommandTestRunner implements TestRunnerInterface
{
    public const METRICS = ['test_pass', 'exit_code', 'tests', 'assertions', 'failures', 'errors', 'skipped', 'duration_ms'];

    /**
     * @param  list<string>|null  $command  null = auto-detect (artisan test, then Pest, then PHPUnit)
     * @param  bool  $junit  append --log-junit=<file> to collect test counts
     */
    public function __construct(
        private readonly string $basePath,
        private readonly ?array $command = null,
        private readonly int $timeout = 900,
        private readonly int $outputLimit = 4000,
        private readonly bool $junit = true,
    ) {
    }

    public function metrics(): array
    {
        return self::METRICS;
    }

    public function run(array $options = []): array
    {
        $command = $this->command ?? $this->detectCommand();

        if ($command === null) {
            return $this->failure('No test runner found (looked for artisan, vendor/bin/pest, vendor/bin/phpunit). Set ai-coding-guard.tests.command.');
        }

        $junitFile = $this->junit ? tempnam(sys_get_temp_dir(), 'ai-guard-junit-') : null;

        if ($junitFile !== null) {
            $command[] = '--log-junit='.$junitFile;
        }

        if (! empty($options['filter'])) {
            $command[] = '--filter='.$options['filter'];
        }

        $process = new Process($command, $this->basePath, ['APP_ENV' => 'testing']);
        $process->setTimeout($this->timeout > 0 ? $this->timeout : null);

        $started = hrtime(true);
        $timedOut = false;

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            $timedOut = true;
        } catch (Throwable $e) {
            $junitFile && @unlink($junitFile);

            return $this->failure('Could not run tests: '.$e->getMessage(), $command);
        }

        $exitCode = $timedOut ? null : $process->getExitCode();

        $result = [
            'test_pass' => ! $timedOut && $exitCode === 0,
            'exit_code' => $exitCode,
            'duration_ms' => (int) round((hrtime(true) - $started) / 1e6),
            'command' => implode(' ', $command),
            'timed_out' => $timedOut,
        ];

        if ($junitFile !== null) {
            $result += $this->parseJunit($junitFile);
            @unlink($junitFile);
        }

        $result['output'] = $this->tail($process->getOutput()."\n".$process->getErrorOutput());

        return $result;
    }

    /**
     * @return list<string>|null
     */
    protected function detectCommand(): ?array
    {
        if (is_file($this->basePath.'/artisan')) {
            return [PHP_BINARY, 'artisan', 'test'];
        }

        foreach (['vendor/bin/pest', 'vendor/bin/phpunit'] as $binary) {
            if (is_file($this->basePath.'/'.$binary)) {
                return [PHP_BINARY, $binary];
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function parseJunit(string $file): array
    {
        if (! is_file($file) || filesize($file) === 0) {
            return [];
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_file($file);
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            return [];
        }

        // <testsuites> wraps one or more <testsuite>; sum the top level ones.
        $suites = $xml->getName() === 'testsuite' ? [$xml] : iterator_to_array($xml->testsuite, false);

        $totals = ['tests' => 0, 'assertions' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0];

        foreach ($suites as $suite) {
            foreach ($totals as $key => $value) {
                $totals[$key] = $value + (int) ($suite[$key] ?? 0);
            }
        }

        $failed = [];

        foreach ($xml->xpath('//testcase[failure or error]') ?: [] as $case) {
            $failed[] = trim(($case['class'] ?? $case['file'] ?? '').'::'.($case['name'] ?? ''), ':');

            if (count($failed) >= 20) {
                break;
            }
        }

        return $totals + ['failed_tests' => $failed];
    }

    /**
     * @param  list<string>|null  $command
     * @return array<string, mixed>
     */
    private function failure(string $message, ?array $command = null): array
    {
        return [
            'test_pass' => false,
            'exit_code' => null,
            'command' => $command ? implode(' ', $command) : null,
            'output' => $message,
        ];
    }

    private function tail(string $output): string
    {
        $output = trim($output);

        if ($this->outputLimit > 0 && strlen($output) > $this->outputLimit) {
            return '…'.substr($output, -$this->outputLimit);
        }

        return $output;
    }
}
