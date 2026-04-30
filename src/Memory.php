<?php

namespace AICodingGuard;

class Memory
{
    public static function add(array $lesson): void
    {
        $file = config('ai-coding-guard.memory_path');

        self::ensureDirectory($file);

        $data = self::read($file);

        $data['lessons'][] = array_merge($lesson, [
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        file_put_contents(
            $file,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    protected static function read(string $file): array
    {
        if (!file_exists($file)) {
            return ['lessons' => []];
        }

        $content = file_get_contents($file);

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            return ['lessons' => []];
        }

        if (!isset($decoded['lessons'])) {
            $decoded['lessons'] = [];
        }

        return $decoded;
    }

    protected static function ensureDirectory(string $file): void
    {
        $dir = dirname($file);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }
}