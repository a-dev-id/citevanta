<?php

namespace App\Services\Content;

use App\Models\Hotel;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;

/**
 * Schema.org JSON-LD for the hotel, built from its own data and facts
 * (no AI call): a Hotel/Resort (LodgingBusiness) and an FAQPage made from
 * the facts. Missing details are "[add: ...]" strings, so the JSON stays
 * valid and nothing is invented. The result is checked by decoding it.
 */
final class SchemaMarkupBuilder
{
    /** Facts that become amenityFeature entries when the value is not "No". */
    private const AMENITIES = ['Swimming pool', 'Kids club', 'Spa', 'Restaurant', 'Free Wi-Fi', 'Parking', 'Gym'];

    /**
     * @param  array<string, string>  $facts
     */
    public function build(Hotel $hotel, array $facts): string
    {
        $fact = function (string $key) use ($facts): ?string {
            foreach ($facts as $factKey => $value) {
                if (Str::lower($factKey) === Str::lower($key)) {
                    return $value;
                }
            }

            return null;
        };
        $placeholder = fn (string $what) => '[add: '.$what.']';

        $lodging = array_filter([
            '@type' => match (Str::lower((string) $hotel->hotel_type)) {
                'resort' => 'Resort',
                'hostel' => 'Hostel',
                'bed and breakfast', 'b&b' => 'BedAndBreakfast',
                default => 'Hotel',
            },
            'name' => $hotel->name,
            'url' => $hotel->website ?: $placeholder('website'),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => $fact('Address') ?? $placeholder('street address'),
                'addressLocality' => $hotel->city,
                'addressCountry' => $hotel->country,
            ],
            'telephone' => $fact('Phone') ?? $placeholder('phone number'),
            'starRating' => $hotel->star_rating ? ['@type' => 'Rating', 'ratingValue' => (string) $hotel->star_rating] : null,
            'priceRange' => $fact('Price range per night') ?? $placeholder('price range per night'),
            'numberOfRooms' => $fact('Number of rooms') ?? $placeholder('number of rooms'),
            'checkinTime' => $fact('Check-in time') ?? $placeholder('check-in time'),
            'checkoutTime' => $fact('Check-out time') ?? $placeholder('check-out time'),
            'petsAllowed' => $fact('Pet policy') ?? $placeholder('pet policy'),
            'amenityFeature' => collect(self::AMENITIES)
                ->map(fn ($key) => [$key, $fact($key)])
                ->filter(fn ($pair) => $pair[1] !== null)
                ->map(fn ($pair) => [
                    '@type' => 'LocationFeatureSpecification',
                    'name' => $pair[0],
                    'value' => ! preg_match('/^(no|none|not)\b/i', $pair[1]),
                ])->values()->all() ?: null,
        ], fn ($value) => $value !== null);

        $faq = [
            '@type' => 'FAQPage',
            'mainEntity' => collect($facts)->take(10)->map(fn ($value, $key) => [
                '@type' => 'Question',
                'name' => "{$key} at {$hotel->name}?",
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $value],
            ])->values()->all(),
        ];

        $document = ['@context' => 'https://schema.org', '@graph' => $faq['mainEntity'] === [] ? [$lodging] : [$lodging, $faq]];

        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Could not build valid schema markup: '.$e->getMessage(), previous: $e);
        }

        return $json;
    }
}
