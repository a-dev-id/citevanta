<?php

namespace App\Enums;

/**
 * Kinds of website an AI answer can cite. Domain lists live in
 * config/citevanta.php (domain_types); see DomainClassifier.
 */
enum DomainType: string
{
    case Direct = 'own';
    case Competitor = 'competitor';
    case Ota = 'ota';
    case ReviewSite = 'review';
    case TravelMedia = 'media';
    case HotelChain = 'chain';
    case Social = 'social';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Direct => 'Direct',
            self::Competitor => 'Competitor',
            self::Ota => 'OTA',
            self::ReviewSite => 'Review site',
            self::TravelMedia => 'Travel media',
            self::HotelChain => 'Hotel chain',
            self::Social => 'Social / community',
            self::Other => 'Other',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Direct => 'Your own hotel website.',
            self::Competitor => 'The website of one of your tracked competitors.',
            self::Ota => 'Online booking sites such as Booking.com, Agoda and Expedia.',
            self::ReviewSite => 'Review platforms such as Tripadvisor, Google reviews and Yelp.',
            self::TravelMedia => 'Travel guides, magazines and blogs such as Lonely Planet and Condé Nast Traveler.',
            self::HotelChain => 'Websites of hotel chains you do not track as competitors.',
            self::Social => 'Social networks and forums such as Reddit, YouTube and Instagram.',
            self::Other => 'Any other website.',
        };
    }

    /** CSS modifier for the badge and the colour dot (cv-domain--{value}). */
    public function cssClass(): string
    {
        return 'cv-domain--'.$this->value;
    }
}
