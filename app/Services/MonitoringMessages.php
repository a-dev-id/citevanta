<?php

namespace App\Services;

class MonitoringMessages
{
    public static function publicText(?string $message): string
    {
        if ($message && preg_match('/cost|budget|spend|billing|token|pricing|reservation|monthly limit|financial|API key|NANDINI_OPENAI|OPENAI_API|USD|\$/i', $message)) {
            return 'Monitoring is currently unavailable. Please try again later or contact support.';
        }

        return $message ?? '';
    }
}
