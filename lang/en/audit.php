<?php

return [
    'errors' => [
        'too_many' => 'You have reached the limit of free audits for today. Please try again tomorrow, or start a free trial to track your hotel every month.',
        'budget' => 'Today\'s free audits are fully booked, please try again tomorrow.',
        'waitlist_offer' => 'Want a reminder? We can email you when new audit slots open.',
        'waitlist_button' => 'Email me when slots open',
        'waitlisted' => 'Thank you. We will email you when new free audit slots open.',
    ],

    'report' => [
        'title' => 'AI visibility audit: :hotel',
        'description' => 'How ChatGPT recommends :hotel in :city.',
        'running_title' => 'Your audit is running',
        'running_text' => 'We are asking ChatGPT 10 questions travellers ask about :city. This usually takes a few minutes. You can close this page: we will email the report to :email.',
        'progress' => ':done of :total questions answered',
        'failed_title' => 'We could not complete this audit',
        'failed_text' => 'ChatGPT did not answer this time. Please try again later.',
        'score_label' => 'AI visibility',
        'score_text' => ':count of :total traveller questions mentioned your hotel',
        'insights_title' => 'What we found',
        'instead_title' => 'Hotels ChatGPT recommends instead of you',
        'instead_none' => 'ChatGPT did not name other hotels in these answers.',
        'answers' => '{1} :count answer|[2,*] :count answers',
        'sources_title' => 'Where ChatGPT gets its information',
        'sources_none' => 'ChatGPT did not cite sources in these answers.',
        'citations' => ':count citations',
        'questions_title' => 'The questions we asked',
        'cta_title' => 'Track every question your guests ask',
        'cta_text' => 'The full product checks up to 50 questions in 5 languages twice a month, finds what AI gets wrong about your hotel, explains why competitors win and tells your team what to change.',
        'cta' => 'Start your free :days-day trial',
        'note' => 'One run on :date. AI answers change; a full plan repeats each question to give a reliable picture.',
    ],

    'insights' => [
        'mentioned' => 'ChatGPT mentioned your hotel in :count of :total traveller questions.',
        'not_mentioned' => 'ChatGPT did not mention your hotel in any of the :total traveller questions.',
        'instead' => 'It recommended :hotels most often instead (up to :count answers).',
        'own_cited' => 'Your own website was cited :count times out of :total citations.',
        'own_not_cited' => 'Your website was not cited once. Most citations came from :type (:share%).',
        'no_website' => 'Most citations came from :type (:share%). Add your website to see whether AI cites it.',
    ],

    'types' => [
        'own' => 'your website', 'competitor' => 'competitor websites', 'ota' => 'booking sites (OTAs)', 'review' => 'review sites',
        'media' => 'travel media', 'chain' => 'hotel chains', 'social' => 'social media and forums', 'other' => 'other websites',
    ],

    'mail' => [
        'slots_subject' => 'Free AI visibility audits are open again',
        'slots_body' => 'New free audit slots are open today. Run your hotel\'s AI visibility audit now: it takes a few minutes.',
        'slots_button' => 'Run my free audit',
        'subject' => 'Your AI visibility report for :hotel',
        'intro' => 'Your free AI visibility audit for :hotel in :city is ready.',
        'score' => 'ChatGPT mentioned your hotel in :count of :total traveller questions.',
        'button' => 'View your report',
        'outro' => 'The report shows the hotels ChatGPT recommends instead of you and where it gets its information.',
    ],
];
