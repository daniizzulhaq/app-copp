<?php
return [
    'required'=>':attribute wajib diisi.','email'=>':attribute harus berupa email yang valid.','unique'=>':attribute sudah digunakan.','confirmed'=>'Konfirmasi :attribute tidak sesuai.',
    'integer'=>':attribute harus berupa bilangan bulat.','numeric'=>':attribute harus berupa angka.','date'=>':attribute harus berupa tanggal yang valid.','date_format'=>'Format :attribute harus :format.',
    'after_or_equal'=>':attribute harus setelah atau sama dengan :date.','in'=>':attribute yang dipilih tidak valid.','exists'=>':attribute tidak ditemukan.',
    'boolean'=>':attribute tidak valid.','uuid'=>':attribute harus berupa UUID yang valid.','accepted'=>'Persetujuan :attribute wajib diterima.',
    'current_password'=>'Kata sandi saat ini tidak sesuai.','digits'=>':attribute harus berisi :digits digit.','string'=>':attribute harus berupa teks.',
    'min'=>['numeric'=>':attribute minimal :min.','string'=>':attribute minimal :min karakter.'],'max'=>['numeric'=>':attribute maksimal :max.','string'=>':attribute maksimal :max karakter.'],
    'password'=>['mixed'=>':attribute harus berisi huruf besar dan huruf kecil.','numbers'=>':attribute harus berisi angka.','letters'=>':attribute harus berisi huruf.'],
    'attributes'=>['bank_name'=>'Nama bank','bank_account_number'=>'Nomor rekening','bank_account_holder'=>'Nama pemilik rekening','name'=>'Nama','email'=>'Email','password'=>'Kata sandi','amount'=>'Nominal','code'=>'Kode autentikator','simulation_consent'=>'ketentuan penggunaan','from'=>'Tanggal awal','to'=>'Tanggal akhir','referral'=>'Kode referral'],
];
