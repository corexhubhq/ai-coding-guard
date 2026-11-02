<?php

namespace AICodingGuard\Console;

use AICodingGuard\Exceptions\InvalidResponseException;
use AICodingGuard\RunResult;
use AICodingGuard\Runner;
use Illuminate\Console\Command;

class RunCommand extends Command
{
    protected $signature = 'ai-coding-guard:run
                            {file? : AI response file (JSON or the text format of ai-coding-guard:prompt); reads STDIN when omitted}
                            {--file= : Same as the file argument}
                            {--dry : Validate the manifest and check the diff applies, change nothing}
                            {--no-test : Apply without running tests (debug only: not evaluated, not recorded)}
                            {--filter= : Only run tests matching this filter}
                            {--force : Approve changes whose risk level needs confirmation}
                            {--json : Print the result as JSON (for agents and CI)}';

    protected $description = 'Validate, apply, test and evaluate an AI change; roll back when the prediction is wrong';

    public function handle(Runner $runner): int
    {
        try {
            $input = $this->readInput();
        } catch (InvalidResponseException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $this->option('json')) {
            $this->components->info($this->option('dry') ? 'AI Coding Guard — dry run' : 'AI Coding Guard');
        }

        $result = $runner->handle($input, [
            'dry' => (bool) $this->option('dry'),
            'skip_tests' => (bool) $this->option('no-test'),
            'filter' => $this->option('filter'),
            'confirm' => fn (array $manifest) => $this->approve($manifest),
        ]);

        $this->option('json') ? $this->printJson($result) : $this->printResult($result);

        return $result->successful() ? self::SUCCESS : self::FAILURE;
    }

    private function readInput(): string
    {
        $file = $this->argument('file') ?? $this->option('file');

        if ($file) {
            if (! is_file($file) || ! is_readable($file)) {
                throw new InvalidResponseException("File not found: {$file}");
            }

            return (string) file_get_contents($file);
        }

        if (defined('STDIN') && function_exists('stream_isatty') && @stream_isatty(STDIN)) {
            throw new InvalidResponseException('No input: pass a file (ai-coding-guard:run response.json) or pipe the AI response to STDIN.');
        }

        $input = defined('STDIN') ? stream_get_contents(STDIN) : false;

        if ($input === false || trim($input) === '') {
            throw new InvalidResponseException('No input: pass a file or pipe the AI response to STDIN.');
        }

        return $input;
    }

    private function approve(array $manifest): bool
    {
        if ($this->option('force')) {
            return true;
        }

        if (! $this->input->isInteractive()) {
            return false;
        }

        $this->components->warn("Risk level: {$manifest['prediction']['risk_level']}");
        $this->line("  <options=bold>Fix:</> {$manifest['fix']}");

        return $this->components->confirm('Apply this change?', false);
    }

    private function printResult(RunResult $result): void
    {
        if ($result->manifest !== []) {
            $prediction = $result->manifest['prediction'] ?? [];

            $this->components->twoColumnDetail('<options=bold>Evidence</>', $this->clip($result->manifest['evidence'] ?? '-'));
            $this->components->twoColumnDetail('<options=bold>Root cause</>', $this->clip($result->manifest['root_cause'] ?? '-'));
            $this->components->twoColumnDetail('<options=bold>Fix</>', $this->clip($result->manifest['fix'] ?? '-'));
            $this->components->twoColumnDetail('<options=bold>Expected</>', $this->clip($prediction['expected_result'] ?? '-'));
            $this->components->twoColumnDetail('<options=bold>Risk</>', is_string($prediction['risk_level'] ?? null) ? $prediction['risk_level'] : '-');
        }

        if ($result->files !== []) {
            $this->components->twoColumnDetail('<options=bold>Files</>', implode(', ', $result->files));
        }

        if ($result->evaluation !== null) {
            $this->newLine();
            $this->table(['Metric', 'Predicted', 'Measured', 'Result'], array_map(fn (array $check) => [
                $check['metric'],
                json_encode($check['expected']),
                json_encode($check['actual']),
                $check['passed'] ? '<fg=green>✔</>' : '<fg=red>✘ '.$check['reason'].'</>',
            ], $result->evaluation->checks));
        }

        if (! $result->successful() && ! empty($result->actual['failed_tests'])) {
            $this->components->bulletList(array_map(fn ($t) => "Failed: {$t}", $result->actual['failed_tests']));
        }

        if (in_array($result->status, [RunResult::FAILED, RunResult::ERROR], true) && ! empty($result->actual['output']) && $this->output->isVerbose()) {
            $this->line($result->actual['output']);
        }

        $this->newLine();

        match ($result->status) {
            RunResult::SUCCESS, RunResult::CHECKED => $this->components->info("[{$result->status}] {$result->message}"),
            RunResult::UNVERIFIED, RunResult::ABORTED => $this->components->warn("[{$result->status}] {$result->message}"),
            default => $this->components->error("[{$result->status}] {$result->message}"),
        };
    }

    private function printJson(RunResult $result): void
    {
        $actual = $result->actual;
        unset($actual['output']);

        $this->line(json_encode([
            'status' => $result->status,
            'successful' => $result->successful(),
            'message' => $result->message,
            'files' => $result->files,
            'applied' => $result->applied,
            'rolled_back' => $result->rolledBack,
            'checks' => $result->evaluation?->checks ?? [],
            'actual' => $actual,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function clip(mixed $text): string
    {
        $text = is_string($text) ? $text : json_encode($text);

        return mb_strlen($text) > 120 ? mb_substr($text, 0, 119).'…' : $text;
    }
}
