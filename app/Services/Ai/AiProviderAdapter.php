<?php

namespace App\Services\Ai;

interface AiProviderAdapter
{
    public function execute(array $settings): array;
}
