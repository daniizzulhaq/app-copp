@extends('layouts.auth')
@section('title','Buat password baru')
@section('content')
<span class="eyebrow">PEMULIHAN AKUN</span><h2>Buat password baru</h2>
<p class="subtitle">Atur password baru untuk {{ $accountEmail }}.</p>
<form method="post" action="/reset-password/new" class="stack">
    @csrf
    <x-field name="password" label="Password baru" type="password" required autocomplete="new-password" hint="Minimal 10 karakter, huruf besar, huruf kecil, dan angka."/>
    <x-field name="password_confirmation" label="Ulangi password baru" type="password" required autocomplete="new-password"/>
    <button class="btn btn-primary btn-full">Simpan password baru</button>
</form>
<p class="auth-switch"><a href="/forgot-password">Gunakan email lain</a></p>
@endsection
