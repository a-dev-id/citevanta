<?php

return [
    'openai_key' => env('NANDINI_OPENAI_API_KEY') ?: env('OPENAI_API_KEY'),
    'ca_bundle' => env('OPENAI_CA_BUNDLE'),
    'model' => env('OPENAI_MODEL', 'gpt-6-luna'),
    'timeout' => 90,
    'max_output_tokens' => 2500,
    'daily_limit' => (int) env('AI_DAILY_TEST_LIMIT', 10),
];
