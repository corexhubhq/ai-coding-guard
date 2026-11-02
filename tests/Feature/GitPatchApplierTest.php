<?php

namespace AICodingGuard\Tests\Feature;

use AICodingGuard\Appliers\GitPatchApplier;
use AICodingGuard\Exceptions\EnvironmentException;
use AICodingGuard\Exceptions\PatchRejectedException;
use AICodingGuard\Tests\TestCase;

class GitPatchApplierTest extends TestCase
{
    private function applier(string $repo, bool $clean = true): GitPatchApplier
    {
        return new GitPatchApplier($repo, $repo.'/storage/ai-coding-guard/last.patch', $clean, $repo.'/storage/ai-coding-guard');
    }

    public function test_apply_then_rollback_restores_modified_and_created_files(): void
    {
        $repo = $this->makeRepository();
        $applier = $this->applier($repo);

        $applier->check(self::FIX_DIFF.self::NEW_FILE_DIFF);
        $applier->apply(self::FIX_DIFF.self::NEW_FILE_DIFF);

        $this->assertStringContainsString('return $a + $b;', file_get_contents($repo.'/app/Calculator.php'));
        $this->assertFileExists($repo.'/app/Helper.php');
        $this->assertTrue($applier->canRollback());

        $applier->rollback();

        $this->assertSame(self::ORIGINAL, file_get_contents($repo.'/app/Calculator.php'));
        $this->assertFileDoesNotExist($repo.'/app/Helper.php');
        $this->assertFalse($applier->canRollback());
    }

    public function test_patch_that_does_not_apply_is_rejected_without_changes(): void
    {
        $repo = $this->makeRepository();
        $bad = str_replace('return $a - $b;', 'return $a * $b;', self::FIX_DIFF);

        try {
            $this->applier($repo)->check($bad);
            $this->fail('Expected PatchRejectedException');
        } catch (PatchRejectedException $e) {
            $this->assertStringContainsString('does not apply cleanly', $e->getMessage());
        }

        $this->assertSame(self::ORIGINAL, file_get_contents($repo.'/app/Calculator.php'));
    }

    public function test_dirty_tree_is_refused_but_guard_storage_is_ignored(): void
    {
        $repo = $this->makeRepository();
        $applier = $this->applier($repo);

        // Files the guard writes itself must not count as "dirty".
        mkdir($repo.'/storage/ai-coding-guard', 0755, true);
        file_put_contents($repo.'/storage/ai-coding-guard/memory.json', '{}');
        $applier->check(self::FIX_DIFF);

        file_put_contents($repo.'/notes.txt', 'my work in progress');

        $this->expectException(EnvironmentException::class);
        $this->expectExceptionMessage('Working tree is not clean (notes.txt)');

        $applier->check(self::FIX_DIFF);
    }

    public function test_dirty_tree_is_allowed_when_disabled(): void
    {
        $repo = $this->makeRepository();
        file_put_contents($repo.'/notes.txt', 'wip');

        $this->applier($repo, clean: false)->check(self::FIX_DIFF);
        $this->addToAssertionCount(1);
    }

    public function test_requires_a_git_repository(): void
    {
        $this->expectException(EnvironmentException::class);
        $this->expectExceptionMessage('not inside a git working tree');

        $this->applier($this->tempDir())->check(self::FIX_DIFF);
    }

    public function test_rollback_without_applied_patch_fails(): void
    {
        $this->expectException(EnvironmentException::class);

        $this->applier($this->makeRepository())->rollback();
    }
}
