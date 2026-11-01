@if(isset($claimScenario) && $claimScenario->isLocal() && !auth()->user()->isStaff())
<section class="member-section member-form-card" aria-labelledby="daily-roi-heading">
    <div class="member-section-heading">
        <div><h2 id="daily-roi-heading">Klaim ROI harian</h2><p>{{ today()->format('d M Y') }} &middot; Batas klaim pukul 23.59 WIB</p></div>
    </div>
    <p class="notice">Klaim sendiri setiap hari. ROI yang tidak diklaim sebelum pukul 00.00 WIB hari berikutnya hangus dan tidak diakumulasi.</p>
    @forelse($roiClaims as $claim)
        <article class="member-history-card">
            <div class="member-history-top"><div><strong>{{ $claim->investment->package_name }}</strong><small>{{ $claim->investment->number }}</small></div></div>
            <div class="member-history-bottom"><strong>{{ \App\Support\Money::rupiah($claim->amount) }}</strong><x-status :value="$claim->status"/></div>
            @if($claim->remaining() > 0 && $claimScenario->status !== 'archived')
                <form method="post" action="{{ route('roi.claim', $claim) }}" class="stack">
                    @csrf
                    <button class="btn btn-primary btn-full">Klaim ROI hari ini</button>
                </form>
            @elseif($claim->claimed_at)
                <p>Sudah diklaim pukul {{ $claim->claimed_at->format('H:i') }} WIB dan masuk ke saldo wallet.</p>
            @endif
        </article>
    @empty
        <p class="muted">Belum ada ROI jatuh tempo hari ini. ROI mulai tersedia sehari setelah aktivasi produk.</p>
    @endforelse
</section>
@endif
