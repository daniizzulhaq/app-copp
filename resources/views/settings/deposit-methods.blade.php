@extends('layouts.app')
@section('title','Metode deposit')
@section('content')
<div class="page-heading"><div><p class="eyebrow">PENGATURAN PEMBAYARAN</p><h1>Metode deposit</h1><p>Atur QRIS DANA dan QRIS GoPay untuk pembayaran member.</p></div><a href="/settings" class="btn btn-secondary">Pengaturan rekening bank</a></div>
<form method="post" action="{{ route('settings.deposit-methods') }}" enctype="multipart/form-data" class="stack">
    @csrf @method('put')
    <div class="form-grid">
        @foreach($methods as $key => $method)
        <section class="panel padded-panel stack">
            <h2>{{ $method['label'] }}</h2>
            <label class="checkbox-label"><input type="checkbox" name="{{ $key }}_enabled" value="1" @checked(old($key.'_enabled', $errors->any() ? false : $method['enabled']))> Aktifkan {{ $method['label'] }}</label>
            <x-field :name="$key.'_holder'" label="Nama penerima / merchant" :value="$method['holder']" maxlength="150" placeholder="Nama yang muncul saat QRIS dipindai"/>
            <label class="field"><span>Gambar {{ $method['label'] }}</span><input type="file" name="{{ $key }}_image" accept="image/jpeg,image/png,image/webp"><small>JPG, PNG, atau WEBP, maksimal 2 MB. Unggah kode QRIS asli dari akun penerima.</small></label>
            @if($method['image'])<img class="deposit-qris-image" src="{{ route('settings.deposit-methods.preview', $key) }}?v={{ substr(hash('sha256', $method['image']), 0, 12) }}" alt="Kode {{ $method['label'] }} saat ini">@endif
            <p class="muted">Member memilih metode saat membuat deposit. Pembayaran tetap perlu dikonfirmasi dan diverifikasi admin.</p>
        </section>
        @endforeach
    </div>
    <div><button class="btn btn-primary">Simpan metode deposit</button></div>
</form>
@endsection
