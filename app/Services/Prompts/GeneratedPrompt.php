<?php

namespace App\Services\Prompts;

use App\Enums\Persona;

final readonly class GeneratedPrompt
{
    public function __construct(
        public string $text,
        public Persona $persona,
        public string $language,
    ) {}
}
