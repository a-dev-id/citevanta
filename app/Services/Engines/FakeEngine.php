<?php

namespace App\Services\Engines;

use App\Services\Analysis\HotelNameMatcher;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Offline stand-in for a real AI assistant (CITEVANTA_ENGINE=fake).
 *
 * Produces realistic hotel-recommendation answers for the prompt's city:
 * 3-6 hotels (sometimes the tracked hotel and its competitors), mixed
 * sentiment, 2-5 citations and plausible token counts. The output is fully
 * determined by the prompt, run number, hotel and salt, so repeated calls
 * return the same answer.
 */
final class FakeEngine implements EngineContract
{
    public const MODEL = 'fake-engine';

    /** Words FakeAnalyzer uses to read sentiment back out of an answer. */
    public const NEGATIVE_CUES = ['however', 'mixed reviews', 'a few guests', 'dated', 'noisy', 'slow service'];

    public const POSITIVE_CUES = ['praise', 'standout', 'highly rated', 'outstanding', 'loved'];

    /** @var array<string, array{keywords: list<string>, topic: string, features: list<string>}> */
    private const THEMES = [
        'family' => [
            'keywords' => ['family', 'kids', 'children', 'anak', 'keluarga', '孩子', '子連れ', '子ども', '아이'],
            'topic' => 'families',
            'features' => ['a large kids club', 'family suites with connecting rooms', 'a shallow lagoon pool', 'babysitting on request', 'a kid-friendly menu'],
        ],
        'business' => [
            'keywords' => ['business', 'meetings', 'wi-fi', 'workspace', 'dinas', 'meeting', '出差', '开会', '出張', '打ち合わせ', '출장', '미팅'],
            'topic' => 'business travellers',
            'features' => ['fast, reliable Wi-Fi', 'a quiet co-working lounge', 'easy airport transfers', 'proper desks in every room', 'well-equipped meeting rooms'],
        ],
        'romance' => [
            'keywords' => ['romantic', 'honeymoon', 'couple', 'anniversary', 'romantis', 'bulan madu', '蜜月', '纪念日', 'ハネムーン', '記念日', '신혼', '기념일'],
            'topic' => 'couples',
            'features' => ['private pool villas', 'candle-lit dinners', 'a couples spa', 'sunset views', 'an adults-only pool'],
        ],
        'adventure' => [
            'keywords' => ['hiking', 'active', 'nature', 'outdoor', 'day trips', 'alam', '徒步', '户外', 'トレッキング', '自然', '트레킹', '자연'],
            'topic' => 'an active trip',
            'features' => ['guided treks from the lobby', 'easy access to nature walks', 'a helpful tour desk', 'free bikes for guests', 'early breakfast for sunrise hikes'],
        ],
        'leisure' => [
            'keywords' => [],
            'topic' => 'a relaxing stay',
            'features' => ['a beautiful infinity pool', 'a generous breakfast', 'a calm spa', 'lush tropical gardens', 'friendly, attentive staff'],
        ],
    ];

    private const NAME_PREFIXES = [
        'The Banyan', 'Puri Saraswati', 'Casa Luna', 'The Lotus', 'Bamboo Grove', 'Azure Sky', 'Jade Terrace',
        'Coral Cove', 'Saffron', 'The Orchid', 'Teak House', 'Sunrise Valley', 'Hidden Palm', 'Golden Lantern',
        'Riverstone', 'Moonlight', 'Kirana', 'Sayang', 'Amora', 'Tirta Sari',
    ];

    private const NAME_SUFFIXES = ['Resort', 'Hotel', 'Boutique Hotel', 'Villas', 'Retreat', 'Suites'];

    private const BLOGS = [
        ['cntraveler.com', '/gallery/best-hotels-in-{city}', 'The Best Hotels in {City}'],
        ['lonelyplanet.com', '/articles/where-to-stay-in-{city}', 'Where to stay in {City}'],
        ['timeout.com', '/{city}/hotels/best-hotels-in-{city}', 'The best hotels in {City} right now'],
        ['travelandleisure.com', '/best-{topic}-hotels-{city}', 'Best {City} Hotels for {Topic}'],
        ['thehoneycombers.com', '/{city}/best-{topic}-hotels', 'Our favourite {City} hotels for {Topic}'],
    ];

