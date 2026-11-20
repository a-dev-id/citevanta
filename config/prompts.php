<?php

/*
|--------------------------------------------------------------------------
| Traveller prompt templates (TemplatePromptGenerator)
|--------------------------------------------------------------------------
|
| Two prompts per persona and language, written the way travellers from that
| market actually ask (not word-for-word translations). To add a language,
| copy a block, write natural questions, and add the type words.
|
| Placeholders: {city}, {country}, {type} (hotel type in that language),
| {types} (plural, English only), {market} (guest market, market templates).
| The hotel's own name is never used: we test unprompted recommendations.
*/

return [

    'templates' => [
        'en' => [
            'family' => [
                'What is the best family-friendly {type} in {city}, {country} with a pool and activities for kids?',
                'We are travelling to {city} with two young children. Which {types} would you recommend?',
            ],
            'business' => [
                'Which {type} in {city} is best for business travellers, with fast Wi-Fi and a good workspace?',
                'I have meetings in {city} next month. Recommend a comfortable {type} with good transport links and quiet rooms.',
            ],
            'leisure' => [
                'What are the best {types} to stay at in {city} for a relaxing holiday?',
                'Recommend a {type} in {city}, {country} with great reviews, a nice pool and a good breakfast.',
            ],
            'adventure' => [
                'I am planning an active trip around {city} with hiking and outdoor tours. Which {type} makes a good base?',
                'Which {type} in {city} is best for exploring nature and doing day trips nearby?',
            ],
            'couple' => [
                'What is the most romantic {type} in {city} for a honeymoon?',
                'Recommend a {type} in {city} for a couple\'s anniversary getaway with a spa and great views.',
            ],
        ],

        'id' => [
            'family' => [
                'Rekomendasi {type} ramah anak di {city} yang ada kolam renang dan kids club?',
                'Mau liburan ke {city} bawa dua anak kecil. {type} mana yang cocok untuk keluarga?',
            ],
            'business' => [
                '{type} di {city} yang bagus untuk perjalanan dinas, Wi-Fi kencang dan nyaman buat kerja?',
                'Bulan depan ada meeting di {city}. Ada rekomendasi {type} yang tenang dan aksesnya gampang?',
            ],
            'leisure' => [
                '{type} terbaik di {city} buat liburan santai apa saja?',
                'Cari {type} di {city} yang review-nya bagus, ada kolam renang dan sarapannya enak. Ada saran?',
            ],
            'adventure' => [
                'Mau trip aktif di sekitar {city}, hiking dan tur alam. {type} mana yang enak jadi basecamp?',
                '{type} di {city} yang dekat alam dan cocok untuk day trip ke sekitarnya?',
            ],
            'couple' => [
                '{type} paling romantis di {city} untuk bulan madu?',
                'Rekomendasi {type} di {city} buat anniversary berdua, ada spa dan pemandangannya bagus?',
            ],
        ],

        'zh' => [
            'family' => [
                '{city}有哪些适合带孩子入住的{type}？最好有泳池和儿童俱乐部。',
                '我们要带两个小孩去{city}玩，住哪家{type}比较适合一家人？',
            ],
            'business' => [
                '去{city}出差住哪家{type}比较好？要网速快、方便办公的。',
                '下个月在{city}开会，求推荐安静舒适、交通方便的{type}。',
            ],
            'leisure' => [
                '{city}有什么适合放松度假的{type}推荐？',
                '想在{city}找一家口碑好、有泳池、早餐不错的{type}，有推荐吗？',
            ],
            'adventure' => [
                '打算在{city}周边徒步和玩户外，住哪家{type}出行比较方便？',
                '{city}哪家{type}适合亲近大自然，去周边一日游也方便？',
            ],
            'couple' => [
                '{city}最浪漫的蜜月{type}是哪家？',
                '和另一半去{city}过纪念日，推荐一家有水疗、景色好的{type}。',
            ],
        ],

        'ko' => [
            'family' => [
                '{city}에서 아이들이랑 묵기 좋은 {type} 추천해 주세요. 수영장이랑 키즈클럽 있으면 좋겠어요.',
                '어린 아이 둘이랑 {city} 여행 가요. 가족끼리 묵기 좋은 {type} 어디가 있을까요?',
            ],
            'business' => [
                '{city} 출장 갈 때 묵기 좋은 {type} 있을까요? 와이파이 빠르고 일하기 편한 곳이요.',
                '다음 달 {city}에서 미팅이 있어요. 조용하고 교통 편한 {type} 추천 부탁드려요.',
            ],
            'leisure' => [
                '{city}에서 푹 쉬기 좋은 {type} 어디가 좋아요?',
                '{city}에서 후기 좋고 수영장이랑 조식 괜찮은 {type} 추천해 주세요.',
            ],
            'adventure' => [
                '{city} 근처에서 트레킹이랑 액티비티 하려고 하는데, 베이스로 묵기 좋은 {type} 있나요?',
                '{city}에서 자연 즐기고 근교 투어 다니기 좋은 {type} 추천해 주세요.',
            ],
            'couple' => [
                '{city} 신혼여행으로 로맨틱한 {type} 어디가 좋을까요?',
                '{city}로 기념일 여행 가는데 스파 있고 뷰 좋은 {type} 추천해 주세요.',
            ],
        ],

        'ja' => [
            'family' => [
                '{city}で子連れにおすすめの{type}はどこですか？プールやキッズクラブがあると嬉しいです。',
                '小さい子ども2人と{city}に行きます。家族で泊まりやすい{type}を教えてください。',
            ],
            'business' => [
                '{city}出張でおすすめの{type}はありますか？Wi-Fiが速くて仕事しやすいところがいいです。',
                '来月{city}で打ち合わせがあります。静かで交通の便がいい{type}を教えてください。',
            ],
            'leisure' => [
                '{city}でのんびり過ごせるおすすめの{type}はどこですか？',
                '{city}で口コミの評価が高くて、プールと朝食がいい{type}を探しています。',
            ],
            'adventure' => [
                '{city}周辺でトレッキングやアクティビティを楽しみたいです。拠点にしやすい{type}はありますか？',
                '{city}で自然を満喫できて、日帰りツアーにも便利な{type}はどこですか？',
            ],
            'couple' => [
                '{city}でハネムーンにぴったりのロマンチックな{type}はどこですか？',
                '{city}で記念日旅行をします。スパがあって景色のいい{type}を教えてください。',
            ],
        ],
    ],

    /*
    | Extra prompts for the hotel's guest markets (one per market, at most two).
    | Only for languages where the English country name reads naturally.
    */
    'market_templates' => [
        'en' => [
            ['leisure', 'We are travelling from {market} to {city}. Which {type} would you recommend?'],
            ['couple', 'Which {type} in {city} is popular with travellers from {market}?'],
        ],
        'id' => [
            ['leisure', 'Kami dari {market} mau liburan ke {city}. {type} mana yang direkomendasikan?'],
        ],
    ],

    /** The hotel type (config citevanta.hotel_types) in each language. English uses the type as entered. */
    'type_words' => [
        'id' => ['hotel' => 'hotel', 'resort' => 'resort', 'villa' => 'vila', 'boutique hotel' => 'hotel butik', 'hostel' => 'hostel', 'guesthouse' => 'guest house', 'apartment' => 'apartemen'],
        'zh' => ['hotel' => '酒店', 'resort' => '度假村', 'villa' => '别墅酒店', 'boutique hotel' => '精品酒店', 'hostel' => '青年旅舍', 'guesthouse' => '民宿', 'apartment' => '公寓'],
        'ko' => ['hotel' => '호텔', 'resort' => '리조트', 'villa' => '풀빌라', 'boutique hotel' => '부티크 호텔', 'hostel' => '호스텔', 'guesthouse' => '게스트하우스', 'apartment' => '레지던스'],
        'ja' => ['hotel' => 'ホテル', 'resort' => 'リゾート', 'villa' => 'ヴィラ', 'boutique hotel' => 'ブティックホテル', 'hostel' => 'ホステル', 'guesthouse' => 'ゲストハウス', 'apartment' => 'アパートメントホテル'],
    ],

    /** Guest market (country name, config/countries.php) => prompt language it suggests. */
    'market_languages' => [
        'Australia' => 'en', 'United Kingdom' => 'en', 'United States' => 'en', 'New Zealand' => 'en', 'Canada' => 'en',
        'Ireland' => 'en', 'Singapore' => 'en', 'India' => 'en',
        'China' => 'zh', 'Taiwan' => 'zh', 'Hong Kong' => 'zh',
        'South Korea' => 'ko',
        'Japan' => 'ja',
        'Indonesia' => 'id',
    ],

    /** Prompts generated for each language after the first (the first gets the usual count). */
    'per_extra_language' => 6,
];
