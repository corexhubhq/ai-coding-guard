<?php

namespace AICodingGuard\Console;

use Illuminate\Console\Command;
use AICodingGuard\Runner;

class AiCodingGuardRunCommand extends Command
{
    protected $signature = 'ai-coding-guard:run 
                            {--file= : Path to AI response JSON}
                            {--dry : Do not apply changes}
                            {--no-test : Skip test execution}';

    protected $description = 'Run AI coding task with guardrails';

    public function handle(Runner $runner)
    {
        try {
            $aiResponse = $this->loadInput();

            if ($this->option('dry')) {
                $this->info("DRY RUN MODE");
                $this->line(json_encode($aiResponse, JSON_PRETTY_PRINT));
                return 0;
            }

            if ($this->option('no-test')) {
                $this->warn("Skipping test execution");
                // override tester runtime (light hack for MVP)
                app()->bind(\AICodingGuard\Contracts\TestRunnerInterface::class, function () {
                    return new class implements \AICodingGuard\Contracts\TestRunnerInterface {
                        public function run(): array {
                            return ['test_pass' => true];
                        }
                    };
                });
            }

            $this->info("Running AI Coding Guard...");

            $runner->handle($aiResponse);

            $this->info("✅ AI Coding Guard change applied successfully");

            return 0;

        } catch (\Throwable $e) {
            $this->error("❌ Failed: " . $e->getMessage());
            return 1;
        }
    }

    protected function loadInput(): array
    {
        $file = $this->option('file');

        if ($file) {
            if (!file_exists($file)) {
                throw new \Exception("File not found: {$file}");
            }

            return json_decode(file_get_contents($file), true);
        }

        // fallback: read from stdin
        $input = stream_get_contents(STDIN);

        if (!$input) {
            throw new \Exception("No input provided");
        }

        return json_decode($input, true);
    }
}