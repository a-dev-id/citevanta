<?php

/*
| Teks website publik (Bahasa Indonesia). :app adalah nama produk dari config('app.name').
*/

return [
    'nav' => [
        'features' => 'Fitur',
        'pricing' => 'Harga',
        'free_audit' => 'Audit gratis',
        'login' => 'Masuk',
        'trial' => 'Coba gratis',
        'menu' => 'Menu',
        'language' => 'Bahasa',
    ],

    'meta' => [
        'home_title' => ':app – Lihat bagaimana ChatGPT merekomendasikan hotel Anda',
        'home_description' => ':app memantau bagaimana ChatGPT merekomendasikan hotel Anda, menunjukkan informasi yang salah dan alasan kompetitor dipilih, lalu memberi tim Anda konten siap pakai untuk memperbaikinya. Audit visibilitas AI gratis.',
        'pricing_title' => 'Harga – :app',
        'pricing_description' => 'Paket :app untuk hotel: Starter dan Pro, harga per hotel per bulan, dengan uji coba gratis :days hari (pemindaian terbatas 15 pertanyaan di ChatGPT) tanpa kartu.',
        'audit_title' => 'Audit visibilitas AI gratis untuk hotel – :app',
        'audit_description' => 'Cari tahu dalam beberapa menit apakah ChatGPT merekomendasikan hotel Anda. Kami ajukan 10 pertanyaan nyata dari wisatawan dan kirim laporannya lewat email. Gratis, tanpa kartu.',
        'contact_title' => 'Kontak – :app',
        'contact_description' => 'Ada pertanyaan tentang :app, grup hotel atau agensi? Kirim pesan kepada kami.',
        'privacy_title' => 'Kebijakan privasi – :app',
        'privacy_description' => 'Bagaimana :app mengumpulkan, menggunakan, dan melindungi data pribadi dari tim hotel, permintaan audit gratis, dan formulir kontak.',
        'terms_title' => 'Ketentuan layanan – :app',
        'terms_description' => 'Ketentuan penggunaan :app, layanan visibilitas AI untuk hotel: akun, paket, pembayaran, dan penggunaan yang wajar.',
        'tagline' => 'Visibilitas AI untuk hotel',
    ],

    'hero' => [
        'eyebrow' => 'Visibilitas AI untuk hotel',
        'title' => 'Lihat bagaimana ChatGPT merekomendasikan hotel Anda',
        'lead' => 'Kini wisatawan bertanya ke asisten AI di mana sebaiknya menginap. :app menunjukkan apakah ChatGPT merekomendasikan hotel Anda, informasi apa yang salah, mengapa kompetitor dipilih, dan apa yang perlu diubah.',
        'form_title' => 'Dapatkan audit visibilitas AI gratis',
        'form_note' => '10 pertanyaan nyata dari wisatawan. Laporan dalam beberapa menit, lewat email. Tanpa kartu.',
    ],

    'problem' => [
        'title' => 'Wisatawan bertanya ke AI. Apakah hotel Anda disebut?',
        'points' => [
            ['title' => 'AI adalah pencarian pertama', 'text' => 'Tamu bertanya ke ChatGPT "resort keluarga terbaik di Ubud" lalu memesan salah satu dari sedikit hotel yang disebut. Jika hotel Anda tidak ada di jawaban, Anda tidak masuk daftar pilihan.'],
            ['title' => 'Jawaban yang tidak terlihat', 'text' => 'Berbeda dengan peringkat Google, jawaban AI tidak bisa Anda lihat: Anda tidak tahu apakah disebut, bagaimana digambarkan, atau sumber apa yang dipercaya AI.'],
            ['title' => 'OTA mengambil 15–25%', 'text' => 'Saat AI mengutip situs booking, bukan website Anda, tamu memesan lewat mereka dan Anda membayar komisi 15–25% untuk setiap menginap.'],
        ],
    ],

    'how' => [
        'title' => 'Cara kerjanya',
        'steps' => [
            ['title' => 'Tambahkan hotel Anda', 'text' => 'Data hotel, fakta-faktanya, kompetitor, dan pertanyaan yang diajukan tamu Anda, dalam hingga 5 bahasa.'],
            ['title' => 'Kami bertanya ke AI', 'text' => ':app mengajukan pertanyaan tamu Anda ke ChatGPT dua kali sebulan, lalu membaca setiap jawaban dan setiap sumber yang dikutip.'],
            ['title' => 'Anda perbaiki yang penting', 'text' => 'Daftar tindakan yang jelas dengan konten siap pakai: FAQ, pembaruan listing OTA, Google Business Profile, schema markup.'],
        ],
    ],

    'features' => [
        'title' => 'Semua yang dibutuhkan tim hotel untuk direkomendasikan AI',
        'items' => [
            ['icon' => 'fa-chart-line', 'title' => 'Pemantauan visibilitas', 'text' => 'Seberapa sering AI menyebut hotel Anda, posisinya di jawaban, sentimen, dan tren per tipe wisatawan, dari pemindaian ke pemindaian.'],
            ['icon' => 'fa-spell-check', 'title' => 'Yang salah dikatakan AI', 'text' => 'Harga keliru, fasilitas yang tidak ada, informasi usang: setiap klaim dicek dengan fakta hotel Anda, lengkap dengan kutipannya.'],
            ['icon' => 'fa-chess-knight', 'title' => 'Mengapa kompetitor dipilih', 'text' => 'Fitur dan sumber yang membuat AI merekomendasikan kompetitor, dan apakah hotel Anda juga memilikinya.'],
            ['icon' => 'fa-lightbulb', 'title' => 'Rekomendasi dengan konten siap pakai', 'text' => 'Langkah berprioritas yang mudah diikuti, dengan FAQ, pembaruan listing OTA, posting Google, dan schema markup yang dibuat hanya dari fakta hotel Anda.'],
            ['icon' => 'fa-language', 'title' => '5 bahasa', 'text' => 'Pantau pertanyaan tamu dalam bahasa Inggris, Indonesia, Mandarin, Korea, dan Jepang.'],
            ['icon' => 'fa-link', 'title' => 'Dari mana AI mendapat informasi', 'text' => 'Setiap sumber yang dikutip AI, dikelompokkan: website Anda, OTA, situs ulasan, media perjalanan, dan kompetitor.'],
        ],
    ],

    'sample' => [
        'title' => 'Dashboard Anda',
        'lead' => 'Contoh hotel dengan data demo: visibilitas, perubahan sejak pemindaian terakhir, kompetitor, dan sumber yang diandalkan AI.',
        'label' => 'Data contoh',
        'visibility' => 'Visibilitas',
        'position' => 'Posisi rata-rata',
        'positive' => 'Sebutan positif',
        'sources' => 'Sumber dikutip',
        'trend' => 'Tren visibilitas',
        'share' => 'Share of voice',
        'you' => 'Hotel Anda',
        'alt' => 'Contoh dashboard :app dengan visibilitas, kompetitor, dan sumber yang dikutip',
    ],

    'pricing' => [
        'title' => 'Harga sederhana per hotel',
        'lead' => 'Coba gratis selama :days hari dengan pemindaian terbatas 15 pertanyaan di ChatGPT. Tanpa kartu.',
        'monthly' => 'Bulanan',
        'annual' => 'Tahunan',
        'annual_note' => ':months bulan dengan harga :paid bulan',
        'per_month' => 'per hotel / bulan',
        'per_year' => 'per hotel / tahun',
        'currency' => 'Mata uang',
        'cta' => 'Coba gratis',
        'popular' => 'Paling populer',
        'includes' => 'Termasuk',
        'feature_prompts' => ':count pertanyaan wisatawan dipantau',
        'feature_competitors' => ':count kompetitor dibandingkan',
        'feature_languages' => 'Pertanyaan dalam hingga :count bahasa',
        'feature_scans' => ':count pemindaian terjadwal per bulan',
        'feature_manual' => ':count tambahan "Scan now" per bulan',
        'feature_content' => ':count konten per bulan',
        'feature_engines' => 'Asisten AI: :engines',
        'feature_insights' => 'Cek akurasi, alasan kompetitor, dan rekomendasi',
        'groups_title' => 'Grup hotel dan agensi',
        'groups_text' => 'Diskon :group% untuk Pro bagi :groupMin hotel atau lebih. Diskon :agency% untuk Starter bagi agensi dengan :agencyMin hotel atau lebih.',
        'groups_cta' => 'Hubungi kami',
        'faq_title' => 'Pertanyaan seputar harga',
    ],

    'faq' => [
        'title' => 'Pertanyaan yang sering diajukan',
        'home' => [
            ['q' => 'Apa itu visibilitas AI untuk hotel?', 'a' => 'Visibilitas AI adalah seberapa sering asisten AI seperti ChatGPT menyebut dan merekomendasikan hotel Anda saat wisatawan bertanya di mana sebaiknya menginap. :app mengukurnya dengan mengajukan pertanyaan wisatawan yang sama secara rutin dan membaca setiap jawaban.'],
            ['q' => 'Asisten AI mana yang dicek :app?', 'a' => ':app saat ini mengecek ChatGPT, menggunakan OpenAI dengan pencarian web, seperti cara wisatawan menggunakannya. Asisten AI lain sedang direncanakan.'],
            ['q' => 'Apakah :app mengubah website saya?', 'a' => 'Tidak. :app tidak pernah mengubah website atau listing Anda. :app memberi tahu tim Anda apa yang perlu diubah dan menyediakan teks siap pakai yang dibuat hanya dari fakta hotel Anda.'],
            ['q' => 'Seberapa sering pemindaian dilakukan?', 'a' => 'Setiap paket mencakup pemindaian terjadwal dua kali sebulan, ditambah "Scan now" tambahan. Setiap pertanyaan diajukan lebih dari sekali agar hasilnya tidak bergantung pada satu jawaban acak.'],
            ['q' => 'Bahasa apa saja yang didukung?', 'a' => 'Pertanyaan wisatawan bisa dipantau dalam bahasa Inggris, Indonesia, Mandarin (Sederhana), Korea, dan Jepang, disarankan berdasarkan pasar tamu utama Anda.'],
            ['q' => 'Apakah audit gratis benar-benar gratis?', 'a' => 'Ya. Audit gratis mengajukan 10 pertanyaan wisatawan tentang destinasi Anda ke ChatGPT dan mengirim laporannya lewat email. Tanpa kartu dan tanpa akun.'],
            ['q' => 'Berapa lama uji coba gratis?', 'a' => 'Coba gratis selama :days hari dengan pemindaian terbatas 15 pertanyaan di ChatGPT, satu bahasa dan 3 rekomendasi teratas. Paket berbayar memindai lebih banyak pertanyaan, lebih sering, di lebih banyak asisten AI, dan membuka semua rekomendasi. Tidak perlu kartu untuk memulai.'],
        ],
        'pricing' => [
            ['q' => 'Apakah harganya per hotel?', 'a' => 'Ya. Harga berlaku per hotel per bulan. Setiap hotel memiliki pertanyaan, kompetitor, dan laporannya sendiri.'],
            ['q' => 'Apakah ada paket tahunan?', 'a' => 'Ya. Pembayaran tahunan seharga :months kali harga bulanan, jadi Anda mendapat dua bulan gratis.'],
            ['q' => 'Apakah grup hotel dan agensi mendapat diskon?', 'a' => 'Ya. Grup dengan :groupMin hotel atau lebih mendapat diskon :group% untuk Pro, dan agensi dengan :agencyMin hotel atau lebih mendapat diskon :agency% untuk Starter.'],
            ['q' => 'Bisakah saya berhenti kapan saja?', 'a' => 'Ya. Anda bisa membatalkan kapan saja; paket tetap aktif sampai akhir periode yang sudah dibayar, dan data Anda tetap disimpan.'],
            ['q' => 'Apa yang terjadi setelah uji coba?', 'a' => 'Pilih paket untuk terus memindai. Jika tidak, akun Anda menjadi hanya-baca: Anda tetap bisa masuk dan melihat hasil sebelumnya.'],
        ],
    ],

    'cta' => [
        'title' => 'Cari tahu apakah ChatGPT merekomendasikan hotel Anda',
        'text' => 'Jalankan audit gratis dalam beberapa menit, atau mulai uji coba :days hari untuk memantau setiap pertanyaan penting bagi tamu Anda.',
        'audit' => 'Dapatkan audit gratis',
        'trial' => 'Coba gratis',
    ],

    'audit_form' => [
        'hotel_name' => 'Nama hotel',
        'city' => 'Kota',
        'country' => 'Negara',
        'country_placeholder' => 'Pilih negara',
        'website' => 'Website (opsional)',
        'email' => 'Email Anda',
        'consent' => 'Ya, :app boleh mengirimi saya tips dan kabar terbaru tentang visibilitas AI. Saya bisa berhenti berlangganan kapan saja.',
        'submit' => 'Jalankan audit gratis',
        'privacy' => 'Email Anda kami gunakan untuk mengirim laporan. Lihat :privacy kami.',
        'privacy_link' => 'kebijakan privasi',
    ],

    'audit_page' => [
        'title' => 'Audit visibilitas AI gratis',
        'lead' => 'Kami mengajukan 10 pertanyaan yang benar-benar ditanyakan wisatawan tentang destinasi Anda ke ChatGPT, membaca setiap jawaban, lalu memberi tahu seberapa sering hotel Anda direkomendasikan, hotel mana yang direkomendasikan sebagai gantinya, dan dari mana AI mendapat informasinya.',
        'what_title' => 'Yang Anda dapatkan',
        'what' => [
            'Skor visibilitas AI: berapa dari 10 pertanyaan wisatawan yang menyebut hotel Anda',
            'Hotel-hotel yang direkomendasikan ChatGPT sebagai pengganti hotel Anda',
            'Dari mana ChatGPT mendapat informasi: website Anda, OTA, situs ulasan, atau media perjalanan',
            'Laporan lewat email, biasanya dalam beberapa menit',
        ],
    ],

    'contact' => [
        'title' => 'Hubungi kami',
        'lead' => 'Ada pertanyaan tentang :app, grup hotel, atau harga agensi? Kami biasanya membalas dalam satu hari kerja.',
        'name' => 'Nama Anda',
        'email' => 'Email Anda',
        'company' => 'Hotel atau perusahaan (opsional)',
        'message' => 'Pesan',
        'submit' => 'Kirim pesan',
        'sent' => 'Terima kasih. Pesan Anda sudah terkirim dan kami akan membalas lewat email.',
        'too_many' => 'Terlalu banyak pesan dari koneksi Anda. Silakan coba lagi nanti.',
    ],

    'legal' => [
        'review_note' => 'Draf teks untuk ditinjau bagian hukum. Bukan nasihat hukum.',
        'updated' => 'Terakhir diperbarui: :date',
        'privacy_title' => 'Kebijakan privasi',
        'privacy' => [
            ['h' => 'Siapa kami', 'p' => ':app membantu hotel memahami bagaimana asisten AI merekomendasikan mereka. Kebijakan ini menjelaskan data pribadi apa yang kami kumpulkan dan bagaimana kami menggunakannya.'],
            ['h' => 'Data yang kami kumpulkan', 'p' => 'Data akun (nama, email, hash kata sandi), detail dan fakta hotel Anda, permintaan audit gratis (hotel, kota, negara, website, email, persetujuan), pesan formulir kontak, dan data teknis seperti alamat IP untuk keamanan dan pembatasan akses.'],
            ['h' => 'Cara kami menggunakannya', 'p' => 'Untuk menjalankan layanan, mengirim laporan dan email akun, mencegah penyalahgunaan, dan, hanya jika Anda setuju, mengirim tips dan kabar terbaru. Kami tidak menjual data pribadi.'],
            ['h' => 'Penyedia layanan', 'p' => 'Kami menggunakan penyedia untuk menjalankan layanan, seperti penyedia AI (untuk mengajukan pertanyaan yang dipantau paket Anda), hosting, pengiriman email, dan pemrosesan pembayaran.'],
            ['h' => 'Hak Anda', 'p' => 'Anda bisa meminta salinan data Anda, meminta kami memperbaiki atau menghapusnya, dan berhenti berlangganan email kapan saja. Hubungi kami untuk melakukannya.'],
        ],
        'terms_title' => 'Ketentuan layanan',
        'terms' => [
            ['h' => 'Layanan', 'p' => ':app mengajukan pertanyaan ke asisten AI atas nama Anda dan melaporkan jawabannya. Jawaban AI berubah dari waktu ke waktu; hasil menggambarkan apa yang dikatakan AI saat ditanya.'],
            ['h' => 'Akun Anda', 'p' => 'Anda bertanggung jawab atas akun dan informasi hotel yang Anda masukkan. Jaga kerahasiaan kata sandi Anda.'],
            ['h' => 'Paket dan pembayaran', 'p' => 'Paket berbayar ditagih per hotel per bulan atau per tahun. Anda bisa membatalkan kapan saja; paket tetap aktif sampai akhir periode yang dibayar.'],
            ['h' => 'Penggunaan yang wajar', 'p' => 'Jangan menyalahgunakan layanan, mencoba merusaknya, atau menggunakannya untuk mengirim spam.'],
            ['h' => 'Tanggung jawab', 'p' => 'Layanan disediakan apa adanya. Kami tidak bertanggung jawab atas keputusan yang diambil berdasarkan jawaban AI.'],
        ],
    ],

    'footer' => [
        'product' => 'Produk',
        'company' => 'Perusahaan',
        'legal' => 'Legal',
        'rights' => 'Hak cipta dilindungi.',
        'note' => ':app tidak pernah mengubah website Anda. Tim Anda yang melakukan perubahan.',
    ],

    'errors' => [
        'honeypot' => 'Terjadi kesalahan. Silakan coba lagi.',
        'human' => 'Mohon konfirmasi bahwa Anda bukan robot, lalu kirim formulir lagi.',
    ],
];
