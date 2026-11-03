<?php

namespace AICodingGuard;

/**
 * Compares the predicted metrics with what the experiment actually measured.
 *
 * An expectation is either
 *  - a scalar (true, 0, "ok") that must be equal to the measured value, or
 *  - an object with any of "min", "max" (numbers, inclusive) and "eq".
 */
class Evaluator
{
    private const OPERATORS = ['min', 'max', 'eq'];

    /**
     * @param  array<string, mixed>  $expected  prediction.metrics
     * @param  array<string, mixed>  $actual  metrics reported by the test runner
     */
    public static function evaluate(array $expected, array $actual): EvaluationResult
    {
        $checks = [];

        foreach ($expected as $metric => $expectation) {
            $measured = array_key_exists($metric, $actual) ? $actual[$metric] : null;
            $reason = array_key_exists($metric, $actual) && $measured !== null
                ? self::mismatch($expectation, $measured)
                : 'not measured';

            $checks[] = [
                'metric' => (string) $metric,
                'expected' => $expectation,
                'actual' => $measured,
                'passed' => $reason === null,
                'reason' => $reason,
            ];
        }

        return new EvaluationResult($checks);
    }

    /**
     * Returns why an expectation is malformed, or null when it is valid.
     */
    public static function invalidExpectation(mixed $expectation): ?string
    {
        if (is_bool($expectation) || is_int($expectation) || is_float($expectation)) {
            return null;
        }

        if (is_string($expectation)) {
            return trim($expectation) === '' ? 'expected value is empty' : null;
        }

        if (! is_array($expectation) || $expectation === [] || array_is_list($expectation)) {
            return 'expected value must be a boolean, number, string or an object with min/max/eq.';
        }

        foreach ($expectation as $operator => $value) {
            if (! in_array($operator, self::OPERATORS, true)) {
                return "unknown operator \"{$operator}\" (use min, max or eq).";
            }

            if ($operator !== 'eq' && ! is_int($value) && ! is_float($value)) {
                return "\"{$operator}\" must be a number.";
            }

            if ($operator === 'eq' && ! is_scalar($value)) {
                return '"eq" must be a scalar.';
            }
        }

        if (isset($expectation['min'], $expectation['max']) && $expectation['min'] > $expectation['max']) {
            return '"min" is greater than "max".';
        }

        return null;
    }

    private static function mismatch(mixed $expectation, mixed $actual): ?string
    {
        if (is_array($expectation)) {
            foreach ($expectation as $operator => $value) {
                $reason = match ($operator) {
                    'min' => ! is_numeric($actual) ? 'not a number' : ($actual < $value ? "below min {$value}" : null),
                    'max' => ! is_numeric($actual) ? 'not a number' : ($actual > $value ? "above max {$value}" : null),
                    'eq' => self::mismatch($value, $actual),
                    default => "unknown operator {$operator}",
                };

                if ($reason !== null) {
                    return $reason;
                }
            }

            return null;
        }

        if (is_bool($expectation)) {
            return $actual === $expectation ? null : 'expected '.var_export($expectation, true);
        }

        if (is_int($expectation) || is_float($expectation)) {
            return is_numeric($actual) && (float) $actual === (float) $expectation ? null : "expected {$expectation}";
        }

        $normalize = fn ($v) => strtolower(trim((string) $v));

        return is_scalar($actual) && $normalize($actual) === $normalize($expectation) ? null : "expected \"{$expectation}\"";
    }
}
