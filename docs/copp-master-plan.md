# COPP Gotong Royong

Ketentuan aktif sejak perubahan 20 November 2026. Katalog terbaru hanya satu paket Rp100.000. Halaman depan langsung menampilkan form pendaftaran.

| Harga produk | Bagian ROI | Maksimal pembelian per akun | Batas total ROI |
| --- | --- | --- | --- |
| Rp100.000 | 1 | 10 | Rp210.000 |

Pool harian adalah 25% omzet pembelian produk yang berhasil di workspace aktif, termasuk pembelian ulang. Deposit tidak menambah omzet produk. Pada plan `turnover_v2`, pembelian tanggal 21 masuk omzet tanggal 21 dan hasil pembagiannya dapat diklaim tanggal 22, mulai pukul 00.00 WIB. Pembelian baru tanggal 22 memengaruhi klaim tanggal 23.

Peserta adalah seluruh produk aktif sampai akhir tanggal sumber omzet, termasuk produk yang baru dibeli pada tanggal tersebut, dengan sisa batas ROI. Produk berstatus selesai atau default tidak masuk pembagi. Setiap produk mendapat satu jatah yang sama, tanpa bobot harga atau bagian lama. Jumlah produk dan sisa batas setiap peserta disimpan pada `daily_roi_claim_pools` / `daily_roi_claim_shares`; `period_date` adalah tanggal sumber omzet dan `claim_date` tanggal klaim. Kolom `total_shares` menyimpan jumlah produk peserta dan `shares` bernilai 1 per produk untuk pool baru. Profit besok per produk = pembulatan ke bawah ((omzet pembelian hari ini × 25%) / jumlah produk aktif), maksimal sisa batasnya. Contoh: omzet Rp1.000.000 menghasilkan dana ROI Rp250.000; dibagi 1.000 produk aktif berarti profit Rp250 per produk besok. Member dengan 10 produk aktif mendapat Rp2.500. Profit berubah mengikuti omzet dan jumlah produk aktif; omzet ROI nol berarti profit nol. Tanpa peserta, tidak ada pembagian. Bonus berasal dari pos lain pada omzet dan tidak mengurangi lagi pool ROI 25%.

Klaim tetap manual pada tanggal haknya, sebelum pukul 00.00 WIB hari berikutnya. Pool dibentuk sekali dari omzet hari yang sudah selesai; pembelian baru tidak mengubah nominal klaim hari ini. Dashboard dan portofolio menyiapkan hak saat dibuka, sehingga tidak harus menunggu cron. Keterlambatan akses atau scheduler mengejar hak hari sebelumnya dan menghanguskan hak yang sudah melewati tanggal klaim. Tanpa omzet kemarin, tidak ada tambahan ROI hari ini.

Migrasi mengaktifkan `turnover_v2` pada workspace `turnover_v1` operasional yang belum diarsipkan, mulai tanggal migrasi. Sumber pertama adalah omzet sehari sebelumnya. Riwayat pool lama, jurnal, saldo, produk, dan bonus tidak dihapus. Pembayaran dari pool lama diperhitungkan dan dikurangkan dari anggaran sumber yang sama agar tidak dibayar ulang. Pool lama yang belum dibayar bisa menjadi sumber klaim esok hari; hak lama yang hangus tetap tersimpan sesuai riwayat. Masa sebelum tanggal transisi tidak diputar ulang. `turnover_v1` tetap didukung untuk membaca dan menguji perilaku historis.

Tidak ada persentase keuntungan tetap atau tenggat jumlah hari. Produk berhenti mengikuti pool berikutnya setelah batas total ROI tercapai. Batas dihitung dari seluruh hak yang dialokasikan, termasuk hak yang hangus. Pembulatan rupiah, pool tanpa peserta, dan bagian yang melebihi sisa batas tidak dibagikan ulang. Angka pool admin merupakan anggaran; jumlah hak aktual bisa lebih kecil karena hal tersebut.

Batas penerimaan produk adalah Rp210.000 di luar bonus. Setelah batas alokasi tercapai, paket selesai dan tidak menerima ROI berikutnya. Tidak ada tambahan pengembalian modal Rp100.000. Harga, bagian, batas, dan kebijakan pengembalian modal disimpan saat pembelian; riwayat kontrak lama tetap memakai ketentuan yang tersimpan.

## Bonus

