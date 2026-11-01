@extends('layouts.auth') @section('title','Masuk')
@section('content')
<span class="eyebrow">SELAMAT DATANG KEMBALI</span><h2>Masuk ke akun</h2><p class="subtitle">Gunakan email dan kata sandi untuk masuk ke COPP Luxury.</p>
<form method="post" action="/login" class="stack">
    @csrf
    <x-field name="email" label="Alamat email" type="email" autocomplete="username" required placeholder="nama@contoh.com"/>
    <x-field name="password" label="Kata sandi" type="password" autocomplete="current-password" required/>
    <div class="form-between"><label class="checkbox-label"><input type="checkbox" name="remember" value="1"> Ingat saya</label><a href="/forgot-password" class="text-link">Lupa kata sandi?</a></div>
    <button class="btn btn-primary btn-full">Masuk ke COPP Luxury <x-icon name="arrow"/></button>
</form>
<p class="auth-switch">Belum memiliki akun? <a href="/register">Daftar akun</a></p>
@endsection
