<?php

namespace App\Support\Engine;

use App\Exceptions\Domain\EngineException;
use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use JsonException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Runs one of the engine's TypeScript scripts (config/engine.php `scripts`) in a Node process: the input is encoded
 * as JSON on stdin and the answer is decoded from JSON on stdout. Every way it can go wrong is a domain exception
 * with a translated message (a timeout, a non-zero exit, output that is not JSON), so callers never see a raw
 * process error. Reusable by every later task that needs the engine on the server.
 *
 *   $answer = app(EngineRunner::class)->run('validate-rule-set', ['ruleSet' => $assembled]);
 */
final class EngineRunner
{
    /** Longest stretch of a script's error output that is kept in the exception message. */
    private const DETAIL_LIMIT = 300;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws EngineException When the script is unknown, times out, exits with an error or answers with something that is not a JSON object.
     */
    public function run(string $script, array $input): array
    {
        $path = config("engine.scripts.{$script}");

        if (! is_string($path)) {
            throw EngineException::unknownScript($script);
        }

        try {
            $payload = json_encode($input, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        } catch (JsonException) {
            throw EngineException::invalidInput($script);
        }

        $timeout = (int) config('engine.timeout');

        try {
            $result = Process::path((string) config('engine.package_path'))
                ->timeout($timeout)
                ->input($payload)
                ->run([(string) config('engine.node_binary'), ...config('engine.node_flags'), $path]);
        } catch (LaravelProcessTimedOutException|ProcessTimedOutException) {
            throw EngineException::timeout($script, $timeout);
        }

        if ($result->failed()) {
            throw EngineException::failed($script, (int) $result->exitCode(), mb_substr(trim($result->errorOutput()), 0, self::DETAIL_LIMIT));
        }

        try {
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw EngineException::invalidOutput($script);
        }

        if (! is_array($decoded)) {
            throw EngineException::invalidOutput($script);
        }

        return $decoded;
    }
}
