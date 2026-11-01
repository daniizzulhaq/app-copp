@extends('layouts.auth') @section('title','Lupa kata sandi')
@section('content')
<span class="eyebrow">PEMULIHAN AKUN</span><h2>Lupa kata sandi?</h2>
<p class="subtitle">{{ $temporaryReset ? 'Masukkan email yang terdaftar, lalu buat password baru.' : 'Masukkan email akun Anda untuk menerima tautan reset.' }}</p>
<form method="post" action="/forgot-password" class="stack">
    @csrf
    <x-field name="email" label="Alamat email" type="email" autocomplete="username" required/>
    <button class="btn btn-primary btn-full">{{ $temporaryReset ? 'Lanjut buat password baru' : 'Kirim tautan reset' }}</button>
</form>
<p class="auth-switch"><a href="/login">← Kembali ke halaman masuk</a></p>
@endsection
