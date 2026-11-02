@extends('layouts.app')
@section('title','Artikel landing page')
@section('content')
<div class="page-heading"><div><p class="eyebrow">KONTEN HALAMAN AWAL</p><h1>Edit artikel landing page</h1><p>Ubah judul, isi artikel, dan FAQ. Perubahan langsung tampil setelah disimpan.</p></div><a href="{{ route('home') }}" target="_blank" rel="noopener" class="btn btn-secondary">Lihat landing page <x-icon name="arrow"/></a></div>
<form method="post" action="{{ route('settings.landing-content') }}" class="stack">
    @csrf @method('put')
    @foreach($content as $section => $fields)
    <details class="panel padded-panel" @if($loop->first || collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'content.'.$section.'.'))) open @endif>
        <summary><strong>{{ \Illuminate\Support\Str::headline($section) }}</strong></summary>
        <div class="stack padded-panel">
            @foreach($fields as $key => $value)
            <label class="field"><span>{{ \Illuminate\Support\Str::headline($key) }}</span><textarea name="content[{{ $section }}][{{ $key }}]" rows="{{ str_contains($key, 'judul') ? 2 : 3 }}" maxlength="{{ \App\Http\Controllers\LandingContentController::maxLength($key) }}" required>{{ old('content.'.$section.'.'.$key, $value) }}</textarea>@error('content.'.$section.'.'.$key)<small class="field-error">{{ $message }}</small>@enderror</label>
            @endforeach
        </div>
    </details>
    @endforeach
    <div class="notice">Gunakan teks biasa. Baris baru pada judul dan isi akan ditampilkan di landing page. Angka pada tabel master plan mengikuti konfigurasi produk.</div>
    <div><button class="btn btn-primary">Simpan artikel landing page</button></div>
</form>
@endsection
