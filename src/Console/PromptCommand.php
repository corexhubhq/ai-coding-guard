<?php

namespace AICodingGuard\Console;

use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\ManifestValidator;
use AICodingGuard\Memory;
use AICodingGuard\Support\PathGuard;
use AICodingGuard\SystemPrompt;
use Illuminate\Console\Command;

class PromptCommand extends Command
{
    protected $signature = 'ai-coding-guard:prompt
                            {--lessons= : Number of recent lessons to include (default: config memory.prompt_lessons)}
                            {--output= : Write the prompt to this file instead of STDOUT}';

    protected $description = 'Print the system prompt for the AI agent, including lessons from memory';

    public function handle(Memory $memory, TestRunnerInterface $tester, ManifestValidator $validator, PathGuard $paths): int
    {
        $limit = $this->option('lessons') ?? config('ai-coding-guard.memory.prompt_lessons', 20);

        $prompt = SystemPrompt::build(
            lessons: $memory->recent((int) $limit),
            metrics: $tester->metrics(),
            requiredMetrics: $validator->requiredMetrics(),
            protectedPaths: $paths->protectedPatterns(),
            allowedPaths: $paths->allowedPatterns(),
        );

        if ($file = $this->option('output')) {
            file_put_contents($file, $prompt);
            $this->components->info("System prompt written to {$file}");

            return self::SUCCESS;
        }

        $this->output->write($prompt, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }
}
