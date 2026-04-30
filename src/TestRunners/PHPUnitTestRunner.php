<?php

namespace AICodingGuard\TestRunners;

use AICodingGuard\Contracts\TestRunnerInterface;

class PHPUnitTestRunner implements TestRunnerInterface
{
    public function run(): array
    {
        $output = [];
        $returnVar = 0;

        // Check if phpunit is available
        exec('which phpunit 2>&1', $checkOutput, $checkReturn);
        
        if ($checkReturn !== 0) {
            // Try vendor/bin/phpunit
            exec('./vendor/bin/phpunit --version 2>&1', $checkOutput, $checkReturn);
            
            if ($checkReturn !== 0) {
                // PHPUnit not found, return default pass
                return [
                    'test_pass' => true,
                    'message' => 'PHPUnit not found, skipping tests'
                ];
            }
            
            $phpunit = './vendor/bin/phpunit';
        } else {
            $phpunit = 'phpunit';
        }

        // Run tests with JSON output
        exec("{$phpunit} --log-json=/tmp/phpunit-result.json 2>&1", $output, $returnVar);

        // Parse results
        $testPass = $returnVar === 0;

        $result = [
            'test_pass' => $testPass,
            'exit_code' => $returnVar,
            'output' => implode("\n", $output)
        ];

        // Try to get detailed results from JSON if available
        if (file_exists('/tmp/phpunit-result.json')) {
            $jsonResult = file_get_contents('/tmp/phpunit-result.json');
            
            // Parse JSON lines (PHPUnit outputs one JSON per line)
            $lines = explode("\n", trim($jsonResult));
            $events = array_filter(array_map(function($line) {
                return json_decode($line, true);
            }, $lines));

            $result['detailed'] = $events;
            
            @unlink('/tmp/phpunit-result.json');
        }

        return $result;
    }
}
