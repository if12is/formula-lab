<?php

namespace Tests\Feature\Compliance;

use App\Exceptions\Domain\EngineException;
use App\Support\Engine\EngineRunner;
use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Process\FakeProcessResult;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

/** The server-side bridge to the engine: JSON in, JSON out, every failure a translated domain exception. */
class EngineRunnerTest extends TestCase
{
    private function runner(): EngineRunner
    {
        return app(EngineRunner::class);
    }

    public function test_it_runs_the_real_engine_script_and_returns_its_answer(): void
    {
        $answer = $this->runner()->run('validate-rule-set', ['ruleSet' => [
            'id' => 'demo', 'version' => '1', 'status' => 'draft', 'rules' => [],
        ]]);

        $this->assertTrue($answer['ok']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $answer['hash']);
    }

    public function test_the_engine_reports_validation_problems_as_an_answer_not_an_error(): void
    {
        $answer = $this->runner()->run('validate-rule-set', ['ruleSet' => ['id' => 'demo']]);

        $this->assertFalse($answer['ok']);
        $this->assertContains('dsl.required', array_column($answer['missing'], 'reason'));
    }

    public function test_it_sends_the_input_as_json_on_stdin_and_runs_the_engine_package_script(): void
    {
        Process::fake(['*' => Process::result('{"ok":true,"hash":"abc"}')]);

        $this->runner()->run('validate-rule-set', ['ruleSet' => ['id' => 'ثابت', 'limit' => '0.5']]);

        Process::assertRan(function (PendingProcess $process): bool {
            return $process->input === '{"ruleSet":{"id":"ثابت","limit":"0.5"}}'
                && str_ends_with((string) $process->path, 'packages/engine')
                && $process->timeout === config('engine.timeout')
                && in_array('--experimental-transform-types', $process->command, true)
                && end($process->command) === 'scripts/validate-rule-set.ts';
        });
    }

    public function test_a_script_that_exits_with_an_error_becomes_an_engine_exception_with_its_message(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'boom: not json', exitCode: 2)]);

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('validate-rule-set', ['ruleSet' => []]), 'boom: not json');
    }

    public function test_a_timeout_becomes_an_engine_exception(): void
    {
        Process::fake(fn () => throw new LaravelProcessTimedOutException(
            new ProcessTimedOutException(new SymfonyProcess(['node']), ProcessTimedOutException::TYPE_GENERAL),
            new FakeProcessResult(exitCode: 143),
        ));
        config(['engine.timeout' => 7]);

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('validate-rule-set', ['ruleSet' => []]), '7');
    }

    public function test_a_real_process_that_outlives_the_timeout_is_stopped(): void
    {
        config(['engine.node_binary' => 'sleep', 'engine.node_flags' => [], 'engine.scripts.slow' => '5', 'engine.timeout' => 1]);
        $started = microtime(true);

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('slow', []), '1');
        $this->assertLessThan(4, microtime(true) - $started);
    }

    public function test_output_that_is_not_a_json_object_is_refused(): void
    {
        foreach (['not json at all', '"just a string"', ''] as $output) {
            Process::fake(['*' => Process::result($output)]);

            $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('validate-rule-set', ['ruleSet' => []]));
        }
    }

    public function test_an_unknown_script_name_never_starts_a_process(): void
    {
        Process::fake();

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('rm-rf', []), 'rm-rf');
        Process::assertNothingRan();
    }

    public function test_input_that_cannot_be_encoded_is_refused_before_a_process_starts(): void
    {
        Process::fake();

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('validate-rule-set', ['bad' => "\xB1\x31"]));
        Process::assertNothingRan();
    }

    public function test_the_error_messages_follow_the_active_language(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'x', exitCode: 1)]);
        app()->setLocale('en');

        $this->assertThrowsException(EngineException::class, fn () => $this->runner()->run('validate-rule-set', ['ruleSet' => []]), 'failed with code 1');
    }
}
