<?php

namespace App\Services\Content;

use App\Enums\ContentType;
use App\Services\Insights\OpenAiJsonCaller;

/**
 * Content with the analysis model (strict JSON schema: title + body).
 */
final class OpenAiContentWriter implements ContentWriter
{
    public function __construct(private readonly OpenAiJsonCaller $caller) {}

    public function write(ContentInput $input): array
    {
        $response = $this->caller->call('hotel_content', [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['title', 'body'],
            'properties' => [
                'title' => ['type' => 'string', 'description' => 'A short title for this piece (for the team, not for publishing).'],
                'body' => ['type' => 'string', 'description' => 'The copy-ready text, plain text with line breaks.'],
            ],
        ], self::instructions($input), self::input($input));

        return [
            'title' => trim((string) ($response->data['title'] ?? '')),
            'body' => trim((string) ($response->data['body'] ?? '')),
            'tokens_in' => $response->tokensIn,
            'tokens_out' => $response->tokensOut,
            'model' => $response->model,
        ];
    }

    private static function instructions(ContentInput $input): string
    {
        $limits = (array) config('citevanta.content.limits', []);

        $task = match ($input->type) {
            ContentType::Faq => 'Write 5-8 FAQ questions and answers for the hotel\'s website about the topic. Format: "Q: ..." then "A: ..." on the next line, a blank line between pairs.',
            ContentType::WebsiteSection => 'Write a website section about the topic: a heading on the first line, then 2-4 short paragraphs.',
            ContentType::OtaListingUpdate => 'Booking.com and Agoda write the main description themselves, so write only the two texts the hotel can edit. '
                .'Start with the line "PART 2: TEXT FOR EDITABLE FIELDS". Then, after the line "Booking.com – About the neighbourhood:", a text about the location and what is nearby, at most '
                .($limits['booking_neighbourhood'] ?? 2000).' characters. Then, after the line "Agoda description (send to Agoda support via Need Help in YCS):", a property description of at most '
                .($limits['agoda_description_words'] ?? 160).' words. No contact details, links or prices (OTAs do not allow them).',
            ContentType::GoogleBusinessProfile => 'Write a Google Business Profile description (at most '.($limits['gbp_description'] ?? 750).' characters, no links or promotions) after the line "Description:", then a Google post (at most '.($limits['gbp_post'] ?? 1500).' characters) after the line "Post:".',
            ContentType::ReviewRequest => 'Write a short, warm message asking a recent guest for a review, mentioning 1-2 specific features from the facts. Under '.($limits['review_request'] ?? 600).' characters, suitable for WhatsApp or email. Use [guest name] and [review link] placeholders.',
            ContentType::MediaPitch => 'Write a short pitch email to a travel writer or blogger: subject line first ("Subject: ..."), then 120-200 words saying why the hotel fits their readers, with facts only. Use [writer name] and [article title] placeholders.',
            ContentType::SchemaJsonLd => 'Not used.',
        };

        return <<<TXT
        You write copy-ready marketing text for a hotel, in {$input->languageName}.
        {$task}
        Rules:
        - Use ONLY the hotel data and facts given. Never invent facilities, prices, distances, awards, reviews or anything else.
        - When the text needs a detail that is missing, write a visible placeholder like [add: distance to airport] instead.
        - Natural, warm and specific; no clichés like "hidden gem" or "paradise", no exaggeration.
        TXT;
    }

    private static function input(ContentInput $input): string
    {
        $hotel = collect($input->hotel)->filter()->map(fn ($value, $key) => "- {$key}: {$value}")->implode("\n");
        $facts = $input->facts === [] ? '(none)' : collect($input->facts)->map(fn ($value, $key) => "- {$key}: {$value}")->implode("\n");
        $missing = $input->missingFacts === [] ? '(none)' : implode(', ', $input->missingFacts);

        return "Hotel:\n{$hotel}\n\nFacts:\n{$facts}\n\nMissing facts (use placeholders if needed): {$missing}\n\nTopic: {$input->topic}\nWhy this content is needed: {$input->why}";
    }
}
