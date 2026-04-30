<?php

namespace AICodingGuard;

class Evaluator
{
    public static function evaluate(array $prediction, array $actual): bool
    {
        // normalize data
        $prediction = self::normalize($prediction);
        $actual = self::normalize($actual);

        foreach ($prediction as $key => $expectedValue) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }

            if (!self::compare($expectedValue, $actual[$key])) {
                return false;
            }
        }

        return true;
    }

    protected static function normalize(array $data): array
    {
        $normalized = [];

        foreach ($data as $key => $value) {
            $normalized[$key] = is_string($value)
                ? strtolower(trim($value))
                : $value;
        }

        return $normalized;
    }

    protected static function compare($expected, $actual): bool
    {
        // boolean
        if (is_bool($expected)) {
            return (bool)$actual === $expected;
        }

        // numeric (loose comparison for numbers)
        if (is_numeric($expected) && is_numeric($actual)) {
            return $actual <= $expected;
        }

        // string
        if (is_string($expected)) {
            return strpos($actual, $expected) !== false;
        }

        return $expected === $actual;
    }
}