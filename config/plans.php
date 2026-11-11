<?php

return [
    'default' => 'free',
    'version' => 'citevanta-monthly-2026-11-10-v1',
    'request_rate_per_minute' => ['free' => 3, 'paid' => 30],
    'implemented_providers' => ['openai'],
    'credit_weights' => ['simulated' => ['*' => 1], 'openai' => ['*' => 1]],
    'features_available' => ['campaigns', 'advanced_improvements', 'basic_reports'],
    'definitions' => [
        'free' => ['price_cents' => 0, 'brands' => 1, 'members' => 1, 'monthly_searches' => 5, 'batch_size' => 0, 'repetitions' => 1, 'providers' => ['openai'], 'platform_limit' => 1, 'retention_days' => 30, 'features' => ['basic_reports']],
        'starter' => ['price_cents' => 2900, 'brands' => 1, 'members' => 2, 'monthly_searches' => 450, 'batch_size' => 20, 'repetitions' => 3, 'providers' => ['openai'], 'platform_limit' => 1, 'retention_days' => 90, 'features' => ['basic_reports', 'campaigns', 'scheduled_monitoring']],
        'pro' => ['price_cents' => 9900, 'brands' => 5, 'members' => 5, 'monthly_searches' => 1500, 'batch_size' => 100, 'repetitions' => 5, 'providers' => ['openai'], 'platform_limit' => 5, 'retention_days' => 365, 'features' => ['basic_reports', 'campaigns', 'scheduled_monitoring', 'advanced_improvements', 'advanced_analytics', 'market_comparisons', 'advanced_exports', 'multi_platform']],
        'agency' => ['price_cents' => 24900, 'brands' => 10, 'members' => 20, 'monthly_searches' => 4000, 'batch_size' => 1000, 'repetitions' => 10, 'providers' => ['openai'], 'platform_limit' => null, 'retention_days' => 730, 'features' => ['basic_reports', 'campaigns', 'scheduled_monitoring', 'advanced_improvements', 'advanced_analytics', 'market_comparisons', 'advanced_exports', 'multi_platform', 'multi_client', 'white_label']],
    ],
];
