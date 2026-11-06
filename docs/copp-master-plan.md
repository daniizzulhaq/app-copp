# COPP Luxury

Master plan ini menggantikan katalog dan data contoh NexaVest sebelumnya.

| Harga plan | Maks. ROI harian | Maks. total ROI | Periode maksimal |
| --- | --- | --- | --- |
| Rp100.000 | 5% | 210% | 42 hari |
| Rp200.000 | 5% | 250% | 50 hari |
| Rp500.000 | 7% | 300% | 43 hari |
| Rp1.000.000 | 7% | 350% | 50 hari |
| Rp2.000.000 | 10% | 350% | 35 hari |
| Rp5.000.000 | 10% | 400% | 40 hari |

- Nominal pembelian sama dengan harga plan. Batas tiga pembelian berlaku per produk per akun, termasuk pembelian yang sudah selesai dan lintas workspace.
- Harga produk ditentukan admin, mulai Rp1; pembelian tidak memiliki batas minimum global Rp100.000. Form mengikuti harga atau minimum produk yang dipilih. Produk dengan nominal tetap harus dibeli sesuai harga, sedangkan produk dengan pilihan mulai dari minimum dapat dibeli sebesar minimum produk atau lebih.
- Total ROI adalah hasil ROI saja, dengan modal dikembalikan terpisah dan penuh ke saldo web. Periode dihitung dengan pembulatan ke atas dari total ROI / ROI harian. Hari terakhir dipotong sesuai sisa batas total; plan Rp500.000 menghasilkan maksimal Rp1.500.000 ROI, bukan Rp1.505.000.
- Rate, durasi, batas harian, dan batas total disimpan saat pembelian. Edit produk tidak mengubah portofolio lama.
- Mekanisme klaim harian tetap berlaku: hari pertama sehari setelah pembelian, harus diklaim pada tanggal jatuh tempo, dan hak yang tidak diklaim hangus. Nilai hangus tetap dihitung dalam batas total ROI.
- Pengembalian modal diproses saat halaman akun yang memakai workspace diakses (beranda, wallet, portofolio, penarikan, dan transaksi), serta saat persiapan klaim ROI. Cronjob/scheduler tidak diwajibkan untuk alur ini. Jika tidak ada akses, pencatatan dilakukan pada akses berikutnya, menggunakan tanggal server WIB dan jatuh tempo portofolio yang tersimpan. Modal tidak perlu diklaim manual dan tidak dibayar dua kali. Sumber nominal adalah `principal` yang tersimpan saat pembelian; perubahan harga produk tidak mengubah modal yang dikembalikan. Pengembalian lama yang baru terbayar sebagian diselesaikan dengan mengkreditkan sisanya. Klaim ROI tetap manual dan tidak ikut dikreditkan otomatis.
- Operasional COPP menggunakan saldo web tanpa kas admin. Pembelian tetap memotong saldo tersedia member dan menyimpan modal pada portofolio. Klaim ROI dan pengembalian modal tidak dibatasi kas atau deposit member lain. Contoh modal Rp1.000.000 dikembalikan Rp1.000.000 penuh saat jatuh tempo, terpisah dari ROI. Dashboard, operasional, dan laporan menampilkan saldo wallet serta modal/ROI member.
- Pencatatan tetap memakai jurnal seimbang dan riwayat permanen. Akun `wallet-offset` hanya menjadi pasangan pencatatan saldo web, dengan saldo yang dapat positif/negatif dan tidak berfungsi sebagai batas pembayaran. Saldo wallet member tetap tidak boleh negatif. Histori lama tidak dihapus atau ditulis ulang. Modul eksperimen terpisah (`mode=simulation`) mempertahankan perilaku simulasinya.
- Jaringan matahari berarti jumlah referral langsung tidak dibatasi. Komisi berjalan sampai 15 tingkat ke atas tanpa penempatan binary/matrix.
- Dasar komisi adalah nominal setiap top up manual yang disetujui admin: level 1 sebesar 15%, level 2 sebesar 3%, level 3–5 masing-masing 2%, level 6–15 masing-masing 1%. Top up yang ditolak, dibatalkan, kedaluwarsa, atau belum disetujui tidak dihitung.
- Misi menggunakan referral langsung. Target registrasi menghitung seluruh akun yang terhubung lewat kode referral, tanpa reset harian. Setiap target undang akun (3 dan 15 akun) dibayar hanya sekali per akun, termasuk lintas workspace. Bonus registrasi lama yang sudah dibayar dengan aturan harian tetap dianggap sudah diklaim; histori dan saldo tidak ditulis ulang.
- Seluruh target kumulatif: target 15 registrasi membayar Rp5.000 + Rp15.000, masing-masing hanya 1x per akun. Target top up tetap harian (WIB), menghitung akun unik yang top up-nya disetujui hari itu; banyak top up satu akun tetap satu akun. Target 15 akun top up membayar Rp25.000 + Rp100.000, masing-masing sekali per akun per tanggal WIB.
- Misi omset memakai total top up referral langsung yang disetujui. Target Rp100.000/Rp5.000.000/Rp25.000.000 membayar Rp2.000/Rp20.000/Rp150.000, masing-masing sekali per akun.
- Bonus otomatis masuk wallet melalui ledger dan dicatat dalam `member_bonuses`. ID transaksi/top up dan periode misi mencegah kredit ganda. Pengkreditan mengikuti model cadangan wallet lokal; tidak melakukan transfer bank otomatis.
- Minimal WD Rp50.000, admin 10% dari nominal pengajuan. Contoh Rp50.000 dipotong Rp5.000 sehingga nilai transfer Rp45.000.

