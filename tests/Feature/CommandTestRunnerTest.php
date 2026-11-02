<?php

namespace AICodingGuard\Tests\Feature;

use AICodingGuard\TestRunners\CommandTestRunner;
use AICodingGuard\Tests\TestCase;

class CommandTestRunnerTest extends TestCase
{
    /**
     * A fake test binary: writes a JUnit report like PHPUnit/Pest and exits with $exit.
     */
    private function fakeSuite(string $dir, int $failures): string
    {
        $exit = $failures > 0 ? 1 : 0;
        $failure = $failures > 0 ? '<failure>boom</failure>' : '';

        file_put_contents($dir.'/fake-tests.php', <<<PHP
<?php
\$junit = null;
foreach (\$argv as \$arg) {
    if (str_starts_with(\$arg, '--log-junit=')) { \$junit = substr(\$arg, 12); }
    if (str_starts_with(\$arg, '--filter=')) { echo 'FILTER '.substr(\$arg, 9).PHP_EOL; }
}
file_put_contents(\$junit, '<?xml version="1.0"?><testsuites><testsuite name="all" tests="3" assertions="7" errors="0" failures="{$failures}" skipped="1" time="0.1"><testcase name="test_add" class="CalculatorTest">{$failure}</testcase></testsuite></testsuites>');
echo "ran tests".PHP_EOL;
exit({$exit});
PHP);

        return $dir.'/fake-tests.php';
    }

    public function test_reports_metrics_from_junit_when_suite_passes(): void
    {
        $dir = $this->tempDir();
        $runner = new CommandTestRunner($dir, [PHP_BINARY, $this->fakeSuite($dir, 0)]);

        $result = $runner->run(['filter' => 'CalculatorTest']);

        $this->assertTrue($result['test_pass']);
        $this->assertSame(0, $result['exit_code']);
        $this->assertSame(3, $result['tests']);
        $this->assertSame(7, $result['assertions']);
        $this->assertSame(0, $result['failures']);
        $this->assertSame(1, $result['skipped']);
        $this->assertIsInt($result['duration_ms']);
        $this->assertStringContainsString('FILTER CalculatorTest', $result['output']);
    }

    public function test_failing_suite_reports_failed_tests(): void
    {
        $dir = $this->tempDir();
        $result = (new CommandTestRunner($dir, [PHP_BINARY, $this->fakeSuite($dir, 1)]))->run();

        $this->assertFalse($result['test_pass']);
        $this->assertSame(1, $result['failures']);
        $this->assertSame(['CalculatorTest::test_add'], $result['failed_tests']);
    }

    public function test_fails_closed_when_no_runner_exists(): void
    {
        $result = (new CommandTestRunner($this->tempDir()))->run();

        $this->assertFalse($result['test_pass']);
        $this->assertStringContainsString('No test runner found', $result['output']);
    }

    public function test_fails_closed_when_command_cannot_run(): void
    {
        $result = (new CommandTestRunner($this->tempDir(), ['/nonexistent/phpunit'], junit: false))->run();

        $this->assertFalse($result['test_pass']);
    }

    public function test_fails_closed_on_timeout(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/slow.php', '<?php sleep(5);');

        $result = (new CommandTestRunner($dir, [PHP_BINARY, $dir.'/slow.php'], timeout: 1, junit: false))->run();

        $this->assertFalse($result['test_pass']);
        $this->assertTrue($result['timed_out']);
    }

    public function test_output_is_truncated_to_its_tail(): void
    {
        $dir = $this->tempDir();
        file_put_contents($dir.'/noisy.php', '<?php echo str_repeat("x", 10000)."END";');

        $result = (new CommandTestRunner($dir, [PHP_BINARY, $dir.'/noisy.php'], outputLimit: 100, junit: false))->run();

        $this->assertLessThanOrEqual(110, strlen($result['output']));
        $this->assertStringEndsWith('END', $result['output']);
    }
}
