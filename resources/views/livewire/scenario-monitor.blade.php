<section class="panel padded-panel" wire:poll.10s.visible>
    <div class="panel-heading"><div><h2>Monitor skenario langsung</h2><p>Diperbarui setiap 10 detik · {{ now()->format('H:i:s') }} WIB</p></div><span class="tag" wire:loading>Memperbarui…</span></div>
    <div class="metric-strip"><span>Hari berjalan <b>{{ $scenario->current_day }}</b></span><span>Kas bebas <b>{{ \App\Support\Money::rupiah($risk['cash']) }}</b></span><span>Tunggakan <b>{{ \App\Support\Money::rupiah($risk['outstanding']) }}</b></span><span>Cakupan <b>{{ $risk['coverage'] }}%</b></span></div>
</section>
