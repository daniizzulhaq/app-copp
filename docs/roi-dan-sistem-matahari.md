> Catatan lama sebelum master plan COPP, 1 November 2026. Ketentuan katalog dan bonus di dokumen ini telah digantikan oleh [master plan COPP](copp-master-plan.md).

# ROI dan sistem Matahari

## Ketentuan yang diminta

| Komponen | Ketentuan |
| --- | --- |
| Dana ROI | 40% omset baru |
| Dana pemasaran | 30% omset |
| Check-in harian | 5% omset harian, menjadi ROI dan hangus jika tidak diklaim |
| Katalog | 10 produk, masing-masing memiliki batas maksimal ROI harian |
| WD | Minimal Rp50.000, admin 10% |
| Pemasaran | Bonus referral, bonus level hingga 15 level, bonus omset referral mingguan |
| Pembayaran ROI harian | Member mengklaim sendiri; tidak dikirim otomatis oleh scheduler/API |

## Sudah diterapkan

- Pada workspace operasional (`mode=local`), scheduler menyiapkan hak klaim, bukan mengkreditkan ROI ke wallet.
- Tombol klaim tersedia di beranda member, portofolio, dan detail portofolio. Klaim hanya untuk pemilik portofolio yang aktif dan terverifikasi.
- ROI dapat diklaim pada tanggal jatuh tempo sampai sebelum pukul 00.00 WIB hari berikutnya. Hari pertama adalah sehari setelah aktivasi. Tidak ada akumulasi klaim hari sebelumnya.
- Pemrosesan yang tertinggal membuat pencatatan hari yang terlewat dan langsung menghanguskannya. Pemeriksaan tanggal juga berlaku saat member menekan tombol, sehingga tidak bergantung pada scheduler yang tepat waktu.
- Klaim menggunakan transaksi database dan penguncian workspace. Permintaan berulang tidak menggandakan saldo. Jika kas tidak cukup, klaim ditolak tanpa pembayaran parsial; member dapat mencoba lagi pada hari yang sama.
- Nilai ROI yang hangus tetap tersimpan dalam `expired_amount`, dengan jurnal pelepasan kewajiban. Nilai ini bukan pembayaran ke wallet dan tidak dihitung sebagai sisa kewajiban.
- Batas ROI harian dalam rupiah (`daily_roi_cap`) dapat diatur per produk, dihitung per portofolio dan disimpan saat aktivasi. Perubahan produk berlaku pada aktivasi berikutnya. Nilai kosong mempertahankan perhitungan persentase sebelumnya.
- WD baru minimal Rp50.000. Admin 10% dibulatkan ke bawah ke rupiah penuh, dipotong dari nominal pengajuan. Contoh: pengajuan Rp50.000 menahan Rp50.000, admin Rp5.000, transfer Rp45.000. Penolakan mengembalikan nominal penuh. Pengajuan lama mempertahankan biaya yang berlaku saat diajukan.
- Pengembalian modal saat jatuh tempo tetap mengikuti alur yang sudah ada. Skenario eksperimen (`mode=simulation`) tetap memakai distribusi simulasi.

## Data yang masih diperlukan sebelum model omset diaktifkan

Pembagian dana 40%/30%/5%, bonus check-in, bonus referral, bonus 15 level, dan bonus omset mingguan **belum diterapkan sebagai pembayaran**. Belum ada tarif atau nominal yang dibuat secara asumsi.

1. Nama, harga/minimum aktivasi, durasi, dan batas ROI per hari untuk masing-masing dari 10 produk.
2. Definisi omset: deposit terverifikasi atau aktivasi produk? Apakah pembelian ulang dihitung kembali?
3. Rumus pembagian pool ROI 40% dan check-in 5%: rata per member, proporsional nilai produk, atau bobot produk. Apakah batas berlaku per portofolio, per produk yang dimiliki member, atau per member per hari?
4. Omset harian yang menjadi dasar klaim: omset hari berjalan atau hari sebelumnya. Jika hari berjalan, perlu aturan agar nominal tidak bergantung pada jam member mengklaim.
5. Apakah check-in 5% terpisah dari alokasi 40%/30%, dan apakah ikut batas ROI produk.
6. Tarif referral langsung, tarif masing-masing level 1–15, syarat penerima, serta hubungan total bonus dengan anggaran pemasaran 30%.
7. Persentase/tier bonus omset mingguan, downline yang dihitung, syarat penerima, awal/akhir minggu, dan hari pembayarannya.
8. Penggunaan sisa alokasi dan dana klaim yang hangus.

Sebelum ketentuan tersebut ditetapkan, ROI operasional menggunakan persentase produk yang sudah ada, dibatasi `daily_roi_cap` jika diisi. Angka 40%/30%/5% belum menjadi batas dana pada ledger.

## Verifikasi

Jalankan `php vendor/bin/phpunit`, `php artisan nexavest:verify-ledger`, dan `npm.cmd run build` dari direktori proyek. Pengujian menggunakan database terpisah `nexavest_claims_test` pada server MySQL yang sama, dengan rollback transaksi setiap pengujian; tidak menghapus database workspace.