- Bonus akun baru: Rp5.000, diklaim melalui tombol pada dashboard atau halaman referral, sekali per akun. Akun yang sudah ada sebelum reset tidak otomatis mendapat bonus daftar baru.
- Undangan: Rp5.000 kepada pengundang untuk setiap akun baru yang mendaftar lewat kodenya, sekali per akun yang diundang.
- Cashback: Rp10.000 sekali per akun setelah pembelian produk pertama yang berhasil di workspace aktif. Tidak dibayar ulang pada pembelian berikutnya. Pengujian dan seeder tidak mengkreditkan saldo awal.
- Pengundang langsung adalah level 1 dan menerima 25% nominal pembelian (Rp25.000 untuk paket Rp100.000). Empat upline berikutnya adalah level 2?5, masing-masing Rp5.000 sesuai ketentuan sebelumnya. Jaringan berhenti setelah lima upline total; level 6 tidak menerima komisi. Cabang referral langsung tidak dibatasi.
- Komisi berasal dari aktivasi, bukan deposit. Penerima nonaktif atau kohor tidak menerima bonus; tingkatan tetap ditelusuri tanpa memindahkan bonusnya ke penerima lain.
- Bonus jaringan dibayar setiap pembelian berhasil oleh member yang sama, termasuk pembelian menggunakan saldo pemberian admin. Tiga pembelian Rp100.000 membayar total Rp75.000 ke level 1 dan Rp15.000 ke masing-masing level 2 sampai 5. Setiap bonus baru mencantumkan nomor pembelian pada riwayat agar dapat dicocokkan satu per satu.
- Kunci unik per akun, undangan, dan investasi mencegah bonus ganda saat retry. Bonus masuk wallet melalui jurnal seimbang dan dicatat di `member_bonuses`.
- Minimal WD Rp30.000, admin 10%. Pengajuan Rp30.000 menghasilkan transfer bersih Rp27.000. WD tetap melalui persetujuan admin dan mensyaratkan pembelian produk.

## Penerapan dan reset

Jalankan dari folder proyek:

```bash
php artisan migrate --force
php artisan db:seed --class=ProductPlanSeeder --force
php artisan optimize:clear
```

Untuk perbaikan tampilan klaim, unggah juga hasil `npm run build` di `public/build` beserta view dan JavaScript terbaru. Kartu klaim berada di bawah saldo, tetap menampilkan status ketika nominal belum tersedia atau sudah diklaim, dan memuat ulang hak harian saat tanggal aplikasi berganti atau tab lama dibuka kembali. Tombol **Perbarui klaim** juga memuat ulang data dari server. Rumus pool dan bonus tetap mengikuti ketentuan di atas.

Jika klaim belum tampil di hosting, jalankan `php artisan copp:roi-check USERNAME` (atau email member). Perintah ini membaca waktu aplikasi, plan workspace, tanggal sumber omzet, produk, dan hak klaim tersimpan, tanpa mengubah saldo atau hak klaim. Pastikan `APP_TIMEZONE=Asia/Jakarta`; setelah unggah jalankan `php artisan optimize:clear` untuk membersihkan cache view dan konfigurasi lama. Buat backup database sebelum migrasi; tidak perlu reset atau menjalankan seeder akun.

Penerapan aturan final tidak memerlukan reset saldo atau akun. Backup SQL dibuat sebelum perubahan skema. Perintah reset awal `copp:activate-plan --reset-members` membuat backup SQL penuh sebelum reset. Akun, password, dan jaringan referral dipertahankan. Seluruh workspace lokal lama diarsipkan dan workspace baru dimulai tanpa saldo atau produk, sehingga riwayat lama tetap dapat diperiksa admin. Limit pembelian plan baru dihitung per harga produk dalam workspace aktif. Pengulangan perintah setelah plan aktif tidak mereset ulang saldo.

`migrate:fresh --seed` tetap merupakan reset database penuh: seluruh akun dan riwayat dihapus, kemudian dibuat admin, satu produk, workspace baru, dan satu akun member testing. Tidak diperlukan untuk menerapkan perubahan ini pada database yang sudah berisi akun.

Seeder katalog terpisah `ProductPlanSeeder` tidak mengubah password admin. Seeder utama menjalankan `CoppSeeder` (katalog + akun admin), `LocalWorkspaceSeeder`, dan `TestMemberSeeder`. Produk lain diarsipkan. Member hanya melihat katalog aktif.

Admin: username `didisaidi`, email `admin@copp.production`, password awal `admin123`. Member testing: username `membertest`, email `tester@copp.test`, password `MemberTest123`. Seeder akun menetapkan ulang password akun terkait; aktivasi plan tidak menjalankan seeder akun.

Perilaku ROI tetap dan bonus lama dipertahankan untuk pengujian serta pembacaan workspace berlabel `legacy`; workspace lama yang diarsipkan tidak melanjutkan akrual atau klaim.

Verifikasi: `php vendor/phpunit/phpunit/phpunit`, `php artisan nexavest:verify-ledger`, `php artisan view:cache`, dan `npm.cmd run build`. Tes menggunakan database `nexavest_claims_test` yang terpisah dari database aplikasi.

Grafik dashboard menampilkan member unik yang penarikannya sudah diselesaikan admin setiap hari, serta total bersih setelah admin. Pengajuan pending/ditolak dan workspace lain tidak dihitung. Tidak ada data WD contoh atau identitas/rekening yang dimasukkan ke grafik.

Pembelian paket terbaru dicatat pada akun ledger `product-sales`, karena tidak ada kewajiban mengembalikan pokok. Kontrak lama yang mengembalikan modal tetap memakai akun `capital`. Verifikasi ledger memeriksa kedua akun terhadap subledger sesuai kebijakan pembelian, tanpa mengubah jurnal historis.
