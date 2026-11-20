<?php

namespace App\Enums;

enum RecommendationCategory: string
{
    case WebsiteContent = 'website_content';
    case OtaListing = 'ota_listing';
    case Reviews = 'reviews';
    case GoogleBusinessProfile = 'google_business_profile';
    case TravelMedia = 'travel_media';
    case Facts = 'facts';
    case SchemaMarkup = 'schema_markup';

    public function label(): string
    {
        return match ($this) {
            self::WebsiteContent => 'Website content',
            self::OtaListing => 'OTA listing',
            self::Reviews => 'Reviews',
            self::GoogleBusinessProfile => 'Google Business Profile',
            self::TravelMedia => 'Travel media and PR',
            self::Facts => 'Facts/accuracy',
            self::SchemaMarkup => 'Schema markup',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::WebsiteContent => 'fa-globe',
            self::OtaListing => 'fa-bed',
            self::Reviews => 'fa-star',
            self::GoogleBusinessProfile => 'fa-location-dot',
            self::TravelMedia => 'fa-newspaper',
            self::Facts => 'fa-spell-check',
            self::SchemaMarkup => 'fa-code',
        };
    }

    /**
     * Content that fits this kind of recommendation, the first one suggested.
     *
     * @return list<ContentType>
     */
    public function contentTypes(): array
    {
        return match ($this) {
            self::WebsiteContent => [ContentType::WebsiteSection, ContentType::Faq, ContentType::SchemaJsonLd],
            self::OtaListing => [ContentType::OtaListingUpdate],
            self::Reviews => [ContentType::ReviewRequest],
            self::GoogleBusinessProfile => [ContentType::GoogleBusinessProfile, ContentType::ReviewRequest],
            self::TravelMedia => [ContentType::MediaPitch],
            self::Facts => [ContentType::Faq, ContentType::OtaListingUpdate, ContentType::GoogleBusinessProfile],
            self::SchemaMarkup => [ContentType::SchemaJsonLd, ContentType::Faq],
        };
    }
}
