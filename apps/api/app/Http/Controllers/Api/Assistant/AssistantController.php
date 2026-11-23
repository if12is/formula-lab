<?php

namespace App\Http\Controllers\Api\Assistant;

use App\Exceptions\Domain\AssistantException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Assistant\AskAssistantRequest;
use App\Services\Assistant\AssistantService;
use App\Support\Assistant\AssistantSessionKey;
use Illuminate\Http\JsonResponse;

class AssistantController extends Controller
{
    public function __construct(private AssistantService $assistant, private AssistantSessionKey $sessionKey) {}

    public function ask(AskAssistantRequest $request): JsonResponse
    {
        try {
            $answer = $this->assistant->ask($request->validated('question'), $request->history(), $request->user()?->getAuthIdentifier(), consentKey: $this->sessionKey->for($request));
        } catch (AssistantException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->status);
        }

        return response()->json([...$answer['reply']->toArray(), 'requestId' => $answer['requestId']]);
    }
}
