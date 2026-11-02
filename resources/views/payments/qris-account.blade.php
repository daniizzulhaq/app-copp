<h3>{{ $invoice->methodLabel() }}</h3>
<p>Nama penerima / merchant: <strong>{{ $invoice->payment_destination['holder'] ?? '-' }}</strong></p>
<a href="{{ route('payments.qris', $invoice) }}" target="_blank" rel="noopener"><img class="deposit-qris-image" src="{{ route('payments.qris', $invoice) }}" alt="Kode {{ $invoice->methodLabel() }} untuk pembayaran deposit"></a>
<a href="{{ route('payments.qris', $invoice) }}" target="_blank" rel="noopener" class="text-link">Buka gambar QRIS</a>
<p class="muted">Pindai kode atau simpan gambar untuk dibuka di aplikasi pembayaran. Periksa nama penerima dan masukkan total pembayaran beserta kode unik.</p>
