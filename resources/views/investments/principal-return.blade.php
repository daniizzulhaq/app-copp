@if(! $investment->returns_principal)
<section class="panel padded-panel">
    <h2>Batas penerimaan produk</h2>
    <p>Paket selesai setelah mencapai {{ \App\Support\Money::rupiah($investment->promised()) }}. Tidak ada pengembalian modal tambahan. Bonus dicatat terpisah dari batas produk.</p>
    <dl class="definition-grid">
        <dt>Harga paket</dt><dd>{{ \App\Support\Money::rupiah($investment->principal) }}</dd>
        <dt>Maksimal penerimaan produk</dt><dd>{{ \App\Support\Money::rupiah($investment->maximumPayout()) }}</dd>
        <dt>Sudah diklaim</dt><dd>{{ \App\Support\Money::rupiah($investment->obligations()->sum('paid_amount')) }}</dd>
    </dl>
</section>
@else
<section class="panel padded-panel">
    <h2>Pengembalian modal</h2>
    @if($investment->roi_method === 'turnover')<p>Modal dikembalikan terpisah setelah batas total ROI produk tercapai. Waktunya mengikuti omzet, tanpa tanggal atau keuntungan harian tetap.</p>@else
    <p class="muted">ROI masuk ke saldo setelah diklaim. Modal jatuh tempo pada {{ $investment->ends_at->format('d M Y') }} dan diproses otomatis ketika halaman akun diakses, tanpa perlu tombol klaim modal. Modal dikembalikan penuh ke saldo web berdasarkan nominal pembelian yang tersimpan.</p>
    @endif
    <dl class="definition-grid">
        <dt>Modal awal</dt><dd>{{ \App\Support\Money::rupiah($investment->principal) }}</dd>
        <dt>Modal sudah dikembalikan</dt><dd>{{ \App\Support\Money::rupiah($investment->principal_returned_amount) }}</dd>
        <dt>Sisa modal</dt><dd>{{ \App\Support\Money::rupiah($investment->principal - $investment->principal_returned_amount) }}</dd>
        <dt>Total ROI selama investasi</dt><dd>{{ \App\Support\Money::rupiah($investment->promised()) }}</dd>
        <dt>Total modal + ROI</dt><dd>{{ \App\Support\Money::rupiah($investment->principal + $investment->promised()) }}</dd>
        @if($investment->principal_returned_at)
            <dt>Modal lunas dikembalikan</dt><dd>{{ $investment->principal_returned_at->format('d M Y H:i') }}</dd>
        @endif
    </dl>
</section>
@endif
