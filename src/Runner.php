<?php

namespace AICodingGuard;

use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\Evaluator;
use AICodingGuard\Memory;
use AICodingGuard\Middleware\ManifestValidator;

class Runner
{
    protected $applier;
    protected $tester;

    public function __construct(
        CodeApplierInterface $applier,
        TestRunnerInterface $tester
    ) {
        $this->applier = $applier;
        $this->tester = $tester;
    }

    public function handle(array $aiResponse): void
    {
        $manifest = $aiResponse['CHANGE_MANIFEST'] ?? null;
        $diff = $aiResponse['CODE_DIFF'] ?? null;

        if (!$manifest || !$diff) {
            throw new \Exception("Invalid AI response format");
        }

        // 1. Validate manifest
        ManifestValidator::validate($manifest);

        // 2. Backup
        $this->backup($aiResponse);

        try {
            // 3. Apply code
            $this->applier->apply($diff);

            // 4. Run test
            $actual = $this->tester->run();

            // 5. Evaluate
            $isPass = Evaluator::evaluate(
                $manifest['prediction'],
                $actual
            );

            if (!$isPass) {
                $this->fail($manifest, $actual);
                return;
            }

            $this->success($manifest);
        } catch (\Throwable $e) {
            $this->applier->rollback();

            Memory::add([
                'type' => 'exception',
                'message' => $e->getMessage(),
                'manifest' => $manifest
            ]);

            throw $e;
        }
    }

    protected function fail(array $manifest, array $actual): void
    {
        $this->applier->rollback();

        Memory::add([
            'type' => 'fail',
            'manifest' => $manifest,
            'actual' => $actual
        ]);

        throw new \Exception("AI change failed → rolled back");
    }

    protected function success(array $manifest): void
    {
        Memory::add([
            'type' => 'success',
            'manifest' => $manifest
        ]);
    }

    protected function backup(array $data): void
    {
        $file = config('ai-coding-guard.backup_path');

        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }
}