@extends('layouts.app')
@section('title',$package->exists ? 'Edit produk investasi':'Buat produk investasi')
@section('content')
<div class="page-heading"><div><h1>{{ $package->exists ? 'Edit produk investasi':'Buat produk investasi' }}</h1><p>Perubahan berlaku untuk pembelian berikutnya.</p></div><a href="/packages" class="btn btn-secondary">Kembali</a></div>
<section class="panel form-panel narrow"><form method="post" action="{{ $package->exists ? '/packages/'.$package->id:'/packages' }}" class="stack">
@csrf @if($package->exists) @method('put') @endif
<input type="hidden" name="roi_method" value="{{ $package->roi_method ?? 'turnover' }}">
<x-field name="name" label="Nama produk investasi" :value="$package->name" required maxlength="100"/>
<div class="form-grid">
<x-field name="minimum_amount" label="Harga produk (Rp)" type="number" :value="$package->minimum_amount" min="1" max="1000000000" required/>
<x-field name="purchase_limit" label="Batas pembelian per akun" type="number" :value="$package->purchase_limit ?? 10" min="1" max="10" required/>
@if($package->roi_method === 'turnover')
<p>Rp100.000 = 1 bagian; Rp1.000.000 = 10 bagian. Pool ROI 60% omzet. Batas ROI 210%, modal kembali terpisah.</p>
@else
<x-field name="daily_rate_bps" label="ROI harian (basis points)" type="number" :value="$package->daily_rate_bps" min="1" max="10000" required/>
<x-field name="duration_days" label="Durasi (hari)" type="number" :value="$package->duration_days" min="1" max="365" required/>
<x-field name="total_roi_bps" label="Max Total ROI (basis points)" type="number" :value="$package->total_roi_bps"/>
<x-field name="daily_roi_cap" label="Maksimal ROI per hari (Rp)" type="number" :value="$package->daily_roi_cap"/>
<input type="hidden" name="fixed_amount" value="{{ $package->fixed_amount ? 1 : 0 }}">
@endif
<label class="field"><span>Status</span><select name="active"><option value="1" @selected(old('active',$package->active)==1)>Aktif</option><option value="0" @selected(old('active',$package->active)==0)>Diarsipkan</option></select></label>
</div>
<label class="field"><span>Deskripsi</span><textarea name="description" rows="3" maxlength="1000">{{ old('description',$package->description) }}</textarea></label><button class="btn btn-primary">Simpan produk investasi</button>
</form></section>
@endsection
