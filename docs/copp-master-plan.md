# COPP Gotong Royong

Ketentuan aktif sejak perubahan 20 November 2026. Ini menggantikan katalog enam produk dan ROI tetap harian.

| Harga produk | Bagian ROI | Maksimal pembelian per akun | Batas total ROI |
| --- | --- | --- | --- |
| Rp100.000 | 1 | 10 | Rp210.000 |
| Rp1.000.000 | 10 | 10 | Rp2.100.000 |

Pool harian adalah 60% omzet pembelian produk yang berhasil di workspace aktif, termasuk pembelian ulang. Deposit tidak menambah omzet produk. Tanpa pembelian pada suatu tanggal, ROI tanggal itu nol.

Peserta hari itu adalah produk yang aktif sejak pukul 00.00 WIB; produk baru ikut mulai keesokan hari. Bagian dan sisa batas setiap peserta disimpan pada `daily_roi_pools` / `daily_roi_shares`. Ini menjaga pembagi tetap sepanjang hari. Hak produk = pembulatan ke bawah (pool ? bagian produk / total bagian), maksimal sisa batasnya. Contoh omzet Rp1.000.000 menghasilkan pool Rp600.000; pada total 1.000 bagian, satu bagian mendapat Rp600, dan sepuluh bagian mendapat Rp6.000.

Klaim tetap manual pada tanggal haknya, sebelum pukul 00.00 WIB. Tambahan omzet di hari yang sama menambah hak; member dapat mengklaim selisihnya tanpa membayar kembali bagian yang sudah diklaim. Jurnal pengakuan dan pembayaran memakai kunci yang berbeda untuk setiap kenaikan. Keterlambatan scheduler tetap mengejar tanggal sebelumnya, lalu menghanguskan hak yang tidak diklaim sesuai aturan klaim lama.

Tidak ada persentase keuntungan tetap atau tenggat jumlah hari. Produk berhenti mengikuti pool berikutnya setelah batas total ROI tercapai. Batas dihitung dari seluruh hak yang dialokasikan, termasuk hak yang hangus. Pembulatan rupiah, pool tanpa peserta, dan bagian yang melebihi sisa batas tidak dibagikan ulang. Angka pool admin merupakan anggaran; jumlah hak aktual bisa lebih kecil karena hal tersebut.

Batas total ROI adalah hasil ROI saja, di luar bonus. Pengembalian modal mempertahankan perilaku terpisah: modal penuh kembali ke saldo web ketika batas ROI produk tercapai. Harga, bagian, dan batas disimpan saat pembelian; perubahan katalog tidak mengubah produk yang sudah dibeli.

## Bonus

- Bonus akun baru: Rp5.000, diklaim melalui tombol pada dashboard atau halaman referral, sekali per akun. Akun yang sudah ada sebelum reset tidak otomatis mendapat bonus daftar baru.
- Undangan: Rp5.000 kepada pengundang untuk setiap akun baru yang mendaftar lewat kodenya, sekali per akun yang diundang.
- Cashback: Rp10.000 sekali per akun setelah total deposit manual terverifikasi minimal Rp100.000 dan pembelian produk di workspace aktif. Deposit saja atau pembelian saja tidak cukup. Pengujian dan seeder tidak mengkreditkan saldo awal.
- Setiap aktivasi produk membayar pengundang langsung Rp25.000. Lima upline berikutnya mendapat level 1?5, masing-masing Rp5.000. Nominal tetap tersebut berlaku untuk kedua harga produk. Tidak ada komisi level 6. Cabang referral langsung tidak dibatasi.
- Komisi berasal dari aktivasi, bukan deposit. Penerima nonaktif atau kohor tidak menerima bonus; tingkatan tetap ditelusuri tanpa memindahkan bonusnya ke penerima lain.
- Kunci unik per akun, undangan, dan investasi mencegah bonus ganda saat retry. Bonus masuk wallet melalui jurnal seimbang dan dicatat di `member_bonuses`.
- Minimal WD Rp30.000, admin 10%. Pengajuan Rp30.000 menghasilkan transfer bersih Rp27.000. WD tetap melalui persetujuan admin dan mensyaratkan pembelian produk.

## Penerapan dan reset

Jalankan dari folder proyek:

```bash
php artisan migrate --force
php artisan copp:activate-plan --reset-members
php artisan optimize:clear
```

Perintah aktivasi membuat backup SQL penuh sebelum reset. Akun, password, dan jaringan referral dipertahankan. Seluruh workspace lokal lama diarsipkan dan workspace baru dimulai tanpa saldo atau produk, sehingga riwayat lama tetap dapat diperiksa admin. Limit pembelian plan baru dihitung per harga produk dalam workspace aktif. Pengulangan perintah setelah plan aktif tidak mereset ulang saldo.

`migrate:fresh --seed` tetap merupakan reset database penuh: seluruh akun dan riwayat dihapus, kemudian dibuat admin, dua produk, workspace baru, dan satu akun member testing. Tidak diperlukan untuk menerapkan perubahan ini pada database yang sudah berisi akun.

Seeder katalog terpisah `ProductPlanSeeder` tidak mengubah password admin. Seeder utama menjalankan `CoppSeeder` (katalog + akun admin), `LocalWorkspaceSeeder`, dan `TestMemberSeeder`. Produk lain diarsipkan. Member hanya melihat katalog aktif.

Admin: username `didisaidi`, email `admin@copp.production`, password awal `admin123`. Member testing: username `membertest`, email `tester@copp.test`, password `MemberTest123`. Seeder akun menetapkan ulang password akun terkait; aktivasi plan tidak menjalankan seeder akun.

Perilaku ROI tetap dan bonus lama dipertahankan untuk pengujian serta pembacaan workspace berlabel `legacy`; workspace lama yang diarsipkan tidak melanjutkan akrual atau klaim.

Verifikasi: `php vendor/phpunit/phpunit/phpunit`, `php artisan nexavest:verify-ledger`, `php artisan view:cache`, dan `npm.cmd run build`. Tes menggunakan database `nexavest_claims_test` yang terpisah dari database aplikasi.
