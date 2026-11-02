# AI Coding Guard (Laravel package)

> A discipline layer that makes AI coding agents behave like an engineer running an experiment:
>
> **Hypothesis → Prediction → Experiment → Evaluation → Learning**

The guard does not call any AI. You (or your agent) give it an AI answer that contains a
**CHANGE_MANIFEST** (evidence, root cause, fix, measurable prediction) and a **CODE_DIFF**.
The guard then:

| Step | What the guard does | Fails → |
|------|---------------------|---------|
| **Hypothesis** | Requires `evidence`, `root_cause`, `fix`, each specific and different from the others | rejected, nothing changed |
| **Prediction** | Requires `prediction.metrics` using only metrics the test runner can measure (`test_pass` is mandatory) | rejected, nothing changed |
| **Experiment** | Refuses diffs touching protected paths (tests, `.env`, CI, `composer.json`...), checks `git apply --check` on a clean working tree, asks for approval on `high` risk, applies the diff, runs the test suite | rejected / aborted |
| **Evaluation** | Compares every predicted metric with the measured one | **rolled back** with `git apply -R` |
| **Learning** | Stores a compact lesson (success, fail, rejected, error) in `memory.json`; `ai-coding-guard:prompt` puts the recent lessons in the system prompt | — |

A change survives only when the tests pass **and** the agent predicted the outcome correctly.
An agent that guesses gets rolled back, even if the tests happen to pass.

---

## Requirements

- PHP 8.2+, Laravel 11, 12 or 13
- `git` (the project must be a git working tree)
- A test suite: `php artisan test`, Pest or PHPUnit (auto-detected)

## Installation

The package is not on Packagist; add the repository, like `corexhubhq/laravel-permission`:

```json
"repositories": [
    { "type": "vcs", "url": "https://github.com/corexhubhq/ai-coding-guard" }
]
```

```bash
composer require --dev corexhubhq/ai-coding-guard:dev-main
```

Install it as a **dev** dependency: it applies code changes and runs tests, it does not belong in production.

Publish the config (optional):

```bash
php artisan vendor:publish --tag=ai-coding-guard-config
```

---

## Workflow

### 1. Give the agent the system prompt

```bash
php artisan ai-coding-guard:prompt > ai-prompt.md
```

The prompt contains the exact output format, the measurable metrics, the protected paths and
the **lessons** learned from previous runs. Use it as the system prompt of Claude, GPT or any
coding agent.

### 2. Save the AI answer

Either JSON ([`examples/response.json`](examples/response.json)):

```json
{
  "CHANGE_MANIFEST": {
    "evidence": "UserResourceTest::test_list_users fails: GET /admin/users returns 403 for a user with users-read",
    "root_cause": "UserPolicy::viewAny checks users-list, but BasePolicy maps viewAny to {group}-read",
    "fix": "Remove the viewAny override so the BasePolicy mapping (users-read) is used",
    "prediction": {
      "expected_result": "UserResourceTest passes; no other test changes",
      "risk_level": "low",
      "metrics": { "test_pass": true, "failures": 0, "duration_ms": { "max": 300000 } }
    }
  },
  "CODE_DIFF": "--- a/app/Policies/UserPolicy.php\n+++ b/app/Policies/UserPolicy.php\n@@ ..."
}
```

or the text format (ANALYSIS / CHANGE_MANIFEST / CODE_DIFF with ```json and ```diff blocks,
see [`examples/response.md`](examples/response.md)). Both are parsed.

### 3. Run it

```bash
php artisan ai-coding-guard:run response.json
```

```
Evidence ........ UserResourceTest::test_list_users fails: GET /admin/users returns 403 …
Root cause ...... UserPolicy::viewAny checks users-list, but BasePolicy maps viewAny to …
Files ........... app/Policies/UserPolicy.php

+-------------+-----------+----------+--------+
| Metric      | Predicted | Measured | Result |
+-------------+-----------+----------+--------+
| test_pass   | true      | true     | ✔      |
| failures    | 0         | 0        | ✔      |
+-------------+-----------+----------+--------+

[success] Prediction confirmed; change kept. Review and commit it (or run ai-coding-guard:rollback).
```

Then review the diff and commit it yourself: the guard never commits.

### Options

| Command | Purpose |
|---------|---------|
| `ai-coding-guard:run response.json --dry` | Validate the manifest, check paths and `git apply --check`; change nothing |
| `ai-coding-guard:run response.json --filter=UserResourceTest` | Only run matching tests (faster loop; run the full suite before committing) |
| `ai-coding-guard:run response.json --force` | Approve a `high` risk change without the prompt (CI / agents) |
| `ai-coding-guard:run response.json --json` | Machine-readable result for an agent loop; exit code 0 = kept, 1 = not kept |
| `cat answer.md \| php artisan ai-coding-guard:run` | Read the answer from STDIN |
| `ai-coding-guard:run response.json --no-test` | Debug only: apply without tests. Not evaluated, not recorded |
| `ai-coding-guard:rollback` | Undo the last applied change |
| `ai-coding-guard:memory [--type=fail] [--clear]` | Inspect or reset the lessons |
| `ai-coding-guard:prompt [--lessons=10] [--output=file]` | Print the system prompt with lessons |

