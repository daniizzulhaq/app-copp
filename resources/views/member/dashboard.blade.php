@extends('layouts.app')
@section('title','Beranda')
@section('content')
<section class="member-greeting"><div><p>Selamat datang kembali,</p><h1>{{ explode(' ',auth()->user()->name)[0] }} <span class="member-greeting-spark" aria-hidden="true">✦</span></h1></div><span class="member-tier"><x-icon name="shield"/>Member</span></section>

<a href="/referrals"><img class="copp-banner" src="/images/copp-banner.jpg" alt="Misi harian COPP" width="1280" height="853"></a>
<section class="member-balance-card" x-data="{ visible: true }" aria-label="Saldo wallet">
    <div class="member-balance-top"><span>Saldo tersedia </span><button type="button" class="member-balance-visibility" @click="visible=!visible" :aria-label="visible ? 'Sembunyikan saldo':'Tampilkan saldo'" :aria-pressed="!visible"><span x-show="visible"><x-icon name="eye"/></span><span x-cloak x-show="!visible"><x-icon name="eye-off"/></span></button></div>
    <strong class="member-balance-amount" x-show="visible">{{ \App\Support\Money::rupiah($wallet->balance()) }}</strong><strong class="member-balance-amount" x-cloak x-show="!visible">Rp ••••••••</strong>
    <div class="member-balance-caption"><span class="member-balance-indicator"></span>Saldo siap digunakan</div>
    <div class="member-balance-bottom"><div><small>Modal dialokasikan</small><b x-show="visible">{{ \App\Support\Money::rupiah($metrics['principal']) }}</b><b x-cloak x-show="!visible">••••••</b></div><div><small>ROI dibayar</small><b x-show="visible">{{ \App\Support\Money::rupiah($metrics['paid']) }}</b><b x-cloak x-show="!visible">••••••</b></div></div>
    <svg class="member-card-art" viewBox="0 0 200 220" fill="none" aria-hidden="true"><path d="m25 220 80-82 45 18 70-80M-10 180l80-82 45 18 70-80M35 300l80-82 45 18 70-80" stroke="currentColor" stroke-width="26"/></svg>
</section>

<nav class="member-quick-actions" aria-label="Akses cepat transaksi">
    @foreach([['plus','Deposit','/payments','mint'],['up','Tarik saldo','/withdrawals','blue'],['chart','Portofolio','/investments','violet'],['network','Referral','/referrals','amber']] as [$icon,$label,$url,$color])<a href="{{ $url }}"><span class="{{ $color }}"><x-icon :name="$icon"/></span><b>{{ $label }}</b></a>@endforeach
</nav>

<form class="member-scenario-picker" method="get"><span class="member-list-icon"><x-icon name="box"/></span><label for="member-scenario"><small>Workspace Anda</small><select name="scenario" id="member-scenario" onchange="this.form.submit()">@foreach($scenarioList as $option)<option value="{{ $option->id }}" @selected($s->id === $option->id)>{{ \App\Support\UiText::local($option->name) }}</option>@endforeach</select></label><span class="member-scenario-day">Hari {{ $s->current_day }}</span><noscript><button class="btn btn-primary btn-sm">Pilih</button></noscript></form>

@include('member.roi-claims')

@if($metrics['outstanding'] > 0)
    <a class="member-risk-note" href="/investments"><x-icon name="shield"/><div><strong>Ada ROI atau modal belum diselesaikan</strong><p>{{ \App\Support\Money::rupiah($metrics['outstanding']) }} belum dibayar. Periksa portofolio Anda.</p></div><x-icon name="arrow"/></a>
@endif

<a class="member-explore-banner" href="/packages"><div><span>KENALI SETIAP PARAMETER</span><h2>Mulai dari memahami<br>cara kerja ROI.</h2><p>Jelajahi produk investasi <x-icon name="arrow"/></p></div><span class="member-explore-symbol"><x-icon name="chart"/></span></a>

