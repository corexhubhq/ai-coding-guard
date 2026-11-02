<?php

namespace AICodingGuard\Tests\Feature;

use AICodingGuard\Appliers\GitPatchApplier;
use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\Memory;
use AICodingGuard\Tests\FakeTestRunner;
use AICodingGuard\Tests\TestCase;

class CommandsTest extends TestCase
{
    private string $repo;

    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = $this->makeRepository();
        $this->storage = $this->repo.'/storage/ai-coding-guard';

        $this->app->instance(CodeApplierInterface::class, new GitPatchApplier($this->repo, $this->storage.'/last.patch', true, $this->storage));
        $this->app->instance(TestRunnerInterface::class, FakeTestRunner::calculator($this->repo));
        $this->app->instance(Memory::class, new Memory($this->storage.'/memory.json'));
    }

    private function responseFile(?array $manifest = null, string $diff = self::FIX_DIFF): string
    {
        $file = $this->tempDir().'/response.json';
        file_put_contents($file, json_encode($this->response($manifest, $diff)));

        return $file;
    }

    public function test_run_applies_a_correct_change(): void
    {
        $this->artisan('ai-coding-guard:run', ['file' => $this->responseFile()])
            ->expectsOutputToContain('[success]')
            ->assertSuccessful();

        $this->assertStringContainsString('return $a + $b;', file_get_contents($this->repo.'/app/Calculator.php'));
    }

    public function test_run_fails_and_rolls_back_a_wrong_change(): void
    {
        $diff = str_replace('return $a + $b;', 'return $b - $a;', self::FIX_DIFF);

        $this->artisan('ai-coding-guard:run', ['--file' => $this->responseFile(null, $diff)])
            ->expectsOutputToContain('[failed]')
            ->assertFailed();

        $this->assertSame(self::ORIGINAL, file_get_contents($this->repo.'/app/Calculator.php'));
    }

    public function test_run_json_output(): void
    {
        $this->artisan('ai-coding-guard:run', ['file' => $this->responseFile(), '--json' => true, '--dry' => true])
            ->expectsOutputToContain('"status": "checked"')
            ->assertSuccessful();
    }

    public function test_high_risk_change_is_aborted_when_declined_and_applied_with_force(): void
    {
        $file = $this->responseFile($this->manifest(['test_pass' => true], 'high'));

        $this->artisan('ai-coding-guard:run', ['file' => $file])
            ->expectsConfirmation('Apply this change?', 'no')
            ->expectsOutputToContain('[aborted]')
            ->assertFailed();

        $this->artisan('ai-coding-guard:run', ['file' => $file, '--force' => true])
            ->expectsOutputToContain('[success]')
            ->assertSuccessful();
    }

    public function test_no_test_option_skips_the_test_runner(): void
    {
        $tester = $this->app->make(TestRunnerInterface::class);

        $this->artisan('ai-coding-guard:run', ['file' => $this->responseFile(), '--no-test' => true])
            ->expectsOutputToContain('[unverified]')
            ->assertSuccessful();

        $this->assertSame(0, $tester->runs);
    }

    public function test_missing_file_fails(): void
    {
        $this->artisan('ai-coding-guard:run', ['file' => '/nope.json'])
            ->expectsOutputToContain('File not found')
            ->assertFailed();
    }

    public function test_rollback_command_undoes_the_last_change(): void
    {
        $this->artisan('ai-coding-guard:run', ['file' => $this->responseFile()])->assertSuccessful();

        $this->artisan('ai-coding-guard:rollback')->expectsOutputToContain('rolled back')->assertSuccessful();

        $this->assertSame(self::ORIGINAL, file_get_contents($this->repo.'/app/Calculator.php'));

        $this->artisan('ai-coding-guard:rollback')->expectsOutputToContain('Nothing to roll back')->assertSuccessful();
    }

    public function test_prompt_includes_lessons_from_previous_runs(): void
    {
        $this->artisan('ai-coding-guard:run', ['file' => $this->responseFile($this->manifest(['test_pass' => true, 'response_time' => 5]))])->assertFailed();

        $this->artisan('ai-coding-guard:prompt')
            ->expectsOutputToContain('REJECTED before applying: prediction.metrics.response_time cannot be measured')
            ->assertSuccessful();
    }

    public function test_memory_command_lists_and_clears(): void
    {
        $this->app->make(Memory::class)->add(['type' => 'success', 'fix' => 'Add the missing index']);

        $this->artisan('ai-coding-guard:memory')->expectsOutputToContain('Add the missing index')->assertSuccessful();
        $this->artisan('ai-coding-guard:memory', ['--clear' => true])->expectsConfirmation('Delete all lessons from '.$this->storage.'/memory.json?', 'yes')->assertSuccessful();

        $this->assertSame([], $this->app->make(Memory::class)->all());
    }

    public function test_default_bindings_resolve_from_config(): void
    {
        $this->app->forgetInstance(CodeApplierInterface::class);
        $this->app->forgetInstance(TestRunnerInterface::class);
        $this->app->forgetInstance(Memory::class);

        $this->assertInstanceOf(\AICodingGuard\Runner::class, $this->app->make(\AICodingGuard\Runner::class));
        $this->assertSame(storage_path('ai-coding-guard/memory.json'), $this->app->make(Memory::class)->path());
    }
}
