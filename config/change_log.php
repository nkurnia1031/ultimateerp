<?php

return [
    'releases' => [
        [
            'release_id' => 'release-2026-09-10-angkutan',
            'slug' => 'angkutan-spb-dan-bonus-ayak',
            'title' => 'Perjalanan angkutan, SPB, dan rekap upah sopir',
            'summary' => 'Sistem sekarang dapat mencatat perjalanan angkutan dari SPB sampai rekap unit, sopir, tagihan pelanggan, dan payroll.',
            'benefit' => 'Data perjalanan cukup dicatat sekali, lalu dapat ditelusuri kembali dari Project, invoice, Sell, payroll, dan ledger alat.',
            'audience' => 'Admin tarif, dispatcher, bagian penagihan, dan bagian payroll.',
            'terms' => [
                [
                    'term' => 'SPB',
                    'description' => 'Surat Perintah Bongkar. Di aplikasi, satu SPB mewakili satu perjalanan angkutan, satu unit, satu sopir, dan satu tonase aktual.',
                ],
                [
                    'term' => 'Verifikasi',
                    'description' => 'Pemeriksaan akhir bahwa data perjalanan sudah benar dan boleh dipakai untuk tagihan pelanggan atau rekap upah.',
                ],
                [
                    'term' => 'Settlement',
                    'description' => 'Rekap sumber pembayaran untuk satu sopir dan satu periode. Isinya dapat mencakup upah SPB, Bonus Ayak, dan potongan Kasbon.',
                ],
            ],
            'preparation' => [
                'Pastikan master unit, sopir, rute, dan tarif angkutan sudah tersedia.',
                'Siapkan tanggal perjalanan, project/customer, tonase aktual, dan dokumen SPB.',
                'Gunakan data pada business yang sedang aktif. Data dari business lain tidak boleh dicampur.',
            ],
            'steps' => [
                [
                    'title' => 'Siapkan tarif dan master',
                    'description' => 'Buka Asset Management, pastikan unit alat dan sopir sudah terdaftar, lalu buka Tarif Angkutan. Saat memakai Excel tarif, pilih file dan isi tanggal berlaku secara manual, tekan Upload & Preview, lalu periksa draft hasil upload sebelum mengaktifkan tarif. Untuk workbook perjalanan, gunakan Import SPB Angkutan agar sheet dan nomor baris sumber dapat diperiksa sebelum baris siap diimpor. Tanggal yang tertulis di keterangan Excel tidak ditebak oleh sistem.',
                ],
                [
                    'title' => 'Buat satu perjalanan',
                    'description' => 'Pada SPB Angkutan tekan Buat Trip. Isi nomor tiket, tanggal SPB, tanggal muat/bongkar bila ada, lalu pilih rute, unit/kendaraan, sopir, Project, dan customer. Isi Gross, Tare, Netto, Diterima, Ton Tagihan, Selisih, Toleransi, Tarif Klaim/Kg, dan Potongan Lain sesuai dokumen. Sistem menghitung pendapatan pelanggan serta upah sopir dari snapshot tarif.',
                ],
                [
                    'title' => 'Selesaikan dan verifikasi',
                    'description' => 'Periksa kembali Gross, Tare, Netto, Diterima, Ton Tagihan, Nilai dasar, Penyesuaian klaim, Nilai akhir, unit, dan sopir. Tekan Selesaikan lalu Verify jika data sudah disetujui. Contoh uji 23 ton gross dikurangi 2 ton tare menghasilkan 21 ton netto; 21 ton × Rp115.000 = Rp2.415.000 nilai dasar, lalu klaim Rp150.000 membuat nilai akhir Rp2.565.000 setelah potongan lain nol.',
                ],
                [
                    'title' => 'Buat tagihan pelanggan bila diperlukan',
                    'description' => 'Dari halaman Project buka tab Trip Angkutan lalu tekan Pilih SPB untuk Invoice. Project dan customer sudah menjadi konteks, dan daftar hanya berisi SPB Project yang verified serta belum ditagihkan. Periksa Ton Tagihan dan Pendapatan, tekan Lanjutkan, pilih lokasi bisnis, tinjau preview, lalu buat invoice. Dari Sell gunakan Angkutan dari SPB. Satu SPB tidak boleh masuk dua tagihan.',
                ],
                [
                    'title' => 'Catat biaya sopir secara terpisah',
                    'description' => 'Bonus Ayak dapat dibuat manual atau dihubungkan ke beberapa SPB. Bonus Ayak bukan baris tagihan pelanggan. Pada Review Settlement, pilih upah SPB, Bonus Ayak, dan Kasbon yang benar sebelum membuat draft dan mem-posting Payroll.',
                ],
                [
                    'title' => 'Baca rekap unit dan sopir',
                    'description' => 'Buka Rekap Alat & Sopir untuk melihat jumlah perjalanan, tonase, pendapatan pelanggan, upah sopir, dan kontribusi. Ledger unit dan Project membantu menelusuri sumber setiap angka.',
                ],
            ],
            'expected_results' => [
                'SPB yang verified terlihat pada daftar dan dapat ditelusuri dari unit, Project, serta rekap sopir.',
                'Pendapatan pelanggan dan biaya/upah sopir tampil sebagai angka terpisah.',
                'Tarif yang berubah kemudian tidak mengubah snapshot nominal SPB yang sudah tersimpan.',
                'SPB yang sudah ditagihkan atau masuk settlement tidak tersedia lagi untuk proses yang sama.',
            ],
            'example' => [
                'title' => 'Contoh yang sudah diuji',
                'body' => 'SPB TRIP-202609-0004 dengan tonase 20 ton menghasilkan pendapatan pelanggan Rp2.300.000 (20 × Rp115.000) dan total upah sopir Rp2.600.000 sesuai snapshot perjalanan. Angka ini bukan berarti invoice dan payroll digabung: pendapatan masuk tagihan pelanggan, sedangkan upah masuk settlement/payroll.',
            ],
            'verified_step_description' => 'Periksa kembali Gross, Tare, Netto, Diterima, Ton Tagihan, Nilai dasar, Penyesuaian klaim, Nilai akhir, unit, dan sopir. Tekan Selesaikan lalu Verify jika data sudah disetujui. Contoh uji 23 ton gross dikurangi 2 ton tare menghasilkan 21 ton netto; 21 × Rp115.000 = Rp2.415.000 nilai dasar. Jika penyesuaian klaim positif Rp150.000 dan potongan lain nol, nilai akhir menjadi Rp2.565.000.',
            'verified_example_body' => 'Contoh uji: 23 ton gross dikurangi 2 ton tare menghasilkan 21 ton netto. Dengan tarif Rp115.000 per ton, nilai dasar adalah 21 × Rp115.000 = Rp2.415.000. Jika penyesuaian klaim positif Rp150.000 dan potongan lain nol, nilai akhir menjadi Rp2.565.000. Pendapatan pelanggan dan upah sopir tetap dicatat terpisah.',
            'common_issues' => [
                [
                    'problem' => 'SPB tidak muncul saat memilih invoice atau settlement.',
                    'solution' => 'Periksa business aktif, status harus Verified, tanggal harus masuk periode, dan SPB belum pernah ditagihkan atau disettle.',
                ],
                [
                    'problem' => 'Tarif atau unit tidak dapat dipilih.',
                    'solution' => 'Pastikan unit sudah terhubung ke asset dan ada tarif yang berlaku pada tanggal perjalanan. Jangan menebak tarif yang kosong; perbaiki master atau minta admin melakukan override sesuai prosedur.',
                ],
                [
                    'problem' => 'Nominal Bonus Ayak tidak muncul sebagai baris invoice.',
                    'solution' => 'Itu perilaku yang benar. Bonus Ayak adalah komponen biaya/penghasilan sopir untuk payroll dan margin Project, bukan pendapatan yang ditagihkan kepada pelanggan.',
                ],
                [
                    'problem' => 'Settlement sudah diposting dan ingin diubah.',
                    'solution' => 'Settlement posted tidak diedit langsung. Gunakan proses koreksi yang disediakan dan minta pemeriksa payroll mengikuti prosedur koreksi.',
                ],
            ],
            'separation_note' => 'Pendapatan pelanggan dihitung dari tonase tagihan dan tarif snapshot. Upah sopir dihitung dari uang jalan, borongan, dan komponen Bonus Ayak yang dipilih untuk payroll. Keduanya dapat dilihat bersama untuk margin, tetapi tidak menjadi satu nominal atau satu baris invoice.',
            'category' => 'Angkutan',
            'order' => 100,
            'permission' => null,
            'module' => 'AssetManagement',
            'subscription_permission' => null,
            'published_at' => '2026-09-10',
            'status' => 'published',
            'related_links' => [
                [
                    'label' => 'Buka SPB Angkutan',
                    'route' => 'asset.fleet.trips.index',
                    'permission' => 'asset.fleet_trips.manage',
                    'module' => 'AssetManagement',
                ],
                [
                    'label' => 'Buka Rekap Alat & Sopir',
                    'route' => 'asset.fleet.reports.index',
                    'permission' => 'asset.view',
                    'module' => 'AssetManagement',
                ],
            ],
        ],
    ],
];
