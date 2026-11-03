<?php

namespace AICodingGuard\Console;

use AICodingGuard\Memory;
use Illuminate\Console\Command;

class MemoryCommand extends Command
{
    protected $signature = 'ai-coding-guard:memory
                            {--limit=20 : Number of recent lessons to show}
                            {--type= : Only show lessons of this type (success, fail, rejected, error)}
                            {--clear : Delete all lessons}';

    protected $description = 'Show (or clear) the lessons the guard has learned';

    public function handle(Memory $memory): int
    {
        if ($this->option('clear')) {
            if (! $this->components->confirm('Delete all lessons from '.$memory->path().'?', true)) {
                return self::SUCCESS;
            }

            $memory->clear();
            $this->components->info('Memory cleared.');

            return self::SUCCESS;
        }

        $lessons = $memory->all();

        if ($type = $this->option('type')) {
            $lessons = array_values(array_filter($lessons, fn ($l) => ($l['type'] ?? null) === $type));
        }

        $lessons = array_slice($lessons, -max(1, (int) $this->option('limit')));

        if ($lessons === []) {
            $this->components->info('No lessons yet.');

            return self::SUCCESS;
        }

        $this->table(['When', 'Type', 'Fix', 'Reason / files'], array_map(fn (array $l) => [
            $l['created_at'] ?? '',
            $l['type'] ?? '',
            mb_strimwidth((string) ($l['fix'] ?? '-'), 0, 60, '…'),
            mb_strimwidth((string) ($l['reason'] ?? implode(', ', (array) ($l['files'] ?? []))), 0, 70, '…'),
        ], $lessons));

        return self::SUCCESS;
    }
}
