# Reset password sementara tanpa SMTP

- Buka Lupa sandi, masukkan email akun aktif yang terdaftar, lalu buat password baru. Tidak ada pengiriman email atau pemeriksaan akses inbox.
- Alur yang sama berlaku di lokal, staging, dan production, termasuk akses dari jaringan luar dan domain publik. Tidak ada pembatasan environment, host, atau IP.
- `TEMPORARY_PASSWORD_RESET_ENABLED=true` menjadi nilai default konfigurasi dan `.env.example`. Jika server pernah memakai nilai `false`, ubah menjadi `true` lalu jalankan `php artisan config:clear` dan `php artisan route:clear` setelah memperbarui kode.
- Form reset berada di `/reset-password/new`. Jika flag dimatikan secara eksplisit, Lupa sandi kembali menggunakan tautan email bertoken seperti biasa; endpoint reset langsung tidak tersedia.
- Setelah email ditemukan, izin reset disimpan di sesi browser selama 15 menit dan terikat pada akun, alamat email, serta password saat itu. Form tidak bisa mengganti akun sasaran lewat input atau query string. Izin tidak bisa digunakan kembali setelah reset atau setelah password/email akun berubah.
- Reset mengubah hash password, merotasi remember token, mencabut sesi akun, menghapus token reset email yang masih berlaku, dan mencatat audit tanpa menyimpan password di log.
- Risiko: email saja tidak membuktikan kepemilikan akun. Siapa pun yang mengetahui alamat email akun aktif dapat mengganti passwordnya melalui alur ini, termasuk akun admin. Pembatasan percobaan, CSRF, dan sesi reset tidak mencegah pengambilalihan tersebut. Alur ini diaktifkan sesuai permintaan eksplisit pengguna untuk penggunaan sementara di server tanpa SMTP.