    /**
     * Intro and closing lines of list answers per prompt language
     * (%1$d count, %2$s city). The hotel lines stay in English so the
     * offline insight fakes can read the features.
     *
     * @var array<string, array{0: list<string>, 1: list<string>}>
     */
    private const FRAMES = [
        'id' => [
            ['Berikut %1$d pilihan penginapan di %2$s yang layak dipertimbangkan:', 'Dari ulasan terbaru, %1$d hotel di %2$s ini patut dicoba:'],
            ['Harga cepat berubah saat musim ramai, jadi cek ulasan terbaru sebelum memesan.', 'Sebutkan budget dan tanggalnya, nanti saya bantu persempit pilihannya.'],
        ],
        'zh' => [
            ['以下是%2$s值得考虑的%1$d家住宿：', '根据最近的评价，%2$s这%1$d家酒店值得一看：'],
            ['旺季价格变化快，预订前请查看最新评价。', '告诉我预算和日期，我可以帮你进一步筛选。'],
        ],
        'ko' => [
            ['%2$s에서 추천할 만한 숙소 %1$d곳입니다:', '최근 후기 기준으로 %2$s에서 가볼 만한 호텔 %1$d곳이에요:'],
            ['성수기에는 가격이 자주 바뀌니 예약 전에 최신 후기를 확인하세요.', '예산과 날짜를 알려주시면 더 좁혀 드릴게요.'],
        ],
        'ja' => [
            ['%2$sでおすすめの宿泊先を%1$d軒ご紹介します：', '最近の口コミから、%2$sで検討する価値のあるホテルを%1$d軒選びました：'],
            ['繁忙期は料金が変わりやすいので、予約前に最新の口コミを確認してください。', 'ご予算と日程を教えていただければ、さらに絞り込めます。'],
        ],
    ];

    private Randomizer $random;

    public function __construct(
        private readonly string $engineName = 'openai',
        private readonly float $mentionRate = 0.45,
        private readonly float $competitorRate = 0.5,
        private readonly string $salt = '',
    ) {}

    public function name(): string
    {
        return $this->engineName;
    }

    public function model(): string
    {
        return self::MODEL;
    }

    public function ask(string $prompt, ?AskContext $context = null): EngineResult
    {
        $context ??= new AskContext('', 'the city', '');
        $this->random = new Randomizer(new Mt19937($this->seed($prompt, $context)));

        $theme = $this->theme($prompt);
        $hotels = $this->localNames($this->pickHotels($context), $context);
        $isList = count($hotels) > 2 && $this->chance(0.85);

        if (! $isList) {
            $targetIndex = array_search(true, array_column($hotels, 'is_target'), true);
            if ($targetIndex !== false && $targetIndex > 1) {
                [$hotels[1], $hotels[$targetIndex]] = [$hotels[$targetIndex], $hotels[1]];
            }
            $hotels = array_slice($hotels, 0, 2);
        }

        $described = array_map(fn (array $hotel) => $hotel + [
            'description' => $this->describe($theme, $context->city, $hotel['is_target']),
        ], $hotels);

        $text = $isList
            ? $this->listAnswer($described, $theme, $context->city, $context->language)
            : $this->proseAnswer($described, $theme, $context->city);

        return new EngineResult(
            text: $text,
            citations: $this->citations($described, $theme, $context),
            tokensIn: $this->random->getInt(1800, 6500),
            tokensOut: intdiv(strlen($text), 4) + $this->random->getInt(20, 120),
            webSearchCalls: $this->random->getInt(1, 2),
        );
    }

    private function seed(string $prompt, AskContext $context): int
    {
        return crc32(implode('|', [$this->salt, $this->engineName, $prompt, $context->runNumber, $context->hotelName]));
    }

    /**
     * @return array{keywords: list<string>, topic: string, features: list<string>}
     */
    private function theme(string $prompt): array
    {
        $prompt = Str::lower($prompt);

        foreach (self::THEMES as $theme) {
            foreach ($theme['keywords'] as $keyword) {
                if (str_contains($prompt, $keyword)) {
                    return $theme;
                }
            }
        }

        return self::THEMES['leisure'];
    }

