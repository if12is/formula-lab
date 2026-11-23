<?php

namespace App\Support\Assistant;

/** What one question to the assistant used so far; filled while it runs, so a failure part-way still leaves a record of the tokens spent. */
final class TurnUsage
{
    public int $apiCalls = 0;

    public int $toolCalls = 0;

    public int $guardRetries = 0;

    public int $inputTokens = 0;

    public int $outputTokens = 0;

    /** Prompt tokens written to and read from the provider's cache (P-08); a read costs a fraction of a fresh input token. */
    public int $cacheCreationInputTokens = 0;

    public int $cacheReadInputTokens = 0;
}
