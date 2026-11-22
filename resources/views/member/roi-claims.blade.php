@if(isset($claimScenario) && $claimScenario->isLocal() && !auth()->user()->isStaff())
<section id="daily-roi" class="member-section member-form-card" aria-labelledby="daily-roi-heading" data-daily-roi-date="{{ today()->toDateString() }}" data-daily-roi-timezone="{{ config('app.timezone') }}" data-daily-roi-now="{{ now()->getTimestampMs() }}">
    <div class="member-section-heading">
        <div><h2 id="daily-roi-heading">Klaim ROI harian</h2><p>{{ today()->format('d M Y') }} &middot; Batas klaim pukul 23.59 WIB</p></div>
        <a href="{{ request()->fullUrl() }}#daily-roi" class="btn btn-secondary btn-sm">Perbarui klaim</a>
    </div>
    @if($claimScenario->usesNextDayTurnoverRoi())
        <p>Profit per produk hari ini = (omzet pembelian produk kemarin × {{ config('copp.roi_pool_bps') / 100 }}%) ÷ jumlah produk aktif sampai akhir hari kemarin. Setiap produk aktif mendapat jatah yang sama, maksimal sisa batas ROI produk.</p>
        <p>Omzet hari ini untuk profit besok, mulai pukul 00.00 WIB. Contoh: omzet Rp1.000.000 × 25% = Rp250.000; dibagi 1.000 produk aktif = Rp250 per produk besok. Profit berubah setiap hari. Omzet ROI nol berarti profit nol.</p>
        <p class="muted">Sumber omzet: {{ today()->subDay()->format('d M Y') }}. Pembelian baru hari ini tidak mengubah hak klaim hari ini.</p>
    @elseif($claimScenario->usesTurnoverRoi())
        <p>Pool ROI = {{ config('copp.roi_pool_bps') / 100 }}% omzet produk hari ini. Dibagi menurut bagian produk yang aktif sejak pukul 00.00 WIB. Pembelian baru ikut mulai besok. Omzet nol berarti ROI nol; tambahan omzet setelah klaim bisa diklaim lagi hari ini.</p>
    @endif
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
        @elseif($claimScenario->usesNextDayTurnoverRoi())
            <p class="muted">Pembelian produk hari ini mulai dapat diklaim besok. Profit hari ini mengikuti 25% omzet kemarin dibagi jumlah produk aktif, maksimal sisa batas ROI produk. Tanpa omzet kemarin, profit hari ini Rp0.</p>
        @elseif($claimScenario->usesTurnoverRoi())
            <p class="muted">Belum ada hak ROI untuk akun Anda hari ini. Produk baru mulai mengikuti pembagian ROI keesokan hari. Hak ROI mengikuti omzet pembelian produk hari ini dan sisa batas ROI produk; tanpa omzet, ROI hari ini Rp0. Deposit saja tidak menambah pool ROI.</p>
        @else
            <p class="muted">Belum ada ROI jatuh tempo hari ini. ROI mulai tersedia sehari setelah aktivasi produk.</p>
        @endif
        <p class="muted">Belum ada ROI untuk diklaim.</p>
        <button type="button" class="btn btn-primary btn-full" disabled>Klaim ROI hari ini</button>
    @endforelse
</section>
@endif