    /**
     * @return list<array{name: string, website: string|null, is_target: bool}>
     */
    private function pickHotels(AskContext $context): array
    {
        $picked = [];

        if ($context->hotelName !== '' && $this->chance($this->mentionRate)) {
            $picked[] = ['name' => $context->hotelName, 'website' => $context->hotelWebsite, 'is_target' => true];
        }

        foreach ($context->competitors as $name => $website) {
            if ($this->chance($this->competitorRate)) {
                $picked[] = ['name' => $name, 'website' => $website, 'is_target' => false];
            }
        }

        $wanted = max(count($picked), $this->random->getInt(3, 6));
        $matcher = new HotelNameMatcher;
        $known = array_merge([$context->hotelName], array_keys($context->competitors));
        $guard = 0;

        while (count($picked) < $wanted && $guard++ < 50) {
            $name = $this->generatedName($context->city);
            $clash = array_filter(
                array_merge($known, array_column($picked, 'name')),
                fn (string $existing) => $existing !== '' && $matcher->matches($existing, $name, [$context->city]),
            );

            if ($clash === []) {
                $picked[] = ['name' => $name, 'website' => null, 'is_target' => false];
            }
        }

        return $this->random->shuffleArray($picked);
    }

    private function generatedName(string $city): string
    {
        $prefix = $this->pick(self::NAME_PREFIXES);
        $suffix = $this->pick(self::NAME_SUFFIXES);

        return $this->chance(0.5) ? "{$prefix} {$suffix} {$city}" : "{$prefix} {$suffix}";
    }

    /**
     * @param  array{features: list<string>}  $theme
     */
    private function describe(array $theme, string $city, bool $isTarget): string
    {
        [$first, $second] = array_slice($this->random->shuffleArray($theme['features']), 0, 2);
        $roll = $this->random->getFloat(0, 1);
        $negativeShare = $isTarget ? 0.15 : 0.2;

        if ($roll < $negativeShare) {
            return $this->pick([
                "Has {$first}, however some recent reviews mention dated rooms.",
                "Popular for {$first}, but there are mixed reviews about slow service.",
                "Good location in {$city}, though a few guests found it noisy at night.",
            ]);
        }

        if ($roll < $negativeShare + 0.35) {
            return $this->pick([
                "Offers {$first} at a mid-range price point.",
                "A solid option with {$first}, close to the centre of {$city}.",
                "Known for {$first} and {$second}.",
            ]);
        }

        return $this->pick([
            "Guests consistently praise {$first} and {$second}.",
            "A standout choice, especially for {$first}.",
            "Highly rated for {$first}; many reviewers say it is great value.",
            "Travellers loved {$first} and {$second}.",
        ]);
    }

    /**
     * @param  list<array{name: string, description: string}>  $hotels
     * @param  array{topic: string}  $theme
     */
    private function listAnswer(array $hotels, array $theme, string $city, string $language = 'en'): string
    {
        [$intros, $outros] = self::FRAMES[$language] ?? [[
            'Here are %1$d well-reviewed places to stay in %2$s for %3$s:',
            'Based on recent reviews, these %1$d hotels in %2$s are worth a look for %3$s:',
            'Good options (%1$d) in %2$s for %3$s:',
        ], [
            'Prices and availability change quickly, so check recent reviews before booking.',
            'Book early for peak season (July-August and late December).',
            'Tell me your budget and dates and I can narrow this down.',
        ]];

        $lines = [sprintf($this->pick($intros), count($hotels), $city, $theme['topic']), ''];

        foreach ($hotels as $i => $hotel) {
            $lines[] = ($i + 1).". **{$hotel['name']}** - {$hotel['description']}";
        }

        $lines[] = '';
        $lines[] = $this->pick($outros);

        return implode("\n", $lines);
    }

