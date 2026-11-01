<section class="panel padded-panel">
    <h2>Pengembalian modal</h2>
    <p class="muted">ROI masuk ke saldo setelah diklaim. Modal jatuh tempo pada {{ $investment->ends_at->format('d M Y') }} dan diproses otomatis ketika halaman akun diakses, tanpa perlu tombol klaim modal. Modal dikembalikan penuh ke saldo web berdasarkan nominal pembelian yang tersimpan.</p>
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
