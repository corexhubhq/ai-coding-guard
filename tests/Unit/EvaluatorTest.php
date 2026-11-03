<?php

namespace AICodingGuard\Tests\Unit;

use AICodingGuard\Evaluator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EvaluatorTest extends TestCase
{
    public function test_matching_prediction_passes(): void
    {
        $result = Evaluator::evaluate(
            ['test_pass' => true, 'failures' => 0, 'duration_ms' => ['max' => 2000], 'tests' => ['min' => 10]],
            ['test_pass' => true, 'failures' => 0, 'duration_ms' => 1500, 'tests' => 12, 'output' => '...'],
        );

        $this->assertTrue($result->passed());
        $this->assertSame([], $result->mismatches());
    }

    public function test_wrong_prediction_fails_with_reason(): void
    {
        $result = Evaluator::evaluate(['test_pass' => true, 'failures' => 0], ['test_pass' => false, 'failures' => 2]);

        $this->assertFalse($result->passed());
        $this->assertCount(2, $result->mismatches());
        $this->assertStringContainsString('failures: expected 0', $result->summary());
    }

    public function test_unmeasured_metric_fails(): void
    {
        $result = Evaluator::evaluate(['test_pass' => true, 'tests' => ['min' => 1]], ['test_pass' => true]);

        $this->assertFalse($result->passed());
        $this->assertSame('not measured', $result->mismatches()[0]['reason']);
    }

    public function test_booleans_are_compared_strictly(): void
    {
        $this->assertFalse(Evaluator::evaluate(['test_pass' => true], ['test_pass' => 'false'])->passed());
        $this->assertFalse(Evaluator::evaluate(['test_pass' => false], ['test_pass' => 0])->passed());
    }

    public function test_numbers_are_exact_unless_a_range_is_given(): void
    {
        $this->assertFalse(Evaluator::evaluate(['failures' => 0], ['failures' => 1])->passed());
        $this->assertTrue(Evaluator::evaluate(['failures' => ['max' => 1]], ['failures' => 1])->passed());
        $this->assertFalse(Evaluator::evaluate(['tests' => ['min' => 5, 'max' => 9]], ['tests' => 10])->passed());
    }

    public function test_empty_prediction_never_passes(): void
    {
        $this->assertFalse(Evaluator::evaluate([], ['test_pass' => true])->passed());
    }

    #[DataProvider('invalidExpectations')]
    public function test_invalid_expectations_are_reported(mixed $expectation): void
    {
        $this->assertNotNull(Evaluator::invalidExpectation($expectation));
    }

    public static function invalidExpectations(): array
    {
        return [
            'null' => [null],
            'empty string' => ['  '],
            'list' => [[1, 2]],
            'unknown operator' => [['lt' => 3]],
            'non numeric max' => [['max' => 'fast']],
            'min above max' => [['min' => 5, 'max' => 1]],
        ];
    }
}