    /**
     * Prompts in Chinese, Korean or Japanese: AI answers often write the
     * hotel's name in that script, so use an alternative name in that script
     * for the tracked hotel about half the time.
     *
     * @param  list<array{name: string, website: string|null, is_target: bool}>  $hotels
     * @return list<array{name: string, website: string|null, is_target: bool}>
     */
    private function localNames(array $hotels, AskContext $context): array
    {
        $script = ['zh' => '/\p{Han}/u', 'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u', 'ko' => '/\p{Hangul}/u'][$context->language] ?? null;
        $local = $script === null ? null : collect($context->alternativeNames)->first(fn (string $name) => preg_match($script, $name) === 1);

        if ($local === null) {
            return $hotels;
        }

        return array_map(fn (array $hotel) => $hotel['is_target'] && $this->chance(0.5) ? ['name' => $local] + $hotel : $hotel, $hotels);
    }

    /**
     * @param  list<array{name: string, description: string}>  $hotels
     * @param  array{topic: string}  $theme
     */
    private function proseAnswer(array $hotels, array $theme, string $city): string
    {
        $text = "For {$theme['topic']} in {$city}, **{$hotels[0]['name']}** is a strong choice. {$hotels[0]['description']}";

        if (isset($hotels[1])) {
            $text .= "\n\nIf it is booked out, **{$hotels[1]['name']}** is a good alternative. {$hotels[1]['description']}";
        }

        return $text;
    }

    /**
     * @param  list<array{name: string, website: string|null, is_target: bool}>  $hotels
     * @param  array{topic: string}  $theme
     * @return list<CitationData>
     */
    private function citations(array $hotels, array $theme, AskContext $context): array
    {
        /** @var array<string, CitationData> $citations keyed by URL to avoid duplicates */
        $citations = [];
        $wanted = $this->random->getInt(2, 5);
        $citySlug = Str::slug($context->city);
        $year = date('Y');

        foreach ($hotels as $hotel) {
            if ($hotel['website'] !== null && $hotel['website'] !== '' && $this->chance($hotel['is_target'] ? 0.6 : 0.4)) {
                $url = Str::startsWith($hotel['website'], ['http://', 'https://']) ? $hotel['website'] : 'https://'.$hotel['website'];
                $citation = CitationData::fromUrl($url, $hotel['name'].' - Official Site');
                $citations[$citation->url] = $citation;
            }
        }

        $guard = 0;
        while (count($citations) < $wanted && $guard++ < 40) {
            $hotel = $this->pick($hotels);
            $slug = Str::slug($hotel['name']);
            $citation = match ($this->random->getInt(0, 4)) {
                0 => CitationData::fromUrl("https://www.tripadvisor.com/Hotel_Review-{$slug}-Reviews.html?utm_source=openai", "{$hotel['name']} - UPDATED {$year} Prices & Reviews - Tripadvisor"),
                1 => CitationData::fromUrl("https://www.booking.com/hotel/{$citySlug}/{$slug}.html", "{$hotel['name']}, {$context->city} - Updated {$year} Prices"),
                2 => CitationData::fromUrl("https://www.agoda.com/{$slug}/hotel/{$citySlug}.html", "{$hotel['name']} - Agoda"),
                3 => CitationData::fromUrl("https://www.expedia.com/{$citySlug}-hotels-{$slug}.Hotel-Information", "{$hotel['name']} | Expedia"),
                default => $this->blogCitation($theme, $context->city),
            };
            $citations[$citation->url] ??= $citation;
        }

        return array_values($citations);
    }

    /**
     * @param  array{topic: string}  $theme
     */
    private function blogCitation(array $theme, string $city): CitationData
    {
        [$domain, $path, $title] = $this->pick(self::BLOGS);
        $replace = [
            '{city}' => Str::slug($city),
            '{City}' => $city,
            '{topic}' => Str::slug($theme['topic']),
            '{Topic}' => Str::title($theme['topic']),
        ];

        return CitationData::fromUrl('https://www.'.$domain.strtr($path, $replace), strtr($title, $replace));
    }

    private function chance(float $probability): bool
    {
        return $this->random->getFloat(0, 1) < $probability;
    }

    /**
     * @template T
     *
     * @param  array<int, T>  $items
     * @return T
     */
    private function pick(array $items): mixed
    {
        return $items[array_keys($items)[$this->random->getInt(0, count($items) - 1)]];
    }
}
