<?php

use App\Services\Assistant\Tools\CalculateFormulaTool;
use App\Services\Assistant\Tools\CheckComplianceTool;
use App\Services\Assistant\Tools\ConvertUnitsTool;
use App\Services\Assistant\Tools\ExplainResultTool;
use App\Services\Assistant\Tools\ExtractCoaTool;
use App\Services\Assistant\Tools\GetMaterialTool;
use App\Services\Assistant\Tools\OptimizeTierTool;

/*
| The Claude Haiku formulation assistant (phase P). The model only phrases and orchestrates: every number in a reply comes from a
| tool that calls the engine, and the number guard (App\Support\Assistant\NumberGuard) rejects a reply that has any other number.
| The API key is read here from the environment and nowhere else; it never reaches a browser, a log or a database row.
*/
return [
    'provider' => [
        /* Server-side only. Empty = the assistant answers 503 and nothing is sent anywhere. */
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'api_version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
        'timeout_seconds' => (int) env('ASSISTANT_TIMEOUT', 60),
    ],

    /* The model id is configuration, never code: switching to a newer Haiku is an environment change. */
    'model' => env('ASSISTANT_MODEL', 'claude-haiku-5-5'),

    /* Deterministic answers (P-08): no sampling. */
    'temperature' => 0,
    'max_output_tokens' => (int) env('ASSISTANT_MAX_OUTPUT_TOKENS', 1024),

    /* Longest request message in characters, and the most earlier messages a request may carry. */
    'max_message_chars' => 2000,
    'max_history_messages' => 20,

    /* Model <-> tool round trips allowed for one question, so a looping model cannot run up the bill. */
    'max_tool_rounds' => (int) env('ASSISTANT_MAX_TOOL_ROUNDS', 6),

    /* Most trade materials one get_material answer lists. */
    'material_limit' => 5,

    /* Requests per minute and per client (IP until accounts exist, U-01). An operations choice, not a data value. */
    'rate_limit_per_minute' => (int) env('ASSISTANT_RATE_LIMIT_PER_MINUTE', 20),

    'guard' => [
        /* How many times a reply with an unsupported number is sent back to the model before the safe fallback is returned. */
        'max_retries' => (int) env('ASSISTANT_GUARD_MAX_RETRIES', 2),

        /* A number right after one of these words names a standard, code or category, not a quantity (ES 1562, ISO 22716, CAS 7732-18-5). */
        'reference_prefixes' => ['ES', 'EN', 'ISO', 'IEC', 'CAS', 'IFRA', 'OIML', 'EDREX', 'INS', 'Annex', 'Category', 'Cat', 'Step', 'pH-', 'الفئة', 'فئة', 'المواصفة', 'مواصفة', 'خطوة'],

        /* Keys of a tool answer whose value is an identifier or a checksum: the digits inside never count as an answer's numbers. */
        'identifier_keys' => ['id', 'availableMaterialIds', 'uuid', 'hash', 'inputHash', 'outputHash', 'dataset_version', 'datasetVersion', 'sourceId', 'source_id', 'ruleId', 'ruleSetId', 'ruleSetVersion', 'ruleVersion', 'materialId', 'formulaVersionId', 'messageKey', 'key', 'reason', 'optimizerVersion', 'engineVersion', 'packageVersion', 'extractionId'],
    ],

    /* The tools the model may call, in the order they are offered (App\Support\Assistant\AssistantTool). */
    'tools' => [
        GetMaterialTool::class,
        CalculateFormulaTool::class,
        OptimizeTierTool::class,
        CheckComplianceTool::class,
        ConvertUnitsTool::class,
        ExplainResultTool::class,
        ExtractCoaTool::class,
    ],

    /*
    | Cost of a call. The prices are NULL until a person writes them with their source (orchestration/HUMAN-TODO.md): a null price
    | means the cost of a request is stored as NULL, never as 0 and never from a remembered price list.
    | Format: decimal string, money per 1,000,000 tokens, in `currency`.
    */
    'pricing' => [
        'currency' => env('ASSISTANT_PRICE_CURRENCY'),
        'input_per_million_tokens' => env('ASSISTANT_PRICE_INPUT_PER_MTOK'),
        'output_per_million_tokens' => env('ASSISTANT_PRICE_OUTPUT_PER_MTOK'),
        'source' => env('ASSISTANT_PRICE_SOURCE'),
    ],

    /*
    | Tier profiles for optimize_tier, by product type then tier (economy | mid | premium), each in the optimizer's TierProfile shape
    | (packages/optimizer/src/profile.ts) with a source for every value. EMPTY until the chemist's decision (J-02): the tool then
    | answers `missing: tier_profile` and the model says so. No profile is invented here.
    */
    'tier_profiles' => [],
];