### Result statuses

| Status | Meaning | Recorded in memory |
|--------|---------|--------------------|
| `success` | Applied, tests run, every metric matched | yes |
| `failed` | Applied, a metric did not match → rolled back | yes |
| `rejected` | Invalid response, vague manifest, unmeasurable prediction, protected path or diff that does not apply | yes |
| `error` | Unexpected error after applying → rolled back | yes |
| `checked` | `--dry` passed | no |
| `aborted` | Dirty working tree, not a git repo, or high risk not approved | no (not the AI's fault) |
| `unverified` | `--no-test` | no |

---

## Predictions

`prediction.metrics` maps a metric to an expectation:

- an exact value: `"test_pass": true`, `"failures": 0`
- a range: `"duration_ms": {"max": 60000}`, `"tests": {"min": 120}`, `{"min": 1, "max": 3}`

Measurable metrics of the default runner: `test_pass`, `exit_code`, `tests`, `assertions`,
`failures`, `errors`, `skipped`, `duration_ms` (counts come from the JUnit report written with
`--log-junit`). Any other metric (`response_time`, `"faster"`) is **rejected before applying**:
a prediction that cannot be measured cannot be evaluated.

---

## Configuration

`config/ai-coding-guard.php`:

```php
'storage_path' => storage_path('ai-coding-guard'),   // memory.json, last.patch (+ a .gitignore)

'memory' => ['path' => null, 'max_lessons' => 100, 'prompt_lessons' => 20],

'git' => ['require_clean_tree' => true],

'tests' => [
    'command' => null,          // null = php artisan test → vendor/bin/pest → vendor/bin/phpunit
    'junit' => true,            // add --log-junit to measure counts
    'timeout' => 900,
    'output_limit' => 4000,
],

'manifest' => ['min_length' => 15, 'required_metrics' => ['test_pass']],

'paths' => [
    'protected' => ['.env', '.env.*', '.git/*', '.github/*', 'tests/*', 'phpunit.xml', 'phpunit.xml.dist',
                    'composer.json', 'composer.lock', 'vendor/*', 'node_modules/*', 'storage/*',
                    'bootstrap/cache/*', 'config/ai-coding-guard.php'],
    'allowed' => [],            // e.g. ['app/*', 'resources/views/*', 'database/migrations/*']
],

'confirm_risk_levels' => ['high'],
```

**Why are tests protected?** Tests are the measuring instrument. If the agent could edit them,
it could make any change "pass". Write or update tests yourself, or remove `tests/*` from the
list deliberately for a test-writing task.

### Example: a Filament project (`developer`)

```php
// config/ai-coding-guard.php
'tests' => [
    // Pest 4, MySQL test DB from phpunit.xml
    'command' => [PHP_BINARY, 'vendor/bin/pest', '--compact'],
    'timeout' => 1200,
],
'paths' => [
    // keep the default list and add the project's sensitive files
    'protected' => [
        '.env', '.env.*', '.git/*', '.github/*', 'tests/*', 'phpunit.xml', 'composer.json', 'composer.lock',
        'vendor/*', 'node_modules/*', 'storage/*', 'bootstrap/cache/*', 'config/ai-coding-guard.php',
        'config/auth.php', 'app/Policies/BasePolicy.php', 'deployments/*', 'docker/*', 'docker-compose.yml',
    ],
    'allowed' => ['app/*', 'resources/views/*', 'resources/css/*', 'database/*', 'routes/*', 'lang/*'],
],
```

Inside Docker: `docker compose exec -u www-data web php artisan ai-coding-guard:run response.json`.

---

## Custom implementations

Bind your own implementations in a service provider (the package uses `bindIf`, so yours win):

```php
use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;

$this->app->bind(TestRunnerInterface::class, MyTestRunner::class);
```

```php
interface TestRunnerInterface
{
    /** Measured metrics; must contain test_pass (bool). Fail closed: never report true when tests did not run. */
    public function run(array $options = []): array;

    /** Metrics run() measures; predictions may only use these. */
    public function metrics(): array;
}

interface CodeApplierInterface
{
    public function check(string $diff): void;   // throw PatchRejectedException / EnvironmentException
    public function apply(string $diff): void;
    public function rollback(): void;            // undo exactly what apply() did
    public function canRollback(): bool;
}
```

For example, a runner that also measures an endpoint's response time can add `response_time_ms`
to `metrics()` and to the result of `run()`; predictions can then use it.

## Programmatic use

```php
$result = app(\AICodingGuard\Runner::class)->handle($rawAiAnswer, ['filter' => 'UserResourceTest']);

$result->status;            // success | failed | rejected | error | checked | aborted | unverified
$result->evaluation?->checks;
```

## Development

```bash
composer install
composer test
```

## License

MIT
