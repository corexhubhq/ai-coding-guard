<?php

namespace AICodingGuard\Appliers;

use AICodingGuard\Contracts\CodeApplierInterface;
use Illuminate\Support\Facades\File;

class GitPatchApplier implements CodeApplierInterface
{
    protected $backupPath;

    public function __construct()
    {
        $this->backupPath = config('ai-coding-guard.backup_path');
    }

    public function apply(string $diff): void
    {
        // Backup current state
        $this->createBackup();

        // Create temporary patch file
        $patchFile = storage_path('ai-coding-guard/temp.patch');
        
        $this->ensureDirectory($patchFile);
        
        file_put_contents($patchFile, $diff);

        // Apply patch
        $output = [];
        $returnVar = 0;
        
        exec("git apply {$patchFile} 2>&1", $output, $returnVar);

        // Clean up
        @unlink($patchFile);

        if ($returnVar !== 0) {
            throw new \Exception("Failed to apply patch: " . implode("\n", $output));
        }
    }

    public function rollback(): void
    {
        if (!file_exists($this->backupPath)) {
            throw new \Exception("No backup found to rollback");
        }

        $backup = json_decode(file_get_contents($this->backupPath), true);

        if (!$backup || !isset($backup['files'])) {
            throw new \Exception("Invalid backup format");
        }

        foreach ($backup['files'] as $file => $content) {
            if ($content === null) {
                // File was created, delete it
                if (file_exists($file)) {
                    unlink($file);
                }
            } else {
                // Restore original content
                $this->ensureDirectory($file);
                file_put_contents($file, $content);
            }
        }
    }

    protected function createBackup(): void
    {
        // Get changed files from git status
        exec('git diff --name-only 2>&1', $files, $returnVar);

        if ($returnVar !== 0) {
            // If not a git repo, skip backup for now
            // or implement file-based backup
            return;
        }

        $backup = ['files' => [], 'created_at' => date('Y-m-d H:i:s')];

        foreach ($files as $file) {
            $fullPath = base_path($file);
            
            if (file_exists($fullPath)) {
                $backup['files'][$fullPath] = file_get_contents($fullPath);
            } else {
                $backup['files'][$fullPath] = null; // File will be created
            }
        }

        $this->ensureDirectory($this->backupPath);
        
        file_put_contents(
            $this->backupPath,
            json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    protected function ensureDirectory(string $file): void
    {
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}
