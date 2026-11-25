<?php

/*
| Tur terpandu (lihat App\Services\Tours\TourDefinitions). Setiap langkah punya judul singkat dan teks yang ramah.
*/

return [
    'ui' => [
        'previous' => 'Sebelumnya',
        'next' => 'Berikutnya',
        'done' => 'Selesai',
        'skip' => 'Lewati',
        'dont_show' => 'Jangan tampilkan lagi',
        'counter' => ':current dari :total',
        'close' => 'Tutup tur',
        'dialog' => 'Tur terpandu',
        'help' => 'Bantuan dan tur',
        'take_tour' => 'Ikuti tur halaman ini',
        'no_tour' => 'Halaman ini tidak punya tur',
        'restart_all' => 'Mulai ulang semua tur',
        'restarted' => 'Selesai. Tur akan muncul lagi saat Anda membuka tiap halaman.',
        'nothing' => 'Belum ada yang bisa ditampilkan di halaman ini.',
    ],

    'dashboard' => [
        'name' => 'Dasbor',
        'steps' => [
            'switcher' => ['title' => 'Ganti hotel', 'text' => 'Jika tim Anda punya lebih dari satu hotel, pilih hotel yang ingin dilihat di sini. Seluruh halaman mengikuti pilihan Anda.'],
            'score' => ['title' => 'Skor visibilitas', 'text' => 'Ini seberapa sering asisten AI menyebut hotel Anda saat wisatawan bertanya tentang destinasi Anda, beserta perubahan sejak pemindaian sebelumnya.'],
            'trend' => ['title' => 'Tren', 'text' => 'Setiap titik adalah satu pemindaian. Garis yang naik berarti makin banyak wisatawan mengenal hotel Anda. Pilih "By persona" untuk melihat tipe tamu yang berbeda.'],
            'last_scan' => ['title' => 'Pemindaian terakhir', 'text' => 'Kapan hotel Anda terakhir diperiksa dan kapan pemindaian berikutnya dijadwalkan. Gunakan "Scan now" di sebelahnya untuk memeriksa lagi.'],
            'menu' => ['title' => 'Menu Anda', 'text' => 'Prompt adalah pertanyaan yang kami ajukan. Scan adalah pemeriksaannya. Citations menunjukkan situs yang dipercaya AI. Insights menjelaskan mengapa pesaing unggul. Recommendations memberi tahu apa yang harus diperbaiki lebih dulu.'],
            'trial' => ['title' => 'Selama masa percobaan', 'text' => 'Beberapa fitur terkunci selama Anda mencoba, misalnya sebagian besar detail rekomendasi. Memilih paket akan membukanya.'],
            'help' => ['title' => 'Bantuan selalu ada', 'text' => 'Klik tanda tanya untuk mengikuti tur halaman mana pun lagi, atau untuk memulai ulang semua tur.'],
        ],
    ],

    'recommendations' => [
        'name' => 'Rekomendasi',
        'steps' => [
            'list' => ['title' => 'Daftar tugas berurutan', 'text' => 'Setiap rekomendasi berasal dari pemindaian Anda. Daftarnya diurutkan: yang paling membantu dan paling cepat dikerjakan ada di atas.'],
            'quick_wins' => ['title' => 'Quick wins', 'text' => 'Dampak besar dan butuh satu jam kerja atau kurang. Kalau waktu Anda terbatas, mulailah dari sini.'],
            'looks_done' => ['title' => '"Looks done"', 'text' => 'Jika pemindaian berikutnya menunjukkan masalahnya sudah hilang, rekomendasi ditandai "Looks done". Konfirmasi, atau pilih "Not yet" jika Anda tidak setuju.'],
            'effort_impact' => ['title' => 'Usaha dan dampak', 'text' => 'Usaha adalah lamanya pengerjaan, dampak adalah seberapa besar visibilitas Anda bisa naik. Pakai filter untuk menemukan pekerjaan kecil dengan efek besar.'],
            'content' => ['title' => 'Buka rekomendasi', 'text' => 'Klik satu baris untuk membaca apa yang perlu diubah dan alasannya. Di dalamnya, tombol konten menulis teksnya untuk Anda, misalnya bagian halaman atau jawaban untuk situs Anda.'],
            'paste' => ['title' => 'Salin, tempel, selesai', 'text' => 'Kontennya siap disalin. Baris "Where" memberi tahu di mana menempelkannya: situs Anda, Google Business Profile, atau situs pemesanan. Lalu ubah status menjadi Done.'],
            'locked' => ['title' => 'Terkunci selama percobaan', 'text' => 'Pada masa percobaan Anda hanya melihat judul dan kategori tiap rekomendasi. Memilih paket membuka langkah lengkap dan penulis konten.'],
        ],
    ],

    'prompts-scans' => [
        'name' => 'Prompt dan scan',
        'steps' => [
            'prompts' => ['title' => 'Apa itu prompt', 'text' => 'Prompt adalah pertanyaan yang diajukan wisatawan kepada asisten AI, misalnya "hotel butik terbaik di Ubud". Perubahan tersimpan otomatis. Nama hotel Anda tidak pernah kami masukkan, supaya jawabannya menunjukkan bagaimana wisatawan benar-benar menemukan Anda.'],
            'regenerate' => ['title' => 'Prompt yang disarankan', 'text' => 'Kami menyarankan prompt untuk hotel Anda. "Regenerate" mengganti saran yang belum Anda ubah; prompt buatan Anda dan yang sudah diedit tetap aman.'],
            'run_scan' => ['title' => 'Jalankan scan', 'text' => 'Tekan "Scan now" di dasbor, atau tunggu scan terjadwal. Setiap scan manual memakai satu dari scan yang termasuk dalam paket Anda tiap bulan; dasbor menunjukkan sisanya.'],
            'history' => ['title' => 'Riwayat scan', 'text' => 'Semua scan tercantum di sini. Filter berdasarkan status atau siapa yang memulai, lalu buka scan untuk melihat jawabannya.'],
            'progress' => ['title' => 'Arti statusnya', 'text' => 'Queued berarti menunggu, running berarti jawaban sedang dikumpulkan (bar menunjukkan berapa yang selesai), completed berarti hasil siap, failed berarti ada masalah pada scan itu.'],
        ],
    ],

    'reports' => [
        'name' => 'Laporan',
        'steps' => [
            'date_picker' => ['title' => 'Pilih tanggal', 'text' => 'Pilih preset seperti "Last 28 days" atau buka kalender untuk rentang sendiri. Rentang selalu berada dalam hari yang sudah kami sinkronkan.'],
            'compare' => ['title' => 'Bandingkan', 'text' => 'Bandingkan dengan periode sebelumnya atau periode yang sama tahun lalu. Garis putus-putus pada grafik dan angka % pada kartu menunjukkan perubahannya. Pilihan diredupkan jika kami belum punya data sejauh itu.'],
            'kpi' => ['title' => 'Angka utama', 'text' => 'Kunjungan, pembelian, dan pendapatan yang datang dari asisten AI, serta persentasenya dari seluruh lalu lintas Anda. Baris kecil di bawah tiap angka adalah perubahan dibanding pembanding Anda.'],
            'tables' => ['title' => 'Tabel', 'text' => 'Lihat platform AI, negara, dan halaman tujuan mana yang membawa pengunjung. Klik judul kolom untuk mengurutkan semua baris, dan pakai kontrol halaman di bawah.'],
            'csv' => ['title' => 'Unduh sebagai CSV', 'text' => 'Tombol CSV memberi semua baris pada tanggal yang dipilih, bukan hanya halaman yang Anda lihat, siap dibuka di spreadsheet.'],
            'searches' => ['title' => 'Pencarian menurut tanggal', 'text' => 'Tanggal yang dicari tamu di mesin pemesanan Anda. Ini pencarian, bukan pemesanan: tanggal yang ramai menunjukkan di mana permintaan sedang naik.'],
        ],
    ],

    'settings' => [
        'name' => 'Pengaturan',
        'steps' => [
            'ai_key' => ['title' => 'Kunci OpenAI Anda', 'text' => 'Pada paket Private, setiap panggilan AI tim Anda memakai kunci OpenAI milik Anda sendiri, sehingga Anda membayar langsung ke OpenAI. Tempel di sini; kunci disimpan terenkripsi dan hanya 4 karakter terakhir yang ditampilkan.'],
            'budget' => ['title' => 'Anggaran bulanan', 'text' => 'Batas pengaman untuk kunci Anda, selain batas pengeluaran di OpenAI. Tetapkan biaya AI maksimum per bulan; bar menunjukkan berapa yang sudah terpakai.'],
            'google_share' => ['title' => 'Bagikan akses kepada kami', 'text' => 'Hanya baca: kami bisa melihat laporan, tidak pernah mengubah apa pun. Salin email service account dan tambahkan di GA4 sebagai Viewer (Admin > Property access management) dan di Search Console dengan izin Restricted (Settings > Users and permissions).'],
            'google_pick' => ['title' => 'Pilih properti Anda', 'text' => 'Tekan "Check connection", lalu pilih properti GA4 dan situs Search Console Anda. Data pertama masuk dalam beberapa jam.'],
            'google_menu' => ['title' => 'Kembali kapan saja', 'text' => 'Connect Google, kunci AI, dan pengaturan lainnya ada di menu Settings.'],
        ],
    ],
];
