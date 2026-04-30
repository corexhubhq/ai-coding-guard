<?php

return [
    'memory_path' => storage_path('ai-coding-guard/memory.json'),
    'backup_path' => storage_path('ai-coding-guard/last_changes.json'),

    'rollback_strategy' => 'patch', // patch | git | file

    'auto_create_storage' => true,
];