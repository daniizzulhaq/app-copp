@extends('layouts.app')
@section('title','Pengaturan aplikasi')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">WORKSPACE SETTINGS</p><h1>Pengaturan aplikasi<span class="heading-dot">.</span></h1><p>Kelola kontak WhatsApp admin, rekening deposit, dan skenario default.</p></div>
    <div class="button-row"><a href="{{ route('settings.deposit-methods') }}" class="btn btn-secondary">Metode deposit QRIS</a><a href="{{ route('settings.landing-content') }}" class="btn btn-secondary">Edit artikel landing page</a></div>
</div>
<section class="panel padded-panel narrow">
    <form method="post" action="/settings" class="stack">
        @csrf @method('put')
        <h2>WhatsApp admin</h2>
        <x-field name="support_whatsapp_number" label="Nomor WhatsApp admin" type="tel" :value="$whatsappNumber" maxlength="40" autocomplete="tel" placeholder="Contoh: 081234567890" hint="Member yang menekan ikon WhatsApp akan diarahkan ke nomor ini. Bisa pakai 08 atau +62. Kosongkan untuk menyembunyikan ikon."/>
        <h2>Skenario default</h2>
        <label class="field"><span>Skenario untuk sesi baru</span><select name="default_scenario">@foreach($scenarios as $s)<option value="{{ $s->id }}" @selected(old('default_scenario', $default)==$s->id)>{{ \App\Support\UiText::local($s->name) }}</option>@endforeach</select></label>
        <h2>Rekening tujuan deposit</h2>
        <p class="muted">Rekening ini ditampilkan kepada member untuk transfer manual.</p>
        <x-field name="deposit_bank_name" label="Nama bank" :value="$bankAccount['bank']" maxlength="100" placeholder="Contoh: BCA"/>
        <x-field name="deposit_account_number" label="Nomor rekening" :value="$bankAccount['number']" maxlength="50" inputmode="numeric" placeholder="Nomor rekening tujuan"/>
        <x-field name="deposit_account_holder" label="Atas nama" :value="$bankAccount['holder']" maxlength="150" placeholder="Nama pemilik rekening"/>
        <button class="btn btn-primary">Simpan pengaturan</button>
    </form>
</section>
<section class="panel padded-panel">
    <h2>Konfigurasi operasional</h2>
    <dl class="definition-grid">
        <dt>Mode keuangan</dt><dd>Deposit transfer bank atau QRIS DANA / GoPay dengan verifikasi admin.</dd>
        <dt>Scheduler otomatis</dt><dd>{{ config('nexavest.scheduler_enabled') ? 'Aktif':'Nonaktif' }} — kendalikan melalui SIMULATION_SCHEDULER_ENABLED pada .env.</dd>
        <dt>Queue driver</dt><dd>{{ config('queue.default') }}</dd>
        <dt>Email driver</dt><dd>{{ config('mail.default') }} — konfigurasi SMTP diperlukan jika ingin mengirim email verifikasi ke inbox.</dd>
        <dt>Autentikasi webhook</dt><dd>HMAC SHA-256, timestamp maksimum ±5 menit. Kunci rahasia hanya disimpan dalam .env.</dd>
    </dl>
</section>
@endsection
