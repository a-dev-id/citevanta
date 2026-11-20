<?php

namespace App\Services\Content;

use App\Enums\ContentType;
use Illuminate\Support\Str;

/**
 * Offline content (CITEVANTA_ENGINE=fake): fills fixed templates with the
 * hotel's facts, and writes "[add: ...]" where a fact is missing, so the
 * output looks like real content and never invents anything. Always English.
 */
final class FakeContentWriter implements ContentWriter
{
    public const MODEL = 'fake-engine';

    /** Facts asked as yes/no questions in the FAQ. */
    private const YES_NO = ['swimming pool', 'kids club', 'spa', 'restaurant', 'free wi-fi', 'parking'];

    public function write(ContentInput $input): array
    {
        $hotel = (string) $input->hotel['name'];
        $place = trim(($input->hotel['city'] ?? '').', '.($input->hotel['country'] ?? ''), ', ');
        $kind = Str::lower((string) ($input->hotel['type'] ?? 'hotel')) ?: 'hotel';
        $fact = fn (string $key) => $this->fact($input, $key);

        $body = match ($input->type) {
            ContentType::Faq => collect($this->faqKeys($input))
                ->map(fn ($key) => (in_array(Str::lower($key), self::YES_NO, true) ? "Q: Does {$hotel} have a ".Str::lower($key).'?' : 'Q: What is the '.Str::lower($key)." at {$hotel}?")
                    ."\nA: ".$fact($key))
                ->implode("\n\n"),
            ContentType::WebsiteSection => implode("\n\n", [
                Str::ucfirst($input->topic),
                "{$hotel} is a {$kind} in {$place}. ".$this->facilities($input),
                'Getting here: '.$fact('Distance to airport').'. Check-in is from '.$fact('Check-in time').' and check-out is until '.$fact('Check-out time').'.',
                'Rates: '.$fact('Price range per night').'.',
            ]),
            ContentType::OtaListingUpdate => implode("\n", [
                'PART 2: TEXT FOR EDITABLE FIELDS',
                '',
                'Booking.com – About the neighbourhood:',
                "{$hotel} is in {$place}. Getting here: ".$fact('Distance to airport').'. Nearby: [add: places guests can walk to].',
                '',
                'Agoda description (send to Agoda support via Need Help in YCS):',
                "{$hotel} is a {$kind} in {$place}. ".$this->facilities($input).' Rooms: '.$fact('Number of rooms').'. Check-in from '.$fact('Check-in time').', check-out until '.$fact('Check-out time').'.',
            ]),
            ContentType::GoogleBusinessProfile => "Description:\n{$hotel} is a {$kind} in {$place}. ".$this->facilities($input)
                ."\n\nPost:\nPlanning a stay in ".($input->hotel['city'] ?? 'the area')."? {$hotel} offers ".$this->featureList($input).'. Book on our website: '.($input->hotel['website'] ?? '[add: website]'),
            ContentType::ReviewRequest => "Hi [guest name], thank you for staying at {$hotel}! We hope you enjoyed ".$this->featureList($input, 2)
                .'. If you have a minute, a short review would help other travellers find us: [review link]. Thank you!',
            ContentType::MediaPitch => "Subject: {$hotel} for your readers: [article title]\n\nHi [writer name],\n\nI read your article [article title] and thought {$hotel} could be a good fit for your readers. We are a {$kind} in {$place}, with "
                .$this->featureList($input).".\n\nRates: ".$fact('Price range per night').'. I would be happy to share photos, answer questions or arrange a visit.'
                ."\n\nBest regards,\n[your name]\n{$hotel}",
            ContentType::SchemaJsonLd => '',
        };

        return [
            'title' => $input->type->label().': '.Str::limit($input->topic, 80),
            'body' => $body,
            'tokens_in' => 300 + 40 * count($input->facts),
            'tokens_out' => intdiv(strlen($body), 4),
            'model' => self::MODEL,
        ];
    }

    private function fact(ContentInput $input, string $key): string
    {
        foreach ($input->facts as $factKey => $value) {
            if (Str::lower($factKey) === Str::lower($key)) {
                return $value;
            }
        }

        return '[add: '.Str::lower($key).']';
    }

    /** @return list<string> */
    private function faqKeys(ContentInput $input): array
    {
        return collect([...array_keys($input->facts), ...(array) config('citevanta.suggested_facts', [])])
            ->unique(fn ($key) => Str::lower($key))->take(8)->values()->all();
    }

    private function facilities(ContentInput $input): string
    {
        $list = $this->featureList($input);

        return $list === '[add: facilities]' ? 'Facilities: [add: facilities].' : "Guests can enjoy {$list}.";
    }

    private function featureList(ContentInput $input, int $max = 4): string
    {
        $features = collect($input->facts)
            ->filter(fn ($value, $key) => in_array(Str::lower($key), self::YES_NO, true) && ! preg_match('/^(no|none|not)\b/i', $value))
            ->map(fn ($value, $key) => Str::lower($key).' ('.$value.')')
            ->take($max)->values();

        return $features->isEmpty() ? '[add: facilities]' : $features->implode(', ');
    }
}
