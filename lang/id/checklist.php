<?php

/*
| Daftar periksa pengaturan awal (lihat App\Services\Setup\SetupChecklist). Setiap butir punya judul singkat, satu
| baris bantuan, dan teks tombolnya. Butir tercentang sendiri dari data yang nyata.
*/

return [
    'ui' => [
        'region' => 'Daftar periksa pengaturan',
        'title' => 'Mulai di sini',
        'progress' => ':done dari :total selesai',
        'pill' => 'Setup :done/:total',
        'pill_all' => 'Siap',
        'minimize' => 'Kecilkan daftar periksa',
        'expand' => 'Buka daftar periksa pengaturan',
        'close' => 'Tutup daftar periksa',
        'menu' => 'Menu daftar periksa',
        'hide' => 'Sembunyikan daftar ini',
        'all_set' => 'Semua siap',
        'all_set_text' => 'Pengaturan Anda sudah selesai. Daftar ini menyembunyikan diri setelah kunjungan ini. Anda bisa memunculkannya lagi dari menu ?.',
        'done' => 'Selesai',
        'todo' => 'Belum selesai',
        'not_counted' => 'Tidak termasuk dalam kemajuan',
        'help_entry' => 'Daftar periksa pengaturan',
    ],

    'items' => [
        'hotel' => ['title' => 'Tambahkan hotel Anda', 'help' => 'Nama, kota, dan situs web hotel Anda.', 'action' => 'Tambah hotel'],
        'facts' => ['title' => 'Isi fakta hotel', 'help' => 'Jawaban AI hanya sebaik fakta Anda: isi setidaknya kisaran harga dan jumlah kamar.', 'action' => 'Isi fakta'],
        'competitors' => ['title' => 'Tambahkan minimal 3 pesaing', 'help' => 'Hotel yang dibandingkan wisatawan dengan hotel Anda.', 'action' => 'Tambah pesaing'],
        'prompts' => ['title' => 'Tinjau prompt Anda', 'help' => 'Aktifkan minimal 10 prompt: pertanyaan yang diajukan wisatawan kepada AI.', 'action' => 'Tinjau prompt'],
        'scan' => ['title' => 'Jalankan scan pertama', 'help' => 'Lihat bagaimana asisten AI membicarakan hotel Anda.', 'action' => 'Scan sekarang'],
        'recommendation' => ['title' => 'Buka rekomendasi pertama', 'help' => 'Buka satu dan tandai To do, In progress, atau Done.', 'action' => 'Lihat rekomendasi'],
        'openai_key' => ['title' => 'Tambahkan kunci OpenAI dan anggaran', 'help' => 'Kunci milik Anda sendiri, sudah diuji, dengan batas bulanan.', 'action' => 'Tambah kunci'],
        'google' => ['title' => 'Hubungkan Google', 'help' => 'Bagikan Google Analytics dan Search Console, lalu pilih properti Anda.', 'action' => 'Hubungkan Google'],
        'script' => ['title' => 'Pasang skrip situs web', 'help' => 'Menghitung kunjungan AI dan klik pemesanan di situs web Anda sendiri.', 'action' => 'Ambil skrip'],
        'health' => ['title' => 'Periksa kesehatan pelacakan', 'help' => 'Pemeriksaan pelacakan tidak menunjukkan kesalahan.', 'action' => 'Buka pemeriksaan'],
        'upgrade' => ['title' => 'Upgrade saat Anda siap', 'help' => 'Memilih paket membuka semua yang terkunci selama masa percobaan.', 'action' => 'Lihat paket'],
    ],
];
