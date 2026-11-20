<?php

/*
| Public website copy (English). :app is the product name from config('app.name').
| Keep answers factual and quotable: AI assistants read these pages too.
*/

return [
    'nav' => [
        'features' => 'Features',
        'pricing' => 'Pricing',
        'free_audit' => 'Free audit',
        'login' => 'Log in',
        'trial' => 'Start free trial',
        'menu' => 'Menu',
        'language' => 'Language',
    ],

    'meta' => [
        'home_title' => ':app – See how ChatGPT recommends your hotel',
        'home_description' => ':app tracks how ChatGPT recommends your hotel, shows what AI gets wrong and why competitors win, and gives your team ready-to-use content to fix it. Free AI visibility audit.',
        'pricing_title' => 'Pricing – :app',
        'pricing_description' => ':app plans for hotels: Starter and Pro, priced per hotel per month, with a :days-day free trial (a limited scan of 15 questions on ChatGPT) and no card needed.',
        'audit_title' => 'Free AI visibility audit for hotels – :app',
        'audit_description' => 'Find out in minutes whether ChatGPT recommends your hotel. We ask 10 real traveller questions and email you the report. Free, no card.',
        'contact_title' => 'Contact – :app',
        'contact_description' => 'Questions about :app, hotel groups or agencies? Send us a message.',
        'privacy_title' => 'Privacy policy – :app',
        'privacy_description' => 'How :app collects, uses and protects personal data from hotel teams, free audit requests and the contact form.',
        'terms_title' => 'Terms of service – :app',
        'terms_description' => 'The terms for using :app, the AI visibility service for hotels: accounts, plans, payment and acceptable use.',
        'tagline' => 'AI visibility for hotels',
    ],

    'hero' => [
        'eyebrow' => 'AI visibility for hotels',
        'title' => 'See how ChatGPT recommends your hotel',
        'lead' => 'Travellers now ask AI assistants where to stay. :app shows whether ChatGPT recommends your hotel, what it gets wrong, why competitors win, and exactly what to change.',
        'form_title' => 'Get your free AI visibility audit',
        'form_note' => '10 real traveller questions. Report in minutes, by email. No card.',
    ],

    'problem' => [
        'title' => 'Travellers ask AI. Do they hear about you?',
        'points' => [
            ['title' => 'AI is the new first search', 'text' => 'Guests ask ChatGPT for "the best family resort in Ubud" and book one of the few hotels it names. If your hotel is not in the answer, you are not on the shortlist.'],
            ['title' => 'Answers you cannot see', 'text' => 'Unlike search rankings, AI answers are invisible to you: you do not know if you are mentioned, how you are described, or which sources the AI trusts.'],
            ['title' => 'OTAs take 15–25%', 'text' => 'When AI cites booking sites instead of your website, guests book through them and you pay 15–25% commission on every stay.'],
        ],
    ],

    'how' => [
        'title' => 'How it works',
        'steps' => [
            ['title' => 'Add your hotel', 'text' => 'Your hotel, its facts, your competitors and the questions your guests ask, in up to 5 languages.'],
            ['title' => 'We ask the AI', 'text' => ':app asks ChatGPT your guests\' questions twice a month and reads every answer and every source it cites.'],
            ['title' => 'You fix what matters', 'text' => 'A clear action list with ready-to-use content: FAQ, OTA listing updates, Google Business Profile, schema markup.'],
        ],
    ],

    'features' => [
        'title' => 'Everything a hotel team needs to win AI recommendations',
        'items' => [
            ['icon' => 'fa-chart-line', 'title' => 'Visibility tracking', 'text' => 'How often AI mentions your hotel, its rank in the answer, sentiment and trends per traveller type, scan after scan.'],
            ['icon' => 'fa-spell-check', 'title' => 'What AI gets wrong', 'text' => 'Wrong prices, facilities you do not have, outdated details: each claim checked against your own facts, with the exact quote.'],
            ['icon' => 'fa-chess-knight', 'title' => 'Why competitors win', 'text' => 'The features and sources that made the AI recommend a competitor instead of you, and whether you have them too.'],
            ['icon' => 'fa-lightbulb', 'title' => 'Recommendations with ready content', 'text' => 'Prioritised steps your team can follow, with copy-ready FAQ, OTA listing updates, Google posts and schema markup built only from your facts.'],
            ['icon' => 'fa-language', 'title' => '5 languages', 'text' => 'Track the questions your guests ask in English, Indonesian, Chinese, Korean and Japanese.'],
            ['icon' => 'fa-link', 'title' => 'Where AI gets its information', 'text' => 'Every source the AI cites, sorted into your website, OTAs, review sites, travel media and competitors.'],
        ],
    ],

    'sample' => [
        'title' => 'Your dashboard',
        'lead' => 'A sample hotel with demo data: visibility, change since the last scan, competitors and the sources AI relies on.',
        'label' => 'Sample data',
        'visibility' => 'Visibility',
        'position' => 'Average position',
        'positive' => 'Positive mentions',
        'sources' => 'Cited sources',
        'trend' => 'Visibility trend',
        'share' => 'Share of voice',
        'you' => 'Your hotel',
        'alt' => 'Sample :app dashboard with visibility, competitors and cited sources',
    ],

    'pricing' => [
        'title' => 'Simple pricing per hotel',
        'lead' => 'Try it free for :days days with a limited scan of 15 questions on ChatGPT. No card needed.',
        'monthly' => 'Monthly',
        'annual' => 'Annual',
        'annual_note' => ':months months for the price of :paid',
        'per_month' => 'per hotel / month',
        'per_year' => 'per hotel / year',
        'currency' => 'Currency',
        'cta' => 'Start free trial',
        'popular' => 'Most popular',
        'includes' => 'Includes',
        'feature_prompts' => ':count traveller questions tracked',
        'feature_competitors' => ':count competitors compared',
        'feature_languages' => 'Questions in up to :count languages',
        'feature_scans' => ':count scheduled scans per month',
        'feature_manual' => ':count extra "Scan now" per month',
        'feature_content' => ':count content pieces per month',
        'feature_engines' => 'AI assistants: :engines',
        'feature_insights' => 'Accuracy checks, competitor reasons and recommendations',
        'groups_title' => 'Hotel groups and agencies',
        'groups_text' => ':group% off Pro for :groupMin or more hotels. :agency% off Starter for agencies with :agencyMin or more hotels.',
        'groups_cta' => 'Talk to us',
        'faq_title' => 'Pricing questions',
    ],

    'faq' => [
        'title' => 'Frequently asked questions',
        'home' => [
            ['q' => 'What is AI visibility for a hotel?', 'a' => 'AI visibility is how often AI assistants such as ChatGPT mention and recommend your hotel when travellers ask where to stay. :app measures it by asking the same traveller questions regularly and reading every answer.'],
            ['q' => 'Which AI assistants does :app check?', 'a' => ':app checks ChatGPT today, using OpenAI with web search, the way travellers use it. More AI assistants are planned.'],
            ['q' => 'Does :app change my website?', 'a' => 'No. :app never edits your website or listings. It tells your team what to change and gives you ready-to-use text built only from your hotel\'s facts.'],
            ['q' => 'How often are scans run?', 'a' => 'Each plan includes scheduled scans twice a month, plus extra "Scan now" scans. Each scan asks every tracked question more than once to smooth out the AI\'s randomness.'],
            ['q' => 'Which languages are supported?', 'a' => 'Traveller questions can be tracked in English, Indonesian, Chinese (Simplified), Korean and Japanese, suggested from your main guest markets.'],
            ['q' => 'Is the free audit really free?', 'a' => 'Yes. The free audit asks ChatGPT 10 traveller questions about your destination and emails you a report. No card and no account needed.'],
            ['q' => 'How long is the free trial?', 'a' => 'Try it free for :days days with a limited scan of 15 questions on ChatGPT, one language and the top 3 recommendations. Paid plans scan more questions, more often, on more AI assistants, and unlock every recommendation. No card is needed to start.'],
        ],
        'pricing' => [
            ['q' => 'Is the price per hotel?', 'a' => 'Yes. Prices are per hotel per month. Each hotel has its own questions, competitors and reports.'],
            ['q' => 'Is there an annual plan?', 'a' => 'Yes. Annual billing costs :months times the monthly price, so you get two months free.'],
            ['q' => 'Do hotel groups and agencies get a discount?', 'a' => 'Yes. Groups with :groupMin or more hotels get :group% off Pro, and agencies with :agencyMin or more hotels get :agency% off Starter.'],
            ['q' => 'Can I cancel at any time?', 'a' => 'Yes. You can cancel at any time; your plan stays active until the end of the period you paid for, and your data is kept.'],
            ['q' => 'What happens after the trial?', 'a' => 'Choose a plan to keep scanning. If you do not, your account becomes read-only: you can still log in and see your results.'],
        ],
    ],

    'cta' => [
        'title' => 'Find out if ChatGPT recommends your hotel',
        'text' => 'Run a free audit in minutes, or start a :days-day trial to track every question that matters to your guests.',
        'audit' => 'Get the free audit',
        'trial' => 'Start free trial',
    ],

    'audit_form' => [
        'hotel_name' => 'Hotel name',
        'city' => 'City',
        'country' => 'Country',
        'country_placeholder' => 'Choose a country',
        'website' => 'Website (optional)',
        'email' => 'Your email',
        'consent' => 'Yes, :app may email me tips and updates about AI visibility. You can unsubscribe at any time.',
        'submit' => 'Run my free audit',
        'privacy' => 'We use your email to send the report. See our :privacy.',
        'privacy_link' => 'privacy policy',
    ],

    'audit_page' => [
        'title' => 'Free AI visibility audit',
        'lead' => 'We ask ChatGPT 10 questions travellers really ask about your destination, read every answer and tell you how often your hotel is recommended, which hotels are recommended instead, and where the AI gets its information.',
        'what_title' => 'What you get',
        'what' => [
            'Your AI visibility score: how many of 10 traveller questions mention your hotel',
            'The hotels ChatGPT recommends instead of you',
            'Where ChatGPT gets its information: your website, OTAs, review sites or travel media',
            'The report by email, usually within a few minutes',
        ],
    ],

    'contact' => [
        'title' => 'Contact us',
        'lead' => 'Questions about :app, hotel groups or agency pricing? We usually reply within one working day.',
        'name' => 'Your name',
        'email' => 'Your email',
        'company' => 'Hotel or company (optional)',
        'message' => 'Message',
        'submit' => 'Send message',
        'sent' => 'Thank you. Your message has been sent and we will reply by email.',
        'too_many' => 'Too many messages from your connection. Please try again later.',
    ],

    'legal' => [
        'review_note' => 'Draft text for legal review. Not legal advice.',
        'updated' => 'Last updated: :date',
        'privacy_title' => 'Privacy policy',
        'privacy' => [
            ['h' => 'Who we are', 'p' => ':app helps hotels understand how AI assistants recommend them. This policy explains what personal data we collect and how we use it.'],
            ['h' => 'What we collect', 'p' => 'Account data (name, email, password hash), your hotel\'s details and facts, free audit requests (hotel, city, country, website, email, consent), contact form messages, and technical data such as IP addresses for security and rate limiting.'],
            ['h' => 'How we use it', 'p' => 'To provide the service, send reports and account emails, prevent abuse, and, only if you agreed, send tips and updates. We do not sell personal data.'],
            ['h' => 'Service providers', 'p' => 'We use providers to run the service, such as AI providers (to ask the questions your plan tracks), hosting, email delivery and payment processing.'],
            ['h' => 'Your rights', 'p' => 'You can ask for a copy of your data, ask us to correct or delete it, and unsubscribe from emails at any time. Contact us to do so.'],
        ],
        'terms_title' => 'Terms of service',
        'terms' => [
            ['h' => 'The service', 'p' => ':app asks AI assistants questions on your behalf and reports what they answer. AI answers change over time; results describe what the AI said when it was asked.'],
            ['h' => 'Your account', 'p' => 'You are responsible for your account and for the hotel information you enter. Keep your password safe.'],
            ['h' => 'Plans and payment', 'p' => 'Paid plans are billed per hotel per month or year. You can cancel at any time; the plan stays active until the end of the paid period.'],
            ['h' => 'Acceptable use', 'p' => 'Do not misuse the service, try to break it, or use it to send spam.'],
            ['h' => 'Liability', 'p' => 'The service is provided as is. We are not responsible for decisions made on the basis of AI answers.'],
        ],
    ],

    'footer' => [
        'product' => 'Product',
        'company' => 'Company',
        'legal' => 'Legal',
        'rights' => 'All rights reserved.',
        'note' => ':app never edits your website. Your team makes the changes.',
    ],

    'errors' => [
        'honeypot' => 'Something went wrong. Please try again.',
        'human' => 'Please confirm you are not a robot, then send the form again.',
    ],
];
