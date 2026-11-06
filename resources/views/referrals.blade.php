@extends('layouts.app')
@section('title','Referral & misi COPP')
@section('content')
<div class="page-heading"><div><p class="eyebrow">COPP LUXURY · JARINGAN MATAHARI</p><h1>Referral & misi</h1><p>Referral langsung tanpa batas cabang. Bonus jaringan sampai 15 level.</p></div></div>
<img class="copp-banner" src="/images/copp-banner.jpg" alt="Misi undang akun COPP: undang 3 akun mendapat Rp5.000, undang 15 akun mendapat Rp15.000, masing-masing sekali per akun" width="1280" height="853">
<p>Bonus undang akun hanya bisa diklaim 1x untuk setiap target per akun.</p>
<div class="mini-stats"><div><span><small>Referral {{ $staff ? 'platform' : 'langsung' }}</small><strong>{{ $total }}</strong></span></div><div><span><small>Total bonus Anda</small><strong>{{ \App\Support\Money::rupiah($bonusTotal) }}</strong></span></div><div><span><small>Kode referral Anda</small><strong>{{ auth()->user()->referral_code }}</strong></span></div></div>
@can('transact')
<section class="panel padded-panel"><h2>Link referral Anda</h2><div class="copy-field"><input readonly id="referral-link" value="{{ url('/register?ref='.auth()->user()->referral_code) }}" aria-label="Link referral"><button class="btn btn-secondary" data-copy="referral-link">Salin link</button></div></section>
@endcan
<section class="panel padded-panel"><h2>Misi member</h2><p>Bonus masuk otomatis saat target tercapai dari referral langsung. Semua misi undang akun hanya bisa diklaim 1x per target per akun. Total undangan diakumulasi dan tidak direset setiap hari. Misi top up tetap harian (WIB), dihitung setelah disetujui admin; setiap akun dihitung sekali per hari. Semua target bersifat kumulatif.</p><div class="package-grid copp-mission-grid">
@foreach(config('copp.missions') as $code => $mission)
<article class="package-card"><span class="tag">{{ $mission['daily'] ? 'Harian' : 'Sekali saja' }}</span><h3>{{ $mission['label'] }}</h3><strong>{{ \App\Support\Money::rupiah($mission['reward']) }}</strong><p>{{ number_format(min($progress[$mission['metric']], $mission['target']),0,',','.') }} / {{ number_format($mission['target'],0,',','.') }} {{ $mission['metric'] === 'turnover' ? 'rupiah' : 'akun' }}</p></article>
@endforeach
</div></section>
<section class="panel padded-panel"><h2>Bonus referral dari top up terverifikasi</h2><div class="table-wrap"><table><thead><tr><th>Level</th><th>Bonus</th></tr></thead><tbody>@foreach([['1','15%'],['2','3%'],['3?5','2%'],['6?15','1%']] as [$level,$rate])<tr><td>{{ $level }}</td><td>{{ $rate }}</td></tr>@endforeach</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><h2>Riwayat bonus terbaru</h2></div><div class="table-wrap"><table><thead><tr><th>Tanggal</th><th>Bonus</th><th>Nominal</th></tr></thead><tbody>@forelse($bonuses as $bonus)<tr><td>{{ $bonus->created_at->format('d M Y H:i') }}</td><td>{{ $bonus->description }}</td><td>{{ \App\Support\Money::rupiah($bonus->amount) }}</td></tr>@empty<tr><td colspan="3">Belum ada bonus.</td></tr>@endforelse</tbody></table></div></section>
<section class="panel"><div class="panel-heading"><h2>Jaringan referral langsung</h2></div><div class="table-wrap"><table><thead><tr><th>Pereferensi</th><th>Member</th><th>Tanggal</th></tr></thead><tbody>@forelse($referrals as $f)<tr><td>{{ $f->referrer->name }}</td><td>{{ $f->referred->name }}<small>{{ $f->referred->member_code }}</small></td><td>{{ $f->created_at->format('d M Y') }}</td></tr>@empty<tr><td colspan="3"><x-empty title="Belum ada referral" message="Akun yang mendaftar melalui kode Anda akan muncul di sini."/></td></tr>@endforelse</tbody></table></div><div class="pagination">{{ $referrals->links() }}</div></section>
@endsection
