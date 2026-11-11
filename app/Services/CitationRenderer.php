<?php

namespace App\Services;

use App\Models\AiResponse;
use Illuminate\Support\HtmlString;

class CitationRenderer
{
    public function render(AiResponse $response): HtmlString
    {
        $text = $response->text ?? '';
        $cursor = 0;
        $html = '';
        foreach ($response->citations->sortBy('start_index') as $citation) {
            $start = $citation->start_index;
            $end = $citation->end_index;
            if ($start === null || $end === null || $start < $cursor || $end <= $start || $end > mb_strlen($text)) {
                continue;
            }
            $html .= e(mb_substr($text, $cursor, $start - $cursor));
            $html .= '<a href="'.e($citation->url).'" target="_blank" rel="noopener noreferrer">'.e(mb_substr($text, $start, $end - $start)).'</a>';
            $cursor = $end;
        }

        return new HtmlString($html.e(mb_substr($text, $cursor)));
    }
}
