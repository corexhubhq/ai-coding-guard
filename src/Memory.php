<?php

namespace AICodingGuard;

use AICodingGuard\Support\GuardStorage;

/**
 * Long-term memory of what worked and what did not, fed back into the
 * system prompt so the agent does not repeat the same mistakes.
 *
 * Stored as JSON: {"lessons": [{"type": "success|fail|rejected|error", ...}]}
 */
class Memory
{
    public const TYPES = ['success', 'fail', 'rejected', 'error'];

    public function __construct(
        private readonly string $path,
        private readonly int $maxLessons = 100,
    ) {
    }

    /**
     * @param  array<string, mixed>  $lesson
     */
    public function add(array $lesson): void
    {
        $lessons = $this->all();
        $lessons[] = ['created_at' => date(DATE_ATOM)] + $lesson;

        if ($this->maxLessons > 0) {
            $lessons = array_slice($lessons, -$this->maxLessons);
        }

        $this->save($lessons);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($this->path), true);
        $lessons = is_array($decoded) ? ($decoded['lessons'] ?? []) : [];

        return is_array($lessons) ? array_values(array_filter($lessons, 'is_array')) : [];
    }

    /**
     * @return list<array<string, mixed>> the most recent $limit lessons, oldest first
     */
    public function recent(int $limit): array
    {
        return $limit > 0 ? array_slice($this->all(), -$limit) : [];
    }

    public function clear(): void
    {
        $this->save([]);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     */
    private function save(array $lessons): void
    {
        GuardStorage::write(
            $this->path,
            json_encode(['lessons' => array_values($lessons)], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)
        );
    }
}
