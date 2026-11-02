<?php

namespace AICodingGuard\Console;

use AICodingGuard\Contracts\CodeApplierInterface;
use Illuminate\Console\Command;
use Throwable;

class RollbackCommand extends Command
{
    protected $signature = 'ai-coding-guard:rollback';

    protected $description = 'Undo the last change applied by ai-coding-guard:run';

    public function handle(CodeApplierInterface $applier): int
    {
        if (! $applier->canRollback()) {
            $this->components->warn('Nothing to roll back.');

            return self::SUCCESS;
        }

        try {
            $applier->rollback();
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info('Last AI change rolled back.');

        return self::SUCCESS;
    }
}
