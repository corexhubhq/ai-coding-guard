# AI Coding Guard (Laravel Package)

> A lightweight guardrail layer that forces AI coding agents to **think, predict, validate, and learn** before applying any code change.

This package helps you safely integrate AI (Claude, GPT, etc.) into your Laravel workflow by enforcing:

* Hypothesis-driven changes (no blind edits)
* Structured change manifests
* Automated evaluation (prediction vs reality)
* Safe rollback on failure
* Incremental learning via memory

---

# ✨ Features

* 🧠 **System Prompt Builder** — enforce disciplined AI behavior
* 🛡️ **Manifest Validator** — reject vague or unsafe changes
* 🧪 **Evaluator** — compare prediction vs actual result
* 🔁 **Automatic Rollback** — revert failed changes instantly
* 📚 **Long-term Memory** — store lessons from success/failure
* 🖥️ **CLI Command (`ai-coding-guard:run`)** — run AI changes safely

---

## Requirements

- PHP >= 8.0
- Laravel >= 11.0

# 📦 Installation

Install via Composer:

```bash
composer require corexhubhq/ai-coding-guard
```

The package will **auto-register** via Laravel's package discovery and includes **default implementations** — ready to use immediately!

---

# ⚙️ Configuration

Publish the configuration file (optional):

```bash
php artisan vendor:publish --tag=ai-coding-guard-config
```

This creates `config/ai-coding-guard.php`:

```php
return [
    'memory_path' => storage_path('ai-coding-guard/memory.json'),
    'backup_path' => storage_path('ai-coding-guard/last_changes.json'),
    'rollback_strategy' => 'patch', // patch | git | file
    'auto_create_storage' => true,
];
```

**Storage directories** are created automatically on first run.

---

# 🎯 Default Implementations

The package includes ready-to-use implementations:

## GitPatchApplier
- Applies code changes using `git apply`
- Creates automatic backups before applying
- Supports rollback to previous state
- Requires: Git installed

## PHPUnitTestRunner
- Runs PHPUnit tests automatically
- Parses test results with detailed output
- Falls back gracefully if PHPUnit is not available

**No additional setup needed** — just install and run!

---

# 🔌 Custom Implementations (Optional)

Want to customize? You can override the default implementations:

## Custom Code Applier

```php
namespace App\AICodingGuard;

use AICodingGuard\Contracts\CodeApplierInterface;

class MyCustomApplier implements CodeApplierInterface
{
    public function apply(string $diff): void
    {
        // Your custom logic
    }

    public function rollback(): void
    {
        // Your rollback logic
    }
}
```

## Custom Test Runner

```php
namespace App\AICodingGuard;

use AICodingGuard\Contracts\TestRunnerInterface;

class MyCustomTestRunner implements TestRunnerInterface
{
    public function run(): array
    {
        // Run your tests
        return [
            'test_pass' => true,
            'output' => '...',
        ];
    }
}
```

## Bind Custom Implementations

In your `AppServiceProvider`:

```php
use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use App\AICodingGuard\MyCustomApplier;
use App\AICodingGuard\MyCustomTestRunner;

public function register()
{
    $this->app->bind(CodeApplierInterface::class, MyCustomApplier::class);
    $this->app->bind(TestRunnerInterface::class, MyCustomTestRunner::class);
}
```

---

# 🚀 Usage

## Prepare AI response (JSON)

Example: `storage/ai-coding-guard/input.json`

```json
{
  "CHANGE_MANIFEST": {
    "evidence": "API response slow (~2s)",
    "root_cause": "missing index on user_id",
    "fix": "add index on user_id column",
    "prediction": {
      "response_time": 500,
      "test_pass": true,
      "risk_level": "low"
    }
  },
  "CODE_DIFF": "--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ ..."
}
```

---

## Run AI safely

```bash
php artisan ai-coding-guard:run --file=storage/ai-coding-guard/input.json
```

---

## Other options

### Dry run (no changes applied)

```bash
php artisan ai-coding-guard:run --file=storage/ai-coding-guard/input.json --dry
```

---

### Skip test (debug only)

```bash
php artisan ai-coding-guard:run --file=storage/ai-coding-guard/input.json --no-test
```

---

### Pipe input (advanced)

```bash
cat response.json | php artisan ai-coding-guard:run
```

---

# 🔁 Workflow

```text
AI → CHANGE_MANIFEST → Validator → Apply → Test → Evaluate
                                      ↓
                             PASS → Save memory
                             FAIL → Rollback + Save lesson
```

---

# 🧠 Memory System

Stored at:

```bash
storage/ai-coding-guard/memory.json
```

### Example:

```json
{
  "lessons": [
    {
      "type": "fail",
      "manifest": { ... },
      "actual": { ... }
    },
    {
      "type": "success",
      "manifest": { ... }
    }
  ]
}
```

---

# ⚠️ Important Rules

### 1. Prediction must be measurable

❌ Bad:

```json
"expected_result": "faster response"
```

✅ Good:

```json
{
  "response_time": 500,
  "test_pass": true
}
```

---

### 2. Always use clean git working tree

```bash
git status
```

---

### 3. Keep changes small

* Avoid multi-file refactors
* Prefer minimal diffs

---

# 🔒 Safety Model

* No manifest → ❌ reject
* Weak reasoning → ❌ reject
* Prediction mismatch → 🔁 rollback
* Exception → 🔁 rollback

---

# 🧩 Extending

You can extend the system by:

* Custom Evaluator logic
* Advanced TestRunner (performance, API checks)
* Memory filtering / pruning
* CI/CD integration

---

# 🚀 Roadmap Ideas

* `ai-coding-guard:ask` — call AI API directly
* Auto retry loop (AHE cycle)
* Patch validator
* Prediction schema enforcement
* Interactive approval mode

---

# 💡 Philosophy

This package is not an AI tool.

It is a **discipline layer** that forces AI to behave like a real engineer:

> Hypothesis → Prediction → Experiment → Evaluation → Learning

---

# 📄 License

MIT
