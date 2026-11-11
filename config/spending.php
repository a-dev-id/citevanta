<?php

return [
    'execution_enabled' => (bool) env('AI_EXECUTION_ENABLED', false),
    'automatic_organization_limits' => (bool) env('AI_AUTOMATIC_ORG_LIMITS', false),
    'organization_exposure_ceiling_micros' => (int) env('AI_ORG_EXPOSURE_CEILING_MICROS', 100000000),
    'monthly_limit_micros' => (int) round((float) env('AI_MONTHLY_LIMIT_USD', 0) * 1000000),
    'batch_request_limit' => 20,
    'safety_percent' => 25,
    'max_repetitions' => 10,
    'max_attempts' => 3,
    'large_batch_warning' => 10,
    'cron_request_limit' => 3,
    'runtime_seconds' => 120,
    'lease_seconds' => 180,
    'pricing' => [
        'version' => 'openai-standard-usd-2026-11-10-v2',
        'verified_on' => '2026-11-10',
        'valid_until' => '2026-12-10',
        'currency' => 'USD',
        'sources' => ['https://developers.openai.com/api/docs/pricing', 'https://developers.openai.com/api/docs/models/gpt-4.1-mini', 'https://developers.openai.com/api/docs/models/gpt-6-luna', 'https://developers.openai.com/api/docs/guides/prompt-caching'],
        'models' => [
            'gpt-6-luna' => ['aliases' => ['gpt-6-luna'], 'input_micros_per_million' => 100000, 'cached_micros_per_million' => 10000, 'cache_write_30m_micros_per_million' => 125000, 'cache_write_12h_micros_per_million' => 250000, 'require_cache_write_usage' => true, 'search_input_is_fixed' => false, 'output_micros_per_million' => 500000, 'search_micros' => 10000, 'search_input_tokens' => 8000, 'context_input_ceiling' => 128000, 'max_tool_calls' => 1, 'max_output_tokens' => 2500],
            'gpt-4.1-mini' => ['aliases' => ['gpt-4.1-mini', 'gpt-4.1-mini-2025-04-14'], 'input_micros_per_million' => 400000, 'cached_micros_per_million' => 100000, 'output_micros_per_million' => 1600000, 'search_micros' => 10000, 'search_input_tokens' => 8000, 'context_input_ceiling' => 128000, 'max_tool_calls' => 1, 'max_output_tokens' => 2500],
        ],
    ],
];
