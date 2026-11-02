<?php

namespace AICodingGuard\Tests\Unit;

use AICodingGuard\Exceptions\ManifestRejectedException;
use AICodingGuard\ManifestValidator;
use AICodingGuard\TestRunners\CommandTestRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ManifestValidatorTest extends TestCase
{
    private function validator(): ManifestValidator
    {
        return new ManifestValidator(CommandTestRunner::METRICS, ['test_pass'], 15);
    }

    private static function valid(): array
    {
        return [
            'evidence' => 'UserPolicyTest::test_view fails with 403 for admins',
            'root_cause' => 'BasePolicy maps view to users-list instead of users-read',
            'fix' => 'Map the view ability to the {group}-read permission',
            'prediction' => [
                'expected_result' => 'UserPolicyTest passes and nothing else changes',
                'risk_level' => 'low',
                'metrics' => ['test_pass' => true, 'failures' => 0],
            ],
        ];
    }

    public function test_valid_manifest_is_accepted(): void
    {
        $this->validator()->validate(self::valid());
        $this->addToAssertionCount(1);
    }

    #[DataProvider('invalidManifests')]
    public function test_invalid_manifest_is_rejected(callable $mutate, string $message): void
    {
        $manifest = self::valid();
        $mutate($manifest);

        $this->expectException(ManifestRejectedException::class);
        $this->expectExceptionMessage($message);

        $this->validator()->validate($manifest);
    }

    public static function invalidManifests(): array
    {
        return [
            'missing evidence' => [function (&$m) { unset($m['evidence']); }, 'Missing or empty field: evidence'],
            'non string fix' => [function (&$m) { $m['fix'] = ['x']; }, 'Missing or empty field: fix'],
            'vague root cause' => [function (&$m) { $m['root_cause'] = 'bug'; }, 'root_cause is too vague'],
            'placeholder' => [function (&$m) { $m['fix'] = '...'; }, 'fix is too vague'],
            'cause equals fix' => [function (&$m) { $m['fix'] = $m['root_cause']; }, 'must each say something different'],
            'prediction not object' => [function (&$m) { $m['prediction'] = 'faster'; }, 'prediction must be an object'],
            'missing expected_result' => [function (&$m) { unset($m['prediction']['expected_result']); }, 'prediction.expected_result'],
            'bad risk level' => [function (&$m) { $m['prediction']['risk_level'] = 'none'; }, 'risk_level must be one of'],
            'no metrics' => [function (&$m) { unset($m['prediction']['metrics']); }, 'prediction.metrics must be a non-empty object'],
            'required metric missing' => [function (&$m) { $m['prediction']['metrics'] = ['failures' => 0]; }, 'must include "test_pass"'],
            'unmeasurable metric' => [function (&$m) { $m['prediction']['metrics']['response_time'] = 500; }, 'response_time cannot be measured'],
            'invalid expectation' => [function (&$m) { $m['prediction']['metrics']['duration_ms'] = ['under' => 5]; }, 'unknown operator'],
        ];
    }
}
