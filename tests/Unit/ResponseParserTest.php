<?php

namespace AICodingGuard\Tests\Unit;

use AICodingGuard\Exceptions\InvalidResponseException;
use AICodingGuard\Support\ResponseParser;
use PHPUnit\Framework\TestCase;

class ResponseParserTest extends TestCase
{
    private const DIFF = "--- a/app/A.php\n+++ b/app/A.php\n@@ -1,3 +1,3 @@\n <?php\n \n-return 1;\n+return 2;\n";

    public function test_parses_json(): void
    {
        $parsed = ResponseParser::parse(json_encode(['CHANGE_MANIFEST' => ['fix' => 'x'], 'CODE_DIFF' => self::DIFF]));

        $this->assertSame(['fix' => 'x'], $parsed['CHANGE_MANIFEST']);
        $this->assertSame(self::DIFF, $parsed['CODE_DIFF']);
    }

    public function test_parses_fenced_json_and_keeps_blank_context_lines(): void
    {
        $raw = "```json\n".json_encode(['change_manifest' => ['fix' => 'x'], 'code_diff' => self::DIFF])."\n```";

        $this->assertSame(self::DIFF, ResponseParser::parse($raw)['CODE_DIFF']);
    }

    public function test_parses_the_text_format(): void
    {
        $raw = <<<TXT
1. ANALYSIS
The function returns the wrong value {see logs}.

2. CHANGE_MANIFEST
```json
{"evidence": "a \"quoted\" } brace", "prediction": {"risk_level": "low"}}
```

3. CODE_DIFF
```diff
--- a/app/A.php
+++ b/app/A.php
@@ -1,3 +1,3 @@
 <?php
 
-return 1;
+return 2;
```
TXT;

        $parsed = ResponseParser::parse($raw);

        $this->assertSame('a "quoted" } brace', $parsed['CHANGE_MANIFEST']['evidence']);
        $this->assertSame(['risk_level' => 'low'], $parsed['CHANGE_MANIFEST']['prediction']);
        $this->assertSame(self::DIFF, $parsed['CODE_DIFF']);
    }

    public function test_parses_unfenced_diff_in_text_format(): void
    {
        $raw = "CHANGE_MANIFEST: {\"fix\": \"x\"}\nCODE_DIFF:\n".self::DIFF;

        $this->assertSame(self::DIFF, ResponseParser::parse($raw)['CODE_DIFF']);
    }

    public function test_rejects_response_without_manifest(): void
    {
        $this->expectException(InvalidResponseException::class);
        $this->expectExceptionMessage('No manifest, no change');

        ResponseParser::parse("Here is the fix:\n".self::DIFF);
    }

    public function test_rejects_response_without_diff(): void
    {
        $this->expectException(InvalidResponseException::class);

        ResponseParser::fromArray(['CHANGE_MANIFEST' => ['fix' => 'x'], 'CODE_DIFF' => '  ']);
    }
}
