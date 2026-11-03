<?php

return [

    /*
    | Where the guard keeps its files (memory.json, last.patch). A .gitignore
    | is created inside, so these files never dirty the working tree.
    */
    'storage_path' => storage_path('ai-coding-guard'),

    'memory' => [
        // null = {storage_path}/memory.json
        'path' => null,
        // Oldest lessons are dropped beyond this number.
        'max_lessons' => 100,
        // How many recent lessons ai-coding-guard:prompt puts in the system prompt.
        'prompt_lessons' => 20,
    ],

    'git' => [
        // Refuse to apply a change while the working tree has uncommitted
        // changes (storage_path excluded), so a rollback never touches your work.
        'require_clean_tree' => true,
    ],

    'tests' => [
        // null = auto-detect: `php artisan test`, then vendor/bin/pest, then vendor/bin/phpunit.
        // Example: [PHP_BINARY, 'vendor/bin/pest', '--compact'].
        'command' => null,
        // Append --log-junit to count tests/failures (PHPUnit, Pest and artisan test support it).
        'junit' => true,
        // Seconds; 0 = no limit.
        'timeout' => 900,
        // Characters of test output kept in the result (the tail).
        'output_limit' => 4000,
    ],

    'manifest' => [
        // Minimum length of evidence / root_cause / fix / expected_result.
        'min_length' => 15,
        // Metrics every prediction must contain.
        'required_metrics' => ['test_pass'],
    ],

    'paths' => [
        // The AI may never change these (Str::is patterns, relative to base_path()).
        // Tests are the measuring instrument: editing them would let a change "pass" by itself.
        'protected' => [
            '.env',
            '.env.*',
            '.git/*',
            '.github/*',
            'tests/*',
            'phpunit.xml',
            'phpunit.xml.dist',
            'composer.json',
            'composer.lock',
            'vendor/*',
            'node_modules/*',
            'storage/*',
            'bootstrap/cache/*',
            'config/ai-coding-guard.php',
        ],
        // When not empty, every changed path must match one of these, e.g. ['app/*', 'resources/views/*'].
        'allowed' => [],
    ],

    // These risk levels need confirmation (interactive prompt or --force).
    'confirm_risk_levels' => ['high'],

];
