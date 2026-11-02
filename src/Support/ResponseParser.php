<?php

namespace AICodingGuard\Support;

use AICodingGuard\Exceptions\InvalidResponseException;

/**
 * Turns a raw AI answer into ['CHANGE_MANIFEST' => array, 'CODE_DIFF' => string].
 *
 * Accepted shapes:
 *  1. A JSON object {"CHANGE_MANIFEST": {...}, "CODE_DIFF": "..."} (optionally in a ```json fence).
 *  2. The text format the system prompt asks for:
 *       ANALYSIS ...
 *       CHANGE_MANIFEST: { ... }        (raw or in a ```json fence)
 *       CODE_DIFF: ```diff ... ```      (fenced, or a raw diff to the end)
 */
final class ResponseParser
{
    /**
     * @return array{CHANGE_MANIFEST: array<string, mixed>, CODE_DIFF: string}
     */
    public static function parse(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new InvalidResponseException('AI response is empty.');
        }

        $json = json_decode(self::unfence($raw), true);

        if (is_array($json)) {
            return self::fromArray($json);
        }

        return self::fromArray([
            'CHANGE_MANIFEST' => self::extractManifest($raw),
            'CODE_DIFF' => self::extractDiff($raw),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CHANGE_MANIFEST: array<string, mixed>, CODE_DIFF: string}
     */
    public static function fromArray(array $data): array
    {
        $data = array_change_key_case($data, CASE_UPPER);
        $manifest = $data['CHANGE_MANIFEST'] ?? null;
        $diff = $data['CODE_DIFF'] ?? null;

        if (! is_array($manifest) || $manifest === []) {
            throw new InvalidResponseException('AI response has no CHANGE_MANIFEST object. No manifest, no change.');
        }

        if (! is_string($diff) || trim($diff) === '') {
            throw new InvalidResponseException('AI response has no CODE_DIFF.');
        }

        $diff = self::unfence($diff);

        return ['CHANGE_MANIFEST' => $manifest, 'CODE_DIFF' => rtrim($diff, "\r\n")."\n"];
    }

    private static function extractManifest(string $raw): array
    {
        $pos = stripos($raw, 'CHANGE_MANIFEST');

        if ($pos === false) {
            throw new InvalidResponseException('AI response has no CHANGE_MANIFEST section. No manifest, no change.');
        }

        $start = strpos($raw, '{', $pos);
        $json = $start === false ? null : self::balancedObject($raw, $start);
        $manifest = $json === null ? null : json_decode($json, true);

        if (! is_array($manifest)) {
            throw new InvalidResponseException('CHANGE_MANIFEST is not valid JSON.');
        }

        return $manifest;
    }

    private static function extractDiff(string $raw): string
    {
        $pos = strripos($raw, 'CODE_DIFF');

        if ($pos === false) {
            throw new InvalidResponseException('AI response has no CODE_DIFF section.');
        }

        $section = substr($raw, $pos);

        if (preg_match('/```[a-zA-Z]*\R(.*?)\R?```/s', $section, $m)) {
            return $m[1];
        }

        if (preg_match('/^(diff --git |--- ).*/ms', $section, $m)) {
            return $m[0];
        }

        throw new InvalidResponseException('CODE_DIFF section does not contain a diff.');
    }

    /**
     * Returns the JSON object starting at $start, honouring strings and escapes.
     */
    private static function balancedObject(string $text, int $start): ?string
    {
        $depth = 0;
        $inString = false;
        $length = strlen($text);

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}' && --$depth === 0) {
                return substr($text, $start, $i - $start + 1);
            }
        }

        return null;
    }

    /**
     * Removes a surrounding ``` fence. Unfenced text is returned untouched:
     * trimming a diff could eat the leading space of a blank context line.
     */
    private static function unfence(string $text): string
    {
        if (preg_match('/^```[a-zA-Z]*\R(.*?)\R?```$/s', trim($text), $m)) {
            return $m[1];
        }

        return $text;
    }
}
