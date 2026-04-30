<?php

namespace AICodingGuard\Middleware;

class ManifestValidator
{
    public static function validate(array $manifest): void
    {
        self::requireFields($manifest, [
            'evidence',
            'root_cause',
            'fix',
            'prediction'
        ]);

        if (!is_array($manifest['prediction'])) {
            throw new \Exception("prediction must be an object");
        }

        self::requireFields($manifest['prediction'], [
            'expected_result',
            'risk_level'
        ]);

        self::validateRiskLevel($manifest['prediction']['risk_level']);
        self::validateContent($manifest);
    }

    protected static function requireFields(array $data, array $fields): void
    {
        foreach ($fields as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \Exception("Missing or empty field: {$field}");
            }
        }
    }

    protected static function validateRiskLevel($risk): void
    {
        $allowed = ['low', 'medium', 'high'];

        if (!in_array($risk, $allowed, true)) {
            throw new \Exception("Invalid risk_level: {$risk}");
        }
    }

    protected static function validateContent(array $manifest): void
    {
        // tránh AI trả lời quá chung chung
        $minLength = 10;

        if (strlen($manifest['evidence']) < $minLength) {
            throw new \Exception("evidence too vague");
        }

        if (strlen($manifest['root_cause']) < $minLength) {
            throw new \Exception("root_cause too vague");
        }

        if (strlen($manifest['fix']) < $minLength) {
            throw new \Exception("fix too vague");
        }
    }
}