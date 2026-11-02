<?php

namespace AICodingGuard\Tests\Unit;

use AICodingGuard\Exceptions\PatchRejectedException;
use AICodingGuard\Support\DiffParser;
use AICodingGuard\Support\PathGuard;
use PHPUnit\Framework\TestCase;

class DiffParserAndPathGuardTest extends TestCase
{
    public function test_extracts_files_from_git_and_unified_diffs(): void
    {
        $diff = <<<'DIFF'
diff --git a/app/Old.php b/app/New.php
similarity index 90%
rename from app/Old.php
rename to app/New.php
--- a/app/Old.php
+++ b/app/New.php
@@ -1 +1 @@
-a
+b
--- /dev/null
+++ b/app/Created.php	2026-01-01 10:00:00
@@ -0,0 +1 @@
+x
--- a/app/Deleted.php
+++ /dev/null
@@ -1 +0,0 @@
-y
DIFF;

        $this->assertSame(['app/Old.php', 'app/New.php', 'app/Created.php', 'app/Deleted.php'], DiffParser::files($diff));
    }

    public function test_diff_without_files_is_rejected(): void
    {
        $this->expectException(PatchRejectedException::class);

        DiffParser::files('@@ ...');
    }

    public function test_protected_and_escaping_paths_are_rejected(): void
    {
        $guard = new PathGuard(['tests/*', '.env*', '.github/*']);

        $this->assertNull($guard->violation('app/Models/User.php'));
        $this->assertNotNull($guard->violation('tests/Feature/Filament/UserResourceTest.php'));
        $this->assertNotNull($guard->violation('.env'));
        $this->assertNotNull($guard->violation('.github/workflows/dev-deploy.yml'));
        $this->assertNotNull($guard->violation('../outside.php'));
        $this->assertNotNull($guard->violation('/etc/passwd'));

        $this->expectException(PatchRejectedException::class);
        $this->expectExceptionMessage('tests/Unit/X.php (protected by "tests/*")');

        $guard->check(['app/A.php', 'tests/Unit/X.php']);
    }

    public function test_allowed_paths_restrict_changes(): void
    {
        $guard = new PathGuard([], ['app/*', 'resources/views/*']);

        $this->assertNull($guard->violation('app/Filament/Resources/UserResource.php'));
        $this->assertSame('not in allowed paths', $guard->violation('routes/web.php'));
    }
}
