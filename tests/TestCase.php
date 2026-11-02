<?php

namespace AICodingGuard\Tests;

use AICodingGuard\AiCodingGuardServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Symfony\Component\Process\Process;

abstract class TestCase extends Orchestra
{
    /** @var list<string> */
    private array $tempDirs = [];

    protected function getPackageProviders($app): array
    {
        return [AiCodingGuardServiceProvider::class];
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            (new Process(['rm', '-rf', $dir]))->run();
        }

        parent::tearDown();
    }

    protected function tempDir(): string
    {
        $dir = sys_get_temp_dir().'/ai-guard-test-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);

        return $this->tempDirs[] = realpath($dir);
    }

    /**
     * A git repository with app/Calculator.php committed.
     */
    protected function makeRepository(): string
    {
        $dir = $this->tempDir();
        mkdir($dir.'/app');
        file_put_contents($dir.'/app/Calculator.php', self::ORIGINAL);

        foreach ([
            ['git', 'init', '-q'],
            ['git', 'config', 'user.email', 'test@example.com'],
            ['git', 'config', 'user.name', 'Test'],
            ['git', 'config', 'commit.gpgsign', 'false'],
            ['git', 'add', '.'],
            ['git', 'commit', '-qm', 'init'],
        ] as $command) {
            (new Process($command, $dir))->mustRun();
        }

        return $dir;
    }

    protected const ORIGINAL = <<<'PHP'
<?php

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a - $b;
    }
}

PHP;

    protected const FIX_DIFF = <<<'DIFF'
--- a/app/Calculator.php
+++ b/app/Calculator.php
@@ -4,6 +4,6 @@ class Calculator
 {
     public function add(int $a, int $b): int
     {
-        return $a - $b;
+        return $a + $b;
     }
 }

DIFF;

    protected const NEW_FILE_DIFF = <<<'DIFF'
--- /dev/null
+++ b/app/Helper.php
@@ -0,0 +1,3 @@
+<?php
+
+function helper() {}

DIFF;

    /**
     * @param  array<string, mixed>  $metrics
     * @return array<string, mixed>
     */
    protected function manifest(array $metrics = ['test_pass' => true], string $risk = 'low'): array
    {
        return [
            'evidence' => 'CalculatorTest::test_add fails: expected 5, got -1',
            'root_cause' => 'Calculator::add subtracts $b instead of adding it',
            'fix' => 'Replace the minus operator with a plus in Calculator::add',
            'prediction' => [
                'expected_result' => 'CalculatorTest passes and no other test changes',
                'risk_level' => $risk,
                'metrics' => $metrics,
            ],
        ];
    }

    /**
     * @return array{CHANGE_MANIFEST: array<string, mixed>, CODE_DIFF: string}
     */
    protected function response(?array $manifest = null, string $diff = self::FIX_DIFF): array
    {
        return ['CHANGE_MANIFEST' => $manifest ?? $this->manifest(), 'CODE_DIFF' => $diff];
    }
}