<section class="member-section"><div class="member-section-heading"><h2>Portofolio saya <span>{{ $metrics['investments'] }}</span></h2><a href="/investments">Lihat semua <x-icon name="arrow"/></a></div>
    @forelse($memberInvestments as $investment)
        @php($elapsed = max(0,min($investment->duration_days,(int)$investment->starts_at->diffInDays(app(\App\Services\WorkspaceClock::class)->date($s),false))))
        <a class="member-investment-preview" href="/investments/{{ $investment->id }}"><div class="member-preview-top"><span class="member-list-icon mint"><x-icon name="box"/></span><div><strong>{{ $investment->package_name }}</strong><small>{{ $investment->daily_rate_bps/100 }}% / hari · {{ $investment->duration_days }} hari</small></div><x-status :value="$investment->status"/></div><div class="member-investment-liability"><span>Modal kembali <b>{{ \App\Support\Money::rupiah($investment->principal_returned_amount) }}</b></span><span>Total modal + ROI <b>{{ \App\Support\Money::rupiah($investment->principal + $investment->promised()) }}</b></span></div><div class="member-preview-values"><span>modal<b>{{ \App\Support\Money::rupiah($investment->principal) }}</b></span><span>ROI dibayar<b>{{ \App\Support\Money::rupiah($investment->obligations_sum_paid_amount) }}</b></span></div><progress value="{{ $elapsed }}" max="{{ $investment->duration_days }}" aria-label="{{ $investment->package_name }}, hari {{ $elapsed }} dari {{ $investment->duration_days }}"></progress><div class="member-progress-caption"><span>Periode</span><b>{{ $elapsed }} / {{ $investment->duration_days }} hari</b></div></a>
    @empty
        <div class="member-empty-card"><span class="member-list-icon mint"><x-icon name="chart"/></span><h3>Ruang untuk eksplorasi Anda</h3><p>Pilih produk investasi menggunakan saldo untuk memulai portofolio.</p><a href="/packages" class="btn btn-primary">Jelajahi produk investasi <x-icon name="arrow"/></a></div>
    @endforelse
</section>

<section class="member-section"><div class="member-section-heading"><h2>Pilihan produk investasi</h2><a href="/packages">Semua produk investasi <x-icon name="arrow"/></a></div><div class="member-featured-plans">
    @forelse($featuredPackages as $package)<a href="/investments?package={{ $package->id }}" class="member-featured-plan"><span class="member-plan-icon {{ $loop->even ? 'violet':'mint' }}"><x-icon name="box"/></span><h3>{{ $package->name }}</h3><strong>{{ number_format($package->daily_rate_bps/100,1,',','.') }}<small>% / hari</small></strong><p>Parameter ROI internal</p><div><small>Mulai dari</small><b>{{ \App\Support\Money::rupiah($package->minimum_amount) }}</b><span>{{ $package->duration_days }} hari <x-icon name="arrow"/></span></div></a>@empty<x-empty title="Belum ada produk investasi aktif"/>@endforelse
</div><p class="member-fine-print">Angka ROI merupakan parameter eksperimen, bukan jaminan keuntungan.</p></section>

<section class="member-section"><div class="member-section-heading"><h2>Aktivitas terbaru</h2><a href="/wallet">Lihat semua <x-icon name="arrow"/></a></div><x-member.transactions :transactions="$recent"/></section>

<section class="member-section member-chart-section"><div class="member-section-heading"><div><h2>Perjalanan saldo</h2><p>{{ \Carbon\Carbon::parse($from)->format('d M') }} – {{ \Carbon\Carbon::parse($to)->format('d M Y') }}</p></div><span class="tag">Dari wallet</span></div><form method="get" class="member-chart-range"><input type="hidden" name="scenario" value="{{ $s->id }}"><label for="member-chart-range">Periode grafik</label><select name="range" id="member-chart-range" onchange="this.form.submit()">@foreach(['week'=>'7 hari','month'=>'30 hari','all'=>'Semua periode'] as $value=>$label)<option value="{{ $value }}" @selected($range===$value)>{{ $label }}</option>@endforeach</select><noscript><button class="btn btn-secondary btn-sm">Terapkan</button></noscript></form><div class="chart-container"><canvas data-chart="member-balance" role="img" aria-label="Perkembangan saldo wallet"></canvas></div></section>
<script type="application/json" id="member-dashboard-data">{!! json_encode($chart,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@endsection
