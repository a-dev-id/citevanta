<?php

namespace App\Enums;

enum ContentType: string
{
    case Faq = 'faq';
    case WebsiteSection = 'website_section';
    case OtaListingUpdate = 'ota_listing_update';
    case GoogleBusinessProfile = 'gbp';
    case ReviewRequest = 'review_request';
    case MediaPitch = 'media_pitch';
    case SchemaJsonLd = 'schema_jsonld';

    public function label(): string
    {
        return match ($this) {
            self::Faq => 'FAQ questions and answers',
            self::WebsiteSection => 'Website section',
            self::OtaListingUpdate => 'OTA listing update (Booking.com and Agoda)',
            self::GoogleBusinessProfile => 'Google Business Profile description and post',
            self::ReviewRequest => 'Review request message',
            self::MediaPitch => 'Pitch email to travel media',
            self::SchemaJsonLd => 'Schema markup (JSON-LD)',
        };
    }

    public function whereToPaste(): string
    {
        return match ($this) {
            self::Faq => 'Your website: an FAQ page, or an FAQ section on the rooms or facilities page.',
            self::WebsiteSection => 'Your website: the page about this topic (e.g. Families, Facilities), as a new section.',
            self::OtaListingUpdate => 'Booking.com extranet and Agoda YCS: work through the checklist, paste the neighbourhood text into Booking.com "About the neighbourhood", and send the Agoda text to Agoda support via "Need Help" in YCS.',
            self::GoogleBusinessProfile => 'Google Business Profile: Edit profile > Description, and Add update (post).',
            self::ReviewRequest => 'WhatsApp or email to guests after check-out (or your PMS/CRM message template).',
            self::MediaPitch => 'Email to the editor or writer of the site, from your own email account.',
            self::SchemaJsonLd => 'Your website: inside a <script type="application/ld+json"> tag in the <head> of the home page (ask your web developer, or use your CMS\'s header-code setting).',
        };
    }

    public function fileExtension(): string
    {
        return $this === self::SchemaJsonLd ? 'json' : 'txt';
    }

    /** Built from facts without an AI call. */
    public function isTemplate(): bool
    {
        return $this === self::SchemaJsonLd;
    }
}
