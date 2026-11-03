<?php

namespace AICodingGuard;

use AICodingGuard\Exceptions\ManifestRejectedException;

/**
 * Rejects changes that are not backed by a hypothesis and a measurable prediction.
 *
 * Expected CHANGE_MANIFEST:
 *
 *   {
 *     "evidence":   "what was observed (error, failing test, log line)",
 *     "root_cause": "why it happens",
 *     "fix":        "what the diff changes and why that removes the cause",
 *     "prediction": {
 *       "expected_result": "human readable outcome",
 *       "risk_level": "low|medium|high",
 *       "metrics": { "test_pass": true, "failures": 0, "duration_ms": {"max": 60000} }
 *     }
 *   }
 */
class ManifestValidator
{
    public const RISK_LEVELS = ['low', 'medium', 'high'];

    private const PLACEHOLDERS = ['...', 'todo', 'tbd', 'n/a', 'unknown', 'none', 'fix bug', 'fix the bug'];

    /**
     * @param  list<string>  $measurableMetrics  metrics the test runner reports
     * @param  list<string>  $requiredMetrics  metrics every prediction must include
     */
    public function __construct(
        private readonly array $measurableMetrics,
        private readonly array $requiredMetrics = ['test_pass'],
        private readonly int $minLength = 15,
    ) {
    }

    /**
     * @param  array<string, mixed>  $manifest
     *
     * @throws ManifestRejectedException
     */
    public function validate(array $manifest): void
    {
        foreach (['evidence', 'root_cause', 'fix'] as $field) {
            $this->requireText($manifest, $field);
        }

        if ($this->same($manifest['evidence'], $manifest['root_cause']) || $this->same($manifest['root_cause'], $manifest['fix'])) {
            throw new ManifestRejectedException('evidence, root_cause and fix must each say something different (observation → cause → change).');
        }

        $prediction = $manifest['prediction'] ?? null;

        if (! is_array($prediction) || array_is_list($prediction)) {
            throw new ManifestRejectedException('prediction must be an object.');
        }

        $this->requireText($prediction, 'expected_result', 'prediction.expected_result');

        $risk = $prediction['risk_level'] ?? null;

        if (! is_string($risk) || ! in_array(strtolower(trim($risk)), self::RISK_LEVELS, true)) {
            throw new ManifestRejectedException('prediction.risk_level must be one of: '.implode(', ', self::RISK_LEVELS).'.');
        }

        $this->validateMetrics($prediction['metrics'] ?? null);
    }

    /** @return list<string> */
    public function measurableMetrics(): array
    {
        return $this->measurableMetrics;
    }

    /** @return list<string> */
    public function requiredMetrics(): array
    {
        return $this->requiredMetrics;
    }

    private function validateMetrics(mixed $metrics): void
    {
        if (! is_array($metrics) || $metrics === [] || array_is_list($metrics)) {
            throw new ManifestRejectedException(
                'prediction.metrics must be a non-empty object of measurable values, e.g. {"test_pass": true, "failures": 0}. '
                .'A prediction that cannot be measured is rejected.'
            );
        }

        foreach ($this->requiredMetrics as $required) {
            if (! array_key_exists($required, $metrics)) {
                throw new ManifestRejectedException("prediction.metrics must include \"{$required}\".");
            }
        }

        foreach ($metrics as $name => $expectation) {
            if (! in_array($name, $this->measurableMetrics, true)) {
                throw new ManifestRejectedException(
                    "prediction.metrics.{$name} cannot be measured. Measurable metrics: ".implode(', ', $this->measurableMetrics).'.'
                );
            }

            if ($error = Evaluator::invalidExpectation($expectation)) {
                throw new ManifestRejectedException("prediction.metrics.{$name}: {$error}");
            }
        }
    }

    private function requireText(array $data, string $field, ?string $label = null): void
    {
        $label ??= $field;
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new ManifestRejectedException("Missing or empty field: {$label}.");
        }

        $value = trim($value);

        if (in_array(strtolower(rtrim($value, '.!')), self::PLACEHOLDERS, true) || mb_strlen($value) < $this->minLength) {
            throw new ManifestRejectedException("{$label} is too vague (\"{$value}\"); be specific (at least {$this->minLength} characters).");
        }
    }

    private function same(string $a, string $b): bool
    {
        $normalize = fn (string $s) => preg_replace('/\s+/', ' ', strtolower(trim($s)));

        return $normalize($a) === $normalize($b);
    }
}
