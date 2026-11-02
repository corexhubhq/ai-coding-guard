<?php

namespace AICodingGuard;

use AICodingGuard\Contracts\CodeApplierInterface;
use AICodingGuard\Contracts\TestRunnerInterface;
use AICodingGuard\Exceptions\EnvironmentException;
use AICodingGuard\Exceptions\GuardException;
use AICodingGuard\Support\DiffParser;
use AICodingGuard\Support\PathGuard;
use AICodingGuard\Support\ResponseParser;
use Throwable;

/**
 * Runs one AI change through the full cycle:
 *
 *   Hypothesis  — CHANGE_MANIFEST must explain evidence → root cause → fix
 *   Prediction  — measurable metrics, validated against what can be measured
 *   Experiment  — diff checked (paths, git apply --check), applied, tests run
 *   Evaluation  — prediction vs measured metrics; mismatch → rollback
 *   Learning    — every outcome caused by the AI is stored in memory
 */
class Runner
{
    public function __construct(
        private readonly CodeApplierInterface $applier,
        private readonly TestRunnerInterface $tester,
        private readonly ManifestValidator $validator,
        private readonly PathGuard $paths,
        private readonly Memory $memory,
        /** @var list<string> */
        private readonly array $confirmRiskLevels = ['high'],
    ) {
    }

    /**
     * @param  array<string, mixed>|string  $response  raw AI answer or decoded array
     * @param  array{dry?: bool, skip_tests?: bool, filter?: ?string, confirm?: callable(array): bool}  $options
     *                                                                                                          "confirm" approves risky changes; without it they are aborted.
     */
    public function handle(array|string $response, array $options = []): RunResult
    {
        // 1. Hypothesis + prediction: the response must carry a valid manifest.
        try {
            $parsed = is_string($response) ? ResponseParser::parse($response) : ResponseParser::fromArray($response);
            $manifest = $parsed['CHANGE_MANIFEST'];
            $diff = $parsed['CODE_DIFF'];

            $this->validator->validate($manifest);

            // 2. Experiment design: which files, may they be touched, does it apply?
            $files = DiffParser::files($diff);
            $this->paths->check($files);
            $this->applier->check($diff);
        } catch (EnvironmentException $e) {
            return new RunResult(RunResult::ABORTED, $e->getMessage(), $manifest ?? [], $files ?? []);
        } catch (GuardException $e) {
            return $this->rejected($e->getMessage(), $manifest ?? [], $files ?? []);
        }

        $risk = strtolower(trim($manifest['prediction']['risk_level']));

        if ($options['dry'] ?? false) {
            return new RunResult(RunResult::CHECKED, 'Dry run: manifest is valid and the diff applies cleanly. Nothing was changed.', $manifest, $files);
        }

        if (in_array($risk, $this->confirmRiskLevels, true)) {
            $confirm = $options['confirm'] ?? null;

            if (! is_callable($confirm) || ! $confirm($manifest)) {
                return new RunResult(RunResult::ABORTED, "risk_level \"{$risk}\" needs approval; change was not applied.", $manifest, $files);
            }
        }

        // 3. Experiment: apply and measure.
        try {
            $this->applier->apply($diff);
        } catch (EnvironmentException $e) {
            return new RunResult(RunResult::ABORTED, $e->getMessage(), $manifest, $files);
        } catch (GuardException $e) {
            return $this->rejected($e->getMessage(), $manifest, $files);
        } catch (Throwable $e) {
            return $this->error($e, $manifest, $files);
        }

        if ($options['skip_tests'] ?? false) {
            return new RunResult(RunResult::UNVERIFIED, 'Change applied WITHOUT running tests: prediction not evaluated, nothing recorded. Use ai-coding-guard:rollback to undo.', $manifest, $files, applied: true);
        }

        try {
            $actual = $this->tester->run(['filter' => $options['filter'] ?? null]);

            // 4. Evaluation: prediction vs reality.
            $evaluation = Evaluator::evaluate($manifest['prediction']['metrics'], $actual);
        } catch (Throwable $e) {
            return $this->error($e, $manifest, $files);
        }

        if (! $evaluation->passed()) {
            $this->learn('fail', $manifest, $files, $evaluation->summary(), $actual, $evaluation);

            try {
                $this->applier->rollback();
            } catch (Throwable $e) {
                return new RunResult(RunResult::ERROR, 'Prediction did not match reality and ROLLBACK FAILED: '.$e->getMessage(), $manifest, $files, $actual, $evaluation, applied: true);
            }

            return new RunResult(RunResult::FAILED, 'Prediction did not match reality → change rolled back. '.$evaluation->summary(), $manifest, $files, $actual, $evaluation, applied: true, rolledBack: true);
        }

        // 5. Learning.
        $this->learn('success', $manifest, $files, null, $actual, $evaluation);

        return new RunResult(RunResult::SUCCESS, 'Prediction confirmed; change kept. Review and commit it (or run ai-coding-guard:rollback).', $manifest, $files, $actual, $evaluation, applied: true);
    }

    private function rejected(string $reason, array $manifest, array $files): RunResult
    {
        $this->learn('rejected', $manifest, $files, $reason);

        return new RunResult(RunResult::REJECTED, $reason, $manifest, $files);
    }

    private function error(Throwable $e, array $manifest, array $files): RunResult
    {
        $message = $e->getMessage();
        $rolledBack = false;

        if ($this->applier->canRollback()) {
            try {
                $this->applier->rollback();
                $rolledBack = true;
            } catch (Throwable $rollbackError) {
                $message .= ' | ROLLBACK FAILED: '.$rollbackError->getMessage();
            }
        }

        $this->learn('error', $manifest, $files, $message);

        return new RunResult(RunResult::ERROR, $message.($rolledBack ? ' → change rolled back.' : ''), $manifest, $files, applied: true, rolledBack: $rolledBack);
    }

    /**
     * Stores a compact lesson (no test output) so memory stays small and useful in prompts.
     */
    private function learn(string $type, array $manifest, array $files, ?string $reason, array $actual = [], ?EvaluationResult $evaluation = null): void
    {
        $prediction = is_array($manifest['prediction'] ?? null) ? $manifest['prediction'] : [];

        $lesson = array_filter([
            'type' => $type,
            'evidence' => $manifest['evidence'] ?? null,
            'root_cause' => $manifest['root_cause'] ?? null,
            'fix' => $manifest['fix'] ?? null,
            'expected_result' => $prediction['expected_result'] ?? null,
            'risk_level' => $prediction['risk_level'] ?? null,
            'predicted' => $prediction['metrics'] ?? null,
            'actual' => $evaluation ? array_column($evaluation->checks, 'actual', 'metric') : null,
            'failed_tests' => $actual['failed_tests'] ?? null,
            'files' => $files,
            'reason' => $reason,
        ], fn ($value) => $value !== null && $value !== []);

        try {
            $this->memory->add($lesson);
        } catch (Throwable) {
            // Memory is best effort: never let it hide the real outcome.
        }
    }
}
