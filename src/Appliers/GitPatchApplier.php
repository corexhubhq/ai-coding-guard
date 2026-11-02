<?php

namespace AICodingGuard\Appliers;

use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Exceptions\EnvironmentException;
use AICodingGuard\Exceptions\PatchRejectedException;
use AICodingGuard\Support\GuardStorage;
use Symfony\Component\Process\Process;

/**
 * Applies diffs with `git apply` and undoes them with `git apply -R`.
 *
 * The applied patch is kept at $patchPath, so a rollback reverses exactly the
 * hunks that were applied (including created and deleted files), and nothing
 * else in the working tree.
 */
class GitPatchApplier implements CodeApplierInterface
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $patchPath,
        private readonly bool $requireCleanTree = true,
        private readonly ?string $storagePath = null,
    ) {
    }

    public function check(string $diff): void
    {
        $this->ensureRepository();

        if ($this->requireCleanTree) {
            $this->ensureCleanTree();
        }

        $file = $this->temporaryPatch($diff);

        try {
            $result = $this->git(['apply', '--check', '--whitespace=nowarn', $file]);
        } finally {
            @unlink($file);
        }

        if (! $result->isSuccessful()) {
            throw new PatchRejectedException('CODE_DIFF does not apply cleanly: '.$this->output($result));
        }
    }

    public function apply(string $diff): void
    {
        $this->ensureRepository();

        GuardStorage::write($this->patchPath, $diff);

        $result = $this->git(['apply', '--whitespace=nowarn', $this->patchPath]);

        if (! $result->isSuccessful()) {
            @unlink($this->patchPath);

            throw new PatchRejectedException('Failed to apply CODE_DIFF: '.$this->output($result));
        }
    }

    public function rollback(): void
    {
        if (! $this->canRollback()) {
            throw new EnvironmentException('Nothing to roll back: no applied patch found at '.$this->patchPath);
        }

        $result = $this->git(['apply', '-R', '--whitespace=nowarn', $this->patchPath]);

        if (! $result->isSuccessful()) {
            throw new EnvironmentException(
                'Rollback failed, files were changed after the patch was applied. '
                .'The patch is kept at '.$this->patchPath.' — revert it manually (git apply -R). Git said: '.$this->output($result)
            );
        }

        @unlink($this->patchPath);
    }

    public function canRollback(): bool
    {
        return is_file($this->patchPath);
    }

    private function ensureRepository(): void
    {
        $result = $this->git(['rev-parse', '--is-inside-work-tree']);

        if (! $result->isSuccessful() || trim($result->getOutput()) !== 'true') {
            throw new EnvironmentException("{$this->basePath} is not inside a git working tree; the guard needs git to apply and roll back changes safely.");
        }
    }

    private function ensureCleanTree(): void
    {
        $command = ['status', '--porcelain', '--untracked-files=all', '--', '.'];

        if ($exclude = $this->relativeStoragePath()) {
            $command[] = ":(exclude){$exclude}";
        }

        $result = $this->git($command);

        if (! $result->isSuccessful()) {
            throw new EnvironmentException('git status failed: '.$this->output($result));
        }

        $dirty = trim($result->getOutput());

        if ($dirty !== '') {
            $files = implode(', ', array_slice(array_map(fn ($l) => trim(substr($l, 3)), explode("\n", $dirty)), 0, 10));

            throw new EnvironmentException("Working tree is not clean ({$files}). Commit or stash your changes first, so a failed AI change can be rolled back without touching your work.");
        }
    }

    private function relativeStoragePath(): ?string
    {
        if ($this->storagePath === null) {
            return null;
        }

        $base = rtrim(str_replace('\\', '/', realpath($this->basePath) ?: $this->basePath), '/').'/';
        $storage = str_replace('\\', '/', realpath($this->storagePath) ?: $this->storagePath);

        return str_starts_with($storage.'/', $base) ? rtrim(substr($storage, strlen($base)), '/') : null;
    }

    private function temporaryPatch(string $diff): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ai-guard-');
        file_put_contents($file, $diff);

        return $file;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(array $arguments): Process
    {
        $process = new Process(['git', ...$arguments], $this->basePath);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }

    private function output(Process $process): string
    {
        return trim($process->getErrorOutput()."\n".$process->getOutput());
    }
}
