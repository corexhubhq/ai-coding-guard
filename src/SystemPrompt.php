<?php

namespace AICodingGuard;

class SystemPrompt
{
    public static function build(array $memory = []): string
    {
        $memoryText = self::formatMemory($memory);

        return <<<PROMPT
You are a disciplined coding agent.

## CORE RULES
- Do NOT change code without a hypothesis
- Always produce a CHANGE_MANIFEST
- Prefer minimal and safe edits
- If unsure → do not proceed

## CHANGE MANIFEST FORMAT (STRICT)
You MUST return:

CHANGE_MANIFEST:
{
  "evidence": "...",
  "root_cause": "...",
  "fix": "...",
  "prediction": {
    "expected_result": "...",
    "risk_level": "low|medium|high"
  }
}

## EVALUATION RULE
- If prediction is not measurable → it will be rejected
- If result != prediction → change will be rolled back

## CODING PRINCIPLES
- Do not break existing logic
- Follow current code style
- Avoid unnecessary refactor

## OUTPUT FORMAT
1. ANALYSIS
2. CHANGE_MANIFEST (json)
3. CODE_DIFF

{$memoryText}
PROMPT;
    }

    protected static function formatMemory(array $memory): string
    {
        if (empty($memory)) {
            return '';
        }

        $lines = ["\n## PREVIOUS LESSONS"];

        foreach ($memory as $lesson) {
            if (!is_array($lesson)) continue;

            if (isset($lesson['type']) && $lesson['type'] === 'fail') {
                $lines[] = "- Avoid: " . ($lesson['manifest']['fix'] ?? 'unknown fix');
            }

            if (isset($lesson['type']) && $lesson['type'] === 'success') {
                $lines[] = "- Prefer: " . ($lesson['manifest']['fix'] ?? 'unknown fix');
            }
        }

        return implode("\n", $lines);
    }
}