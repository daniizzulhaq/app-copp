@if(isset($claimScenario) && $claimScenario->isLocal() && !auth()->user()->isStaff())
<section id="daily-roi" class="member-section member-form-card" aria-labelledby="daily-roi-heading" data-daily-roi-date="{{ today()->toDateString() }}" data-daily-roi-timezone="{{ config('app.timezone') }}" data-daily-roi-now="{{ now()->getTimestampMs() }}">
    <div class="member-section-heading">
        <div><h2 id="daily-roi-heading">Klaim ROI harian</h2><p>{{ today()->format('d M Y') }} &middot; Batas klaim pukul 23.59 WIB</p></div>
        <a href="{{ request()->fullUrl() }}#daily-roi" class="btn btn-secondary btn-sm">Perbarui klaim</a>
    </div>
    <p class="notice">Klaim sendiri setiap hari. ROI yang tidak diklaim sebelum pukul 00.00 WIB hari berikutnya hangus dan tidak diakumulasi.</p>
    @forelse($roiClaims as $claim)
        <article class="member-history-card">
            <div class="member-history-top"><div><strong>{{ $claim->investment->package_name }}</strong><small>{{ $claim->investment->number }}</small></div></div>
            <div class="member-history-bottom"><strong>{{ \App\Support\Money::rupiah($claim->remaining()) }}</strong><x-status :value="$claim->status"/></div>
            @if($claim->remaining() > 0 && $claimScenario->status !== 'archived')
                <form method="post" action="{{ route('roi.claim', $claim) }}" class="stack">
                    @csrf
                    <button class="btn btn-primary btn-full">Klaim ROI hari ini</button>
                </form>
            @elseif($claim->claimed_at)
                <p>Sudah diklaim pukul {{ $claim->claimed_at->format('H:i') }} WIB dan masuk ke saldo wallet.</p>
                <button type="button" class="btn btn-primary btn-full" disabled>Sudah diklaim hari ini</button>
            @endif
        </article>
    @empty
        @if($claimScenario->status === 'archived')
            <p class="muted">Workspace ini sudah diarsipkan. Klaim ROI tidak tersedia.</p>
        @endif
        <p class="muted">Belum ada ROI untuk diklaim.</p>
        <button type="button" class="btn btn-primary btn-full" disabled>Klaim ROI hari ini</button>
    @endforelse
</section>
@endif
