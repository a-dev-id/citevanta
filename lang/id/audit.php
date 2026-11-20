<?php

return [
    'errors' => [
        'too_many' => 'Anda sudah mencapai batas audit gratis hari ini. Silakan coba lagi besok, atau mulai uji coba gratis untuk memantau hotel Anda setiap bulan.',
        'budget' => 'Kuota audit gratis hari ini sudah habis, silakan coba lagi besok.',
        'waitlist_offer' => 'Ingin diingatkan? Kami bisa mengirim email saat kuota audit baru tersedia.',
        'waitlist_button' => 'Kirim email saat kuota tersedia',
        'waitlisted' => 'Terima kasih. Kami akan mengirim email saat kuota audit gratis tersedia lagi.',
    ],

    'report' => [
        'title' => 'Audit visibilitas AI: :hotel',
        'description' => 'Bagaimana ChatGPT merekomendasikan :hotel di :city.',
        'running_title' => 'Audit Anda sedang berjalan',
        'running_text' => 'Kami sedang mengajukan 10 pertanyaan wisatawan tentang :city ke ChatGPT. Biasanya hanya beberapa menit. Anda boleh menutup halaman ini: laporan akan kami kirim ke :email.',
        'progress' => ':done dari :total pertanyaan terjawab',
        'failed_title' => 'Audit ini tidak bisa diselesaikan',
        'failed_text' => 'ChatGPT tidak memberi jawaban kali ini. Silakan coba lagi nanti.',
        'score_label' => 'Visibilitas AI',
        'score_text' => ':count dari :total pertanyaan wisatawan menyebut hotel Anda',
        'insights_title' => 'Temuan kami',
        'instead_title' => 'Hotel yang direkomendasikan ChatGPT sebagai pengganti hotel Anda',
        'instead_none' => 'ChatGPT tidak menyebut hotel lain dalam jawaban ini.',
        'answers' => '{1} :count jawaban|[2,*] :count jawaban',
        'sources_title' => 'Dari mana ChatGPT mendapat informasi',
        'sources_none' => 'ChatGPT tidak mengutip sumber dalam jawaban ini.',
        'citations' => ':count kutipan',
        'questions_title' => 'Pertanyaan yang kami ajukan',
        'cta_title' => 'Pantau setiap pertanyaan tamu Anda',
        'cta_text' => 'Produk lengkapnya memeriksa hingga 50 pertanyaan dalam 5 bahasa dua kali sebulan, menemukan informasi yang salah tentang hotel Anda, menjelaskan mengapa kompetitor dipilih, dan memberi tahu tim Anda apa yang perlu diubah.',
        'cta' => 'Mulai uji coba gratis :days hari',
        'note' => 'Satu kali pemeriksaan pada :date. Jawaban AI berubah-ubah; paket lengkap mengulang setiap pertanyaan agar hasilnya lebih akurat.',
    ],

    'insights' => [
        'mentioned' => 'ChatGPT menyebut hotel Anda di :count dari :total pertanyaan wisatawan.',
        'not_mentioned' => 'ChatGPT tidak menyebut hotel Anda di satu pun dari :total pertanyaan wisatawan.',
        'instead' => 'ChatGPT paling sering merekomendasikan :hotels sebagai gantinya (hingga :count jawaban).',
        'own_cited' => 'Website Anda dikutip :count kali dari :total kutipan.',
        'own_not_cited' => 'Website Anda tidak dikutip sama sekali. Sebagian besar kutipan berasal dari :type (:share%).',
        'no_website' => 'Sebagian besar kutipan berasal dari :type (:share%). Tambahkan website Anda untuk melihat apakah AI mengutipnya.',
    ],

    'types' => [
        'own' => 'website Anda', 'competitor' => 'website kompetitor', 'ota' => 'situs booking (OTA)', 'review' => 'situs ulasan',
        'media' => 'media perjalanan', 'chain' => 'jaringan hotel', 'social' => 'media sosial dan forum', 'other' => 'website lain',
    ],

    'mail' => [
        'slots_subject' => 'Audit visibilitas AI gratis tersedia lagi',
        'slots_body' => 'Kuota audit gratis baru tersedia hari ini. Jalankan audit visibilitas AI hotel Anda sekarang: hanya beberapa menit.',
        'slots_button' => 'Jalankan audit gratis saya',
        'subject' => 'Laporan visibilitas AI untuk :hotel',
        'intro' => 'Audit visibilitas AI gratis untuk :hotel di :city sudah siap.',
        'score' => 'ChatGPT menyebut hotel Anda di :count dari :total pertanyaan wisatawan.',
        'button' => 'Lihat laporan Anda',
        'outro' => 'Laporan ini menunjukkan hotel yang direkomendasikan ChatGPT sebagai pengganti hotel Anda dan dari mana ChatGPT mendapat informasinya.',
    ],
];
