<?php

namespace AICodingGuard\Tests\Feature;

use AICodingGuard\Appliers\GitPatchApplier;
use AICodingGuard\ManifestValidator;
use AICodingGuard\Memory;
use AICodingGuard\Runner;
use AICodingGuard\RunResult;
use AICodingGuard\Support\PathGuard;
use AICodingGuard\Tests\FakeTestRunner;
use AICodingGuard\Tests\TestCase;
use AICodingGuard\TestRunners\CommandTestRunner;

class RunnerTest extends TestCase
{
    private string $repo;

    private FakeTestRunner $tester;

    private Memory $memory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->makeRepository();
        $this->tester = FakeTestRunner::calculator($this->repo);
        $this->memory = new Memory($this->repo.'/storage/ai-coding-guard/memory.json');
    }

    private function runner(): Runner
    {
        $storage = $this->repo.'/storage/ai-coding-guard';

        return new Runner(
            new GitPatchApplier($this->repo, $storage.'/last.patch', true, $storage),
            $this->tester,
            new ManifestValidator(CommandTestRunner::METRICS),
            new PathGuard(['tests/*', '.env*', 'storage/*']),
            $this->memory,
        );
    }

    private function calculator(): string
    {
        return file_get_contents($this->repo.'/app/Calculator.php');
    }

    public function test_correct_prediction_keeps_the_change_and_records_success(): void
    {
        $result = $this->runner()->handle($this->response($this->manifest(['test_pass' => true, 'failures' => 0])));

        $this->assertSame(RunResult::SUCCESS, $result->status, $result->message);
        $this->assertStringContainsString('return $a + $b;', $this->calculator());
        $this->assertSame(['app/Calculator.php'], $result->files);

        $lesson = $this->memory->all()[0];
        $this->assertSame('success', $lesson['type']);
        $this->assertSame(['test_pass' => true, 'failures' => 0], $lesson['actual']);
        $this->assertArrayNotHasKey('output', $lesson);
    }

    public function test_wrong_prediction_rolls_back_and_records_failure(): void
    {
        // The diff "fixes" something else; tests keep failing.
        $diff = str_replace('return $a + $b;', 'return $b - $a;', self::FIX_DIFF);

        $result = $this->runner()->handle($this->response(null, $diff));

        $this->assertSame(RunResult::FAILED, $result->status);
        $this->assertTrue($result->rolledBack);
        $this->assertSame(self::ORIGINAL, $this->calculator());

        $lesson = $this->memory->all()[0];
        $this->assertSame('fail', $lesson['type']);
        $this->assertStringContainsString('test_pass: expected true', $lesson['reason']);
        $this->assertSame(['CalculatorTest::test_add'], $lesson['failed_tests']);
    }

    public function test_over_optimistic_metric_also_rolls_back(): void
    {
        $result = $this->runner()->handle($this->response($this->manifest(['test_pass' => true, 'duration_ms' => ['max' => 100]])));

        $this->assertSame(RunResult::FAILED, $result->status);
        $this->assertSame(self::ORIGINAL, $this->calculator());
        $this->assertStringContainsString('duration_ms: above max 100', $result->message);
    }

    public function test_unmeasurable_prediction_is_rejected_before_applying(): void
    {
        $result = $this->runner()->handle($this->response($this->manifest(['test_pass' => true, 'response_time' => 500])));

        $this->assertSame(RunResult::REJECTED, $result->status);
        $this->assertSame(0, $this->tester->runs);
        $this->assertSame(self::ORIGINAL, $this->calculator());
        $this->assertSame('rejected', $this->memory->all()[0]['type']);
    }

    public function test_change_to_tests_is_rejected(): void
    {
        $diff = "--- /dev/null\n+++ b/tests/CalculatorTest.php\n@@ -0,0 +1 @@\n+<?php // always pass\n";

        $result = $this->runner()->handle($this->response(null, $diff));

        $this->assertSame(RunResult::REJECTED, $result->status);
        $this->assertStringContainsString('protected by "tests/*"', $result->message);
        $this->assertFileDoesNotExist($this->repo.'/tests/CalculatorTest.php');
    }

    public function test_response_without_manifest_is_rejected(): void
    {
        $result = $this->runner()->handle(['CODE_DIFF' => self::FIX_DIFF]);

        $this->assertSame(RunResult::REJECTED, $result->status);
        $this->assertStringContainsString('No manifest, no change', $result->message);
    }

    public function test_raw_text_response_is_parsed(): void
    {
        $raw = 'ANALYSIS: minus instead of plus.'."\nCHANGE_MANIFEST:\n".json_encode($this->manifest())."\nCODE_DIFF:\n```diff\n".self::FIX_DIFF.'```';

        $this->assertSame(RunResult::SUCCESS, $this->runner()->handle($raw)->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $result = $this->runner()->handle($this->response(), ['dry' => true]);

        $this->assertSame(RunResult::CHECKED, $result->status);
        $this->assertSame(self::ORIGINAL, $this->calculator());
        $this->assertSame(0, $this->tester->runs);
        $this->assertSame([], $this->memory->all());
    }

    public function test_dirty_tree_aborts_and_is_not_a_lesson(): void
    {
        file_put_contents($this->repo.'/app/Wip.php', '<?php // mine');

        $result = $this->runner()->handle($this->response());

        $this->assertSame(RunResult::ABORTED, $result->status);
        $this->assertSame(self::ORIGINAL, $this->calculator());
        $this->assertSame([], $this->memory->all());
    }

    public function test_high_risk_needs_confirmation(): void
    {
        $response = $this->response($this->manifest(['test_pass' => true], 'high'));

        $aborted = $this->runner()->handle($response);
        $this->assertSame(RunResult::ABORTED, $aborted->status);
        $this->assertSame(self::ORIGINAL, $this->calculator());

        $approved = $this->runner()->handle($response, ['confirm' => fn () => true]);
        $this->assertSame(RunResult::SUCCESS, $approved->status);
    }

    public function test_skip_tests_applies_without_evaluating(): void
    {
        $result = $this->runner()->handle($this->response(), ['skip_tests' => true]);

        $this->assertSame(RunResult::UNVERIFIED, $result->status);
        $this->assertSame(0, $this->tester->runs);
        $this->assertStringContainsString('return $a + $b;', $this->calculator());
        $this->assertSame([], $this->memory->all());
    }

    public function test_test_runner_crash_rolls_back_and_records_error(): void
    {
        $this->tester = new FakeTestRunner(fn () => throw new \RuntimeException('database unavailable'));

        $result = $this->runner()->handle($this->response());

        $this->assertSame(RunResult::ERROR, $result->status);
        $this->assertTrue($result->rolledBack);
        $this->assertSame(self::ORIGINAL, $this->calculator());
        $this->assertSame('error', $this->memory->all()[0]['type']);
    }

    public function test_filter_is_passed_to_the_test_runner(): void
    {
        $this->runner()->handle($this->response(), ['filter' => 'CalculatorTest']);

        $this->assertSame('CalculatorTest', $this->tester->lastOptions['filter']);
    }
}