Sumber parameter: `config/copp.php`. Seeder utama menjalankan `RoleSeeder`, `CoppSeeder`, dan `LocalWorkspaceSeeder`; hanya membuat admin Didisaidi, enam produk COPP, dan workspace kosong, tanpa member testing, transaksi, atau skenario contoh.

Akun awal: nama `Didisaidi`, username `Didisaidi` (tidak membedakan huruf besar/kecil), email login `admin@copp.production`, dengan password awal sesuai `AccountSeeder` yang di-hash di database dan disimpan dalam catatan akun di `storage/app/private/copp-accounts.txt`. `CoppSeeder` memanggil `AccountSeeder` setelah memperbarui paket COPP. Admin yang diarsipkan tidak diaktifkan kembali.

Untuk reset khusus akun di hosting, upload kode terbaru, jalankan `php artisan migrate --force` untuk menambahkan kolom username, lalu `php artisan db:seed --class=AccountSeeder --force` dari folder aplikasi yang berisi `artisan`. Perintah ini mengganti email admin lama `admin@copp.test` menjadi `admin@copp.production` pada ID akun yang sama, menetapkan username Didisaidi, dan mengarsipkan akun testing `member@copp.test` jika masih ada, serta mencabut sesi dan token reset password kedua akun tersebut. Produk, pengaturan, saldo, dan riwayat transaksi dipertahankan; akun member lain tidak berubah. Seeder menolak jika email lama dan baru sudah dipakai dua akun berbeda atau username Didisaidi milik akun lain. Akun member testing tidak dibuat lagi. Tidak perlu menjalankan `migrate:refresh` untuk memperbarui akun.

Login menerima email atau username melalui kolom yang sama. Username opsional dapat diisi ketika mendaftar, pada profil, atau melalui form member di admin; 3–30 karakter berupa huruf, angka, atau garis bawah. Akun lama yang belum memiliki username tetap dapat login dengan email. Pembatasan percobaan login dihitung per akun dan alamat IP, sehingga bergantian memakai email dan username tidak menghindari batas percobaan.

Penggantian demo lama dilakukan dengan `php scripts/reset-copp-demo.php`. Skrip hanya menerima lingkungan lokal dan akun demo NexaVest yang dikenali, membuat backup SQL lengkap di `storage/app/private/backups`, lalu mengisi database COPP baru. Skrip menolak dijalankan ulang setelah akun COPP tersedia.

Verifikasi: `php vendor/phpunit/phpunit/phpunit`, `php artisan nexavest:verify-ledger`, `php artisan view:cache`, dan `npm.cmd run build`. Tes memakai database `nexavest_claims_test` yang terpisah dari workspace.
