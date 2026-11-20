<?php

namespace Tests\Unit;

use App\Enums\DomainType;
use App\Services\Dashboard\DomainClassifier;
use App\Services\Dashboard\DomainContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DomainClassifierTest extends TestCase
{
    private function classifier(): DomainClassifier
    {
        return new DomainClassifier([
            'ota' => ['booking.com', 'agoda.com', 'expedia.*', 'trip.com', 'traveloka.com', 'airbnb.*'],
            'review' => ['tripadvisor.*', 'google.*', 'yelp.*'],
            'media' => ['lonelyplanet.com', 'cntraveler.com'],
            'social' => ['reddit.com', 'youtube.com'],
            'chain' => ['fourseasons.com', 'marriott.com', 'hyatt.com'],
        ]);
    }

    private function context(): DomainContext
    {
        return new DomainContext('alayahotels.com', [
            ['name' => 'Komaneka at Bisma', 'domain' => 'komaneka.com'],
            ['name' => 'Kayon Jungle Resort', 'domain' => null],
            ['name' => 'Four Seasons Resort Bali at Sayan', 'domain' => null],
            ['name' => 'The Royal Beach', 'domain' => null],
        ], 'Ubud');
    }

    public static function domains(): array
    {
        return [
            'own' => ['alayahotels.com', DomainType::Direct],
            'own with www' => ['www.alayahotels.com', DomainType::Direct],
            'own subdomain' => ['book.alayahotels.com', DomainType::Direct],
            'booking' => ['booking.com', DomainType::Ota],
            'expedia other country' => ['expedia.co.id', DomainType::Ota],
            'trip.com subdomain' => ['id.trip.com', DomainType::Ota],
            'airbnb other country' => ['airbnb.co.uk', DomainType::Ota],
            'tripadvisor australia' => ['tripadvisor.com.au', DomainType::ReviewSite],
            'google maps' => ['maps.google.com', DomainType::ReviewSite],
            'travel guide' => ['www.lonelyplanet.com', DomainType::TravelMedia],
            'forum' => ['reddit.com', DomainType::Social],
            'chain' => ['marriott.com', DomainType::HotelChain],
            'look-alike is not an OTA' => ['notbooking.com', DomainType::Other],
            'expedia look-alike' => ['myexpedia.com', DomainType::Other],
            'unknown site' => ['baliguide.example', DomainType::Other],
        ];
    }

    #[DataProvider('domains')]
    public function test_domains_are_classified(string $domain, DomainType $expected): void
    {
        $this->assertSame($expected, $this->classifier()->classify($domain, $this->context()));
    }

    public function test_a_competitor_is_detected_by_its_website(): void
    {
        $this->assertSame(DomainType::Competitor, $this->classifier()->classify('komaneka.com', $this->context()));
        $this->assertSame(DomainType::Competitor, $this->classifier()->classify('www.komaneka.com', $this->context()));
    }

    public static function competitorNamesInDomains(): array
    {
        return [
            'name joined' => ['kayonjungleresort.com'],
            'name with hyphens' => ['kayon-jungle-resort.com'],
            'leading word' => ['kayonresort.co.id'],
        ];
    }

    #[DataProvider('competitorNamesInDomains')]
    public function test_a_competitor_is_detected_by_its_name_in_the_domain(string $domain): void
    {
        $this->assertSame(DomainType::Competitor, $this->classifier()->classify($domain, $this->context()));
    }

    public function test_a_chain_that_is_a_tracked_competitor_counts_as_competitor(): void
    {
        $this->assertSame(DomainType::Competitor, $this->classifier()->classify('fourseasons.com', $this->context()));
        $this->assertSame(DomainType::HotelChain, $this->classifier()->classify('fourseasons.com', new DomainContext('alayahotels.com')));
    }

    public function test_generic_words_or_the_city_alone_do_not_make_a_competitor(): void
    {
        // "The Royal Beach" is only generic words; "Ubud" is the city.
        $this->assertSame(DomainType::Other, $this->classifier()->classify('royalbali.com', $this->context()));
        $this->assertSame(DomainType::Other, $this->classifier()->classify('ubudjungle.com', $this->context()));
        $this->assertSame(DomainType::Competitor, $this->classifier()->classify('theroyalbeach.com', $this->context()));
    }

    public function test_a_competitor_name_does_not_override_an_ota(): void
    {
        $context = new DomainContext(null, [['name' => 'Booking Villas', 'domain' => null]]);

        $this->assertSame(DomainType::Ota, $this->classifier()->classify('booking.com', $context));
    }

    public function test_without_a_website_nothing_counts_as_direct(): void
    {
        $this->assertSame(DomainType::Other, $this->classifier()->classify('alayahotels.com', new DomainContext(null)));
    }
}
