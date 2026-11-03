<?php

namespace AICodingGuard;

use AICodingGuard\Appliers\GitPatchApplier;
use AICodingGuard\Console\MemoryCommand;
use AICodingGuard\Console\PromptCommand;
use AICodingGuard\Console\RollbackCommand;
use AICodingGuard\Console\RunCommand;
use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\Support\PathGuard;
use AICodingGuard\TestRunners\CommandTestRunner;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AiCodingGuardServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/ai-coding-guard.php', 'ai-coding-guard');

        $this->app->bindIf(CodeApplierInterface::class, fn (Application $app) => new GitPatchApplier(
            basePath: $app->basePath(),
            patchPath: $this->storagePath($app).'/last.patch',
            requireCleanTree: (bool) $this->config($app, 'git.require_clean_tree', true),
            storagePath: $this->storagePath($app),
        ));

        $this->app->bindIf(TestRunnerInterface::class, fn (Application $app) => new CommandTestRunner(
            basePath: $app->basePath(),
            command: $this->config($app, 'tests.command'),
            timeout: (int) $this->config($app, 'tests.timeout', 900),
            outputLimit: (int) $this->config($app, 'tests.output_limit', 4000),
            junit: (bool) $this->config($app, 'tests.junit', true),
        ));

        $this->app->bindIf(Memory::class, fn (Application $app) => new Memory(
            path: $this->config($app, 'memory.path') ?: $this->storagePath($app).'/memory.json',
            maxLessons: (int) $this->config($app, 'memory.max_lessons', 100),
        ));

        $this->app->bindIf(PathGuard::class, fn (Application $app) => new PathGuard(
            protected: (array) $this->config($app, 'paths.protected', []),
            allowed: (array) $this->config($app, 'paths.allowed', []),
        ));

        $this->app->bindIf(ManifestValidator::class, fn (Application $app) => new ManifestValidator(
            measurableMetrics: $app->make(TestRunnerInterface::class)->metrics(),
            requiredMetrics: (array) $this->config($app, 'manifest.required_metrics', ['test_pass']),
            minLength: (int) $this->config($app, 'manifest.min_length', 15),
        ));

        // Not a singleton: commands may rebind the applier / test runner.
        $this->app->bindIf(Runner::class, fn (Application $app) => new Runner(
            applier: $app->make(CodeApplierInterface::class),
            tester: $app->make(TestRunnerInterface::class),
            validator: $app->make(ManifestValidator::class),
            paths: $app->make(PathGuard::class),
            memory: $app->make(Memory::class),
            confirmRiskLevels: array_map('strtolower', (array) $this->config($app, 'confirm_risk_levels', ['high'])),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/ai-coding-guard.php' => $this->app->configPath('ai-coding-guard.php'),
            ], 'ai-coding-guard-config');

            $this->commands([
                RunCommand::class,
                PromptCommand::class,
                MemoryCommand::class,
                RollbackCommand::class,
            ]);
        }
    }

    private function storagePath(Application $app): string
    {
        return rtrim($this->config($app, 'storage_path') ?: $app->storagePath('ai-coding-guard'), '/\\');
    }

    private function config(Application $app, string $key, mixed $default = null): mixed
    {
        return $app['config']->get("ai-coding-guard.{$key}", $default);
    }
}
