<?php

namespace App\Services\Content;

/**
 * Writes one piece of copy-ready content from the hotel's facts only
 * (OpenAiContentWriter, or FakeContentWriter with the fake engine).
 */
interface ContentWriter
{
    /**
     * @return array{title: string, body: string, tokens_in: int, tokens_out: int, model: string}
     */
    public function write(ContentInput $input): array;
}
