<?php

namespace AICodingGuard;

/**
 * Builds the system prompt that makes the agent follow
 * Hypothesis → Prediction → Experiment → Evaluation → Learning,
 * in the exact format the guard parses and validates.
 */
class SystemPrompt
{
    /**
     * @param  list<array<string, mixed>>  $lessons  from Memory::recent()
     * @param  list<string>  $metrics  measurable metrics (TestRunnerInterface::metrics())
     * @param  list<string>  $requiredMetrics
     * @param  list<string>  $protectedPaths
     * @param  list<string>  $allowedPaths
     */
    public static function build(
        array $lessons = [],
        array $metrics = ['test_pass'],
        array $requiredMetrics = ['test_pass'],
        array $protectedPaths = [],
        array $allowedPaths = [],
    ): string {
        $metricList = implode(', ', $metrics);
        $required = implode(', ', $requiredMetrics) ?: 'none';
        $paths = self::formatPaths($protectedPaths, $allowedPaths);
        $memory = self::formatLessons($lessons);

        return <<<PROMPT
You are a disciplined coding agent working on a Laravel project. Every change you
propose is applied, tested and evaluated automatically, and rolled back when the
result does not match your prediction.

## CORE RULES
- No change without a hypothesis: state what you observed, why it happens and how the change removes the cause.
- Predict the outcome with measurable metrics before changing anything.
- Prefer the smallest diff that tests the hypothesis; one root cause per change.
- If you are unsure of the root cause, do not propose a change; ask for more evidence instead.
- Do not break existing logic, follow the current code style, avoid unrelated refactors.
{$paths}
## OUTPUT FORMAT (STRICT)
Return exactly one JSON object and nothing else:

{
  "CHANGE_MANIFEST": {
    "evidence": "What you observed: the failing test, error message, log line or measurement.",
    "root_cause": "Why it happens, pointing at the code responsible.",
    "fix": "What the diff changes and why that removes the root cause.",
    "prediction": {
      "expected_result": "The outcome in words, e.g. 'UserPolicyTest passes, no other test changes'.",
      "risk_level": "low | medium | high",
      "metrics": { "test_pass": true, "failures": 0 }
    }
  },
  "CODE_DIFF": "A unified diff (git diff format) relative to the project root."
}

## PREDICTION RULES
- prediction.metrics is checked against the real test run. Measurable metrics: {$metricList}.
- Required metrics: {$required}.
- A value is either exact (true, 0) or a range object: {"min": 10}, {"max": 60000}, {"min": 1, "max": 5}.
- Unknown or unmeasurable metrics are rejected. If any metric does not match, the change is rolled back.
- risk_level "high" needs a human approval before the change is applied.

## CODE_DIFF RULES
- Paths start with a/ and b/ and are relative to the project root; include enough context lines for `git apply`.
- New files use "--- /dev/null"; deleted files use "+++ /dev/null".
- Escape the diff correctly inside the JSON string (\\n for new lines).
{$memory}
PROMPT;
    }

    /**
     * @param  list<array<string, mixed>>  $lessons
     */
    public static function formatLessons(array $lessons): string
    {
        $lines = [];

        foreach ($lessons as $lesson) {
            $fix = self::clip($lesson['fix'] ?? 'unknown change');
            $files = empty($lesson['files']) ? '' : ' ['.implode(', ', array_slice((array) $lesson['files'], 0, 5)).']';

            $line = match ($lesson['type'] ?? null) {
                'success' => "- WORKED: {$fix}{$files}",
                'fail' => "- FAILED (prediction wrong, rolled back): {$fix}{$files} — ".self::clip($lesson['reason'] ?? 'metrics did not match'),
                'rejected' => '- REJECTED before applying: '.self::clip($lesson['reason'] ?? 'invalid response'),
                'error' => "- ERROR while trying: {$fix}{$files} — ".self::clip($lesson['reason'] ?? 'unknown error'),
                default => null,
            };

            if ($line !== null) {
                $lines[] = $line;
            }
        }

        if ($lines === []) {
            return '';
        }

        return "\n## LESSONS FROM PREVIOUS CHANGES (most recent last)\n"
            ."Do not repeat what failed; reuse what worked when the evidence is similar.\n"
            .implode("\n", $lines)."\n";
    }

    private static function formatPaths(array $protected, array $allowed): string
    {
        $text = '';

        if ($protected !== []) {
            $text .= '- Never change these paths (the change is rejected): '.implode(', ', $protected)."\n";
            $text .= "  In particular, never edit tests to make them pass: tests are the measuring instrument.\n";
        }

        if ($allowed !== []) {
            $text .= '- Only these paths may be changed: '.implode(', ', $allowed)."\n";
        }

        return $text;
    }

    private static function clip(mixed $text, int $length = 240): string
    {
        $text = preg_replace('/\s+/', ' ', trim((string) (is_scalar($text) ? $text : json_encode($text))));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1).'…' : $text;
    }
}
