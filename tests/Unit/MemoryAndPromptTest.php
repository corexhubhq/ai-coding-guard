<?php

namespace AICodingGuard\Tests\Unit;

use AICodingGuard\Memory;
use AICodingGuard\SystemPrompt;
use PHPUnit\Framework\TestCase;

class MemoryAndPromptTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ai-guard-memory-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        @unlink($this->dir.'/memory.json');
        @unlink($this->dir.'/.gitignore');
        @rmdir($this->dir);
    }

    public function test_memory_stores_trims_and_clears_lessons(): void
    {
        $memory = new Memory($this->dir.'/memory.json', maxLessons: 3);

        foreach (range(1, 5) as $i) {
            $memory->add(['type' => 'success', 'fix' => "fix {$i}"]);
        }

        $this->assertSame(['fix 3', 'fix 4', 'fix 5'], array_column($memory->all(), 'fix'));
        $this->assertSame(['fix 5'], array_column($memory->recent(1), 'fix'));
        $this->assertFileExists($this->dir.'/.gitignore');

        $memory->clear();
        $this->assertSame([], $memory->all());
    }

    public function test_corrupt_memory_file_is_treated_as_empty(): void
    {
        mkdir($this->dir);
        file_put_contents($this->dir.'/memory.json', '{not json');

        $this->assertSame([], (new Memory($this->dir.'/memory.json'))->all());
    }

    public function test_prompt_contains_format_metrics_paths_and_lessons(): void
    {
        $prompt = SystemPrompt::build(
            lessons: [
                ['type' => 'success', 'fix' => 'Add an index on orders.user_id', 'files' => ['database/migrations/x.php']],
                ['type' => 'fail', 'fix' => 'Cache the user list forever', 'reason' => 'failures: expected 0 (actual 3)'],
                ['type' => 'rejected', 'reason' => 'prediction.metrics.response_time cannot be measured'],
            ],
            metrics: ['test_pass', 'failures'],
            requiredMetrics: ['test_pass'],
            protectedPaths: ['tests/*'],
        );

        $this->assertStringContainsString('"CHANGE_MANIFEST"', $prompt);
        $this->assertStringContainsString('Measurable metrics: test_pass, failures', $prompt);
        $this->assertStringContainsString('Never change these paths (the change is rejected): tests/*', $prompt);
        $this->assertStringContainsString('WORKED: Add an index on orders.user_id [database/migrations/x.php]', $prompt);
        $this->assertStringContainsString('FAILED (prediction wrong, rolled back): Cache the user list forever — failures: expected 0', $prompt);
        $this->assertStringContainsString('REJECTED before applying: prediction.metrics.response_time', $prompt);
    }

    public function test_prompt_without_lessons_has_no_lessons_section(): void
    {
        $this->assertStringNotContainsString('LESSONS', SystemPrompt::build());
    }
}
