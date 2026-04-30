<?php

namespace AICodingGuard;

use AICodingGuard\Appliers\GitPatchApplier;
use AICodingGuard\Console\AiCodingGuardRunCommand;
use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\TestRunners\PHPUnitTestRunner;
use Illuminate\Support\ServiceProvider;

class AiCodingGuardServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-coding-guard.php', 'ai-coding-guard');

        // Bind default implementations
        $this->app->bind(CodeApplierInterface::class, function ($app) {
            return new GitPatchApplier();
        });

        $this->app->bind(TestRunnerInterface::class, function ($app) {
            return new PHPUnitTestRunner();
        });

        // Bind Runner as singleton
        $this->app->singleton(Runner::class, function ($app) {
            return new Runner(
                $app->make(CodeApplierInterface::class),
                $app->make(TestRunnerInterface::class)
            );
        });
    }

    public function boot()
    {
        $this->publishes([
            __DIR__.'/../config/ai-coding-guard.php' => config_path('ai-coding-guard.php'),
        ], 'ai-coding-guard-config');

        // Tạo storage directories nếu config bật auto_create_storage
        if (config('ai-coding-guard.auto_create_storage', true)) {
            $this->ensureStorageDirectories();
        }

        if ($this->app->runningInConsole()) {
            $this->commands([
                AiCodingGuardRunCommand::class,
            ]);
        }
    }

    protected function ensureStorageDirectories(): void
    {
        $directories = [
            dirname(config('ai-coding-guard.memory_path')),
            dirname(config('ai-coding-guard.backup_path')),
        ];

        foreach ($directories as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}
