@extends('layouts.app')
@section('title','Produk Investasi')
@section('content')
<div class="page-heading"><div><p class="eyebrow">PRODUK INVESTASI</p><h1>Produk Investasi<span class="heading-dot">.</span></h1><p>Satu paket Rp100.000, satu bagian ROI. Maksimal 10 pembelian per akun.</p></div></div>
@can('manage')<p><a href="/packages" class="text-link">Produk aktif</a> · <a href="/packages?status=archived" class="text-link">Lihat arsip produk</a></p>@endcan
<div class="package-grid">
@forelse($packages as $p)
<article class="package-card">
<div class="package-top"><span class="kpi-icon green"><x-icon name="box"/></span><x-status :value="$p->active ? 'active':'archived'"/></div>
<h2>{{ $p->name }}</h2><p>{{ $p->description }}</p>
@if($p->roi_method === 'turnover')
<div class="package-rate">{{ $p->roi_shares }} <span>bagian ROI</span></div><p>Pool ROI 25% omzet pembelian dibagi menurut jumlah produk aktif. Penerimaan maksimal Rp210.000 per paket, tanpa pengembalian modal tambahan. Omzet nol, ROI nol.</p>
@else
<div class="package-rate">{{ $p->daily_rate_bps/100 }}<span>% per hari</span></div>
@endif
<dl><dt>Harga produk</dt><dd>{{ \App\Support\Money::rupiah($p->minimum_amount) }}</dd><dt>Maksimal total ROI</dt><dd>{{ \App\Support\Money::rupiah(intdiv($p->minimum_amount * ($p->total_roi_bps ?? 0), 10000)) }}</dd><dt>Batas pembelian per akun</dt><dd>{{ $p->purchase_limit }} kali</dd>
@if($p->roi_method === 'turnover')<dt>Periode</dt><dd>Sampai batas ROI tercapai</dd>@endif</dl>
@can('transact') @if($p->active)<a href="/investments?package={{ $p->id }}" class="btn btn-primary btn-full">Pilih produk investasi <x-icon name="arrow"/></a>@endif @endcan
@can('manage')<a href="/packages/{{ $p->id }}/edit" class="btn btn-secondary">Edit produk investasi</a>@endcan
</article>
@empty<x-empty title="Produk Investasi belum tersedia"/>@endforelse
</div><div class="pagination">{{ $packages->links() }}</div>
@endsection
