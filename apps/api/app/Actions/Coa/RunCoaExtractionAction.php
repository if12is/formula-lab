<?php

namespace App\Actions\Coa;

use App\Exceptions\Domain\AssistantException;
use App\Exceptions\Domain\CoaException;
use App\Integrations\Anthropic\MessagesClient;
use App\Support\Assistant\AssistantPayload;
use App\Support\Assistant\TurnUsage;
use App\Support\Coa\CoaAnswer;
use App\Support\Coa\CoaAnswerParser;
use App\Support\Coa\CoaReportSchema;
use Illuminate\Support\Facades\Storage;

/**
 * Sends one certificate (a PDF or an image) to the model with the forced `report_coa` tool and reads the structured answer. The model is
 * told to leave a field null when it cannot read it; what it returns is checked afterwards in code (CoaFieldChecker), never trusted as is.
 */
final class RunCoaExtractionAction
{
    public function __construct(
        private readonly MessagesClient $client,
        private readonly CoaAnswerParser $parser,
    ) {}

    /**
     * @throws AssistantException When the provider fails.
     * @throws CoaException When the file cannot be read or the answer is not the structure that was asked for.
     */
    public function __invoke(string $path, string $mime, TurnUsage $usage): CoaAnswer
    {
        $disk = Storage::disk((string) config('coa.disk'));
        if (! $disk->exists($path)) {
            throw CoaException::fileMissing();
        }

        $response = $this->client->create([
            'model' => (string) config('assistant.model'),
            'max_tokens' => (int) config('coa.max_output_tokens'),
            ...AssistantPayload::sampling(),
            'system' => AssistantPayload::system(CoaReportSchema::system()),
            'tools' => AssistantPayload::tools([CoaReportSchema::definition()]),
            'tool_choice' => ['type' => 'tool', 'name' => CoaReportSchema::TOOL],
            'messages' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => $mime === 'application/pdf' ? 'document' : 'image',
                        'source' => ['type' => 'base64', 'media_type' => $mime, 'data' => base64_encode((string) $disk->get($path))],
                    ],
                    ['type' => 'text', 'text' => (string) __('coa.prompt.request', [], CoaReportSchema::LOCALE)],
                ],
            ]],
        ]);

        $usage->apiCalls++;
        $usage->inputTokens += $response->inputTokens;
        $usage->outputTokens += $response->outputTokens;
        $usage->cacheCreationInputTokens += $response->cacheCreationInputTokens;
        $usage->cacheReadInputTokens += $response->cacheReadInputTokens;

        return $this->parser->parse($response);
    }
}
