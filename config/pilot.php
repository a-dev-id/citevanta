<?php

return [
    'key' => 'nandini-controlled-pilot-v1',
    'organization_slug' => 'nandini-jungle',
    'brand_slug' => 'nandini',
    'name' => 'Nandini controlled visibility pilot',
    'country' => 'AU',
    'language' => 'English',
    'first_live_review_required' => true,
    'source' => [
        'title' => 'Nandini AI Intelligence — Project Plan',
        'url' => 'https://docs.google.com/spreadsheets/d/1G185nyaUH7Eh_jc_i_r0R5lY3gmiNdkDpSvkyNzx0lg/edit#gid=113764491',
        'range' => 'TRACKING SETUP!A18:D29',
        'status' => 'Proposed in source plan; administrator review required before execution',
        'retrieved_on' => '2026-11-10',
    ],
    'prompts' => [
        ['key' => 'P01', 'category' => 'Luxury', 'text' => 'Best luxury resorts in Ubud Bali'],
        ['key' => 'P02', 'category' => 'Luxury', 'text' => 'Best jungle resorts in Bali'],
        ['key' => 'P03', 'category' => 'Luxury', 'text' => 'Best boutique resorts with rainforest views in Ubud'],
        ['key' => 'P04', 'category' => 'Romance', 'text' => 'Best romantic resorts in Ubud for couples'],
        ['key' => 'P05', 'category' => 'Romance', 'text' => 'Where to stay in Bali for a secluded honeymoon'],
        ['key' => 'P06', 'category' => 'Romance', 'text' => 'Quiet luxury hotels in Ubud for couples'],
        ['key' => 'P07', 'category' => 'Wellness', 'text' => 'Best wellness retreats in Ubud Bali'],
        ['key' => 'P08', 'category' => 'Wellness', 'text' => 'Luxury jungle resorts in Bali with spa and yoga'],
        ['key' => 'P09', 'category' => 'Wellness', 'text' => 'Best relaxing nature retreats near Ubud'],
        ['key' => 'P10', 'category' => 'Experiences', 'text' => 'Best hotels near the Ayung River in Bali'],
        ['key' => 'P11', 'category' => 'Experiences', 'text' => 'Where to stay in Ubud for jungle and river experiences'],
        ['key' => 'P12', 'category' => 'Experiences', 'text' => 'Luxury hotels in Ubud with romantic dining experiences'],
    ],
];
