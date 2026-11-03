@extends('layouts.auth') @section('title','Daftar akun')
@section('content')
<span class="eyebrow">MULAI SEKARANG</span><h2>Buat akun</h2><p class="subtitle">Isi data dan kata sandi. Akun langsung bisa digunakan setelah mendaftar.</p>
<form method="post" action="/register" class="stack">
    @csrf
    <x-field name="name" label="Nama lengkap" required autocomplete="name"/>
    <x-field name="username" label="Username (opsional)" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="30" hint="3–30 karakter: huruf, angka, atau garis bawah. Bisa digunakan untuk login."/>
    <x-field name="email" label="Alamat email" type="email" required autocomplete="email"/>
    <x-field name="password" label="Kata sandi" type="password" required autocomplete="new-password" hint="Minimal 10 karakter, huruf besar, huruf kecil, dan angka."/>
    <x-field name="password_confirmation" label="Ulangi kata sandi" type="password" required autocomplete="new-password"/>
    <x-field name="referral" label="Kode referral (opsional)" :value="request('ref')" maxlength="12"/>
    <button class="btn btn-primary btn-full">Buat akun <x-icon name="arrow"/></button>
</form>
<p class="auth-switch">Sudah memiliki akun? <a href="/login">Masuk</a></p>
@endsection
