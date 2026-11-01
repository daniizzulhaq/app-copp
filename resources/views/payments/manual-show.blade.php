@extends('layouts.app')
@section('title','Deposit transfer bank')
@section('content')
<div class="page-heading">
    <div><p class="eyebrow">TRANSFER BANK</p><h1>Deposit transfer bank</h1><p>#{{ strtoupper(substr($invoice->number,0,8)) }} · {{ $invoice->user->name }}</p></div>
    <a href="/payments?scenario={{ $invoice->simulation_scenario_id }}" class="btn btn-secondary">Riwayat deposit</a>
</div>
<div class="checkout-grid">
    <section class="panel checkout-main">
        <div class="panel-heading"><h2>Transfer & konfirmasi</h2><x-status :value="$invoice->status"/></div>
        <div class="checkout-amount"><small>Total yang harus ditransfer</small><strong>{{ \App\Support\Money::rupiah($invoice->amount) }}</strong><span>TRANSFER BANK · VERIFIKASI MANUAL</span></div>
        @if($invoice->status==='pending')
            <div class="padded-panel">
                <h2>1. Transfer ke rekening tujuan</h2>
                @include('payments.bank-account')
                <ol class="local-deposit-steps"><li>Transfer sesuai nominal deposit di atas.</li><li>Simpan bukti transfer dari aplikasi bank atau ATM.</li><li>Unggah bukti di bawah, lalu kirim untuk verifikasi admin.</li></ol>
            </div>
        @endif
        @can('submit',$invoice)
            @if($invoice->status==='pending')
                @if($invoice->expires_at->isPast())
                    <div class="padded-panel"><div class="error-summary">Batas pengiriman telah lewat. Buat pengajuan baru atau hubungi admin jika sudah transfer.</div></div>
                @else
                    <form method="post" action="/payments/{{ $invoice->id }}/submit" enctype="multipart/form-data" class="stack padded-panel" data-confirm="Kirim bukti transfer untuk verifikasi admin?">
                        @csrf
                        <h2>2. Unggah bukti transfer</h2>
                        <x-field name="reference" label="Nama pengirim / referensi transfer" required minlength="3" maxlength="100" placeholder="Nama pemilik rekening pengirim atau nomor referensi"/>
                        <label class="field"><span>Bukti transfer</span><input type="file" name="transfer_proof" accept="image/jpeg,image/png,image/webp,application/pdf" required aria-describedby="transfer-proof-help">@error('transfer_proof')<small class="field-error">{{ $message }}</small>@enderror</label>
                        <small id="transfer-proof-help" class="muted">Format JPG, PNG, WEBP, atau PDF. Maksimal 2 MB. Pastikan nominal, rekening tujuan, dan waktu transfer terbaca.</small>
                        <x-field name="note" label="Catatan untuk admin (opsional)" maxlength="500"/>
                        <div class="notice">Saldo masuk setelah admin memeriksa bukti transfer dan menyetujui deposit.</div>
                        <button class="btn btn-primary btn-full">Kirim bukti transfer <x-icon name="arrow"/></button>
                    </form>
                @endif
            @elseif($invoice->status==='awaiting_review')
                <div class="padded-panel"><div class="notice">Bukti transfer sudah dikirim. Menunggu verifikasi admin sebelum saldo ditambahkan.</div></div>
            @endif
            @if(in_array($invoice->status,['pending','awaiting_review']))
                <form method="post" action="/payments/{{ $invoice->id }}/cancel" class="padded-panel" data-confirm="Batalkan pengajuan deposit ini? Jika sudah transfer, hubungi admin sebelum membatalkan.">@csrf<button class="btn btn-secondary btn-full">Batalkan pengajuan</button></form>
            @endif
        @endcan
        @if($invoice->transfer_proof_path)
            <div class="padded-panel">
                <h2>Bukti transfer terkirim</h2>
                @if(in_array(strtolower(pathinfo($invoice->transfer_proof_path, PATHINFO_EXTENSION)), ['jpg','jpeg','png','webp']))
                    <a href="{{ route('payments.proof',$invoice) }}" target="_blank" rel="noopener"><img src="{{ route('payments.proof',$invoice) }}" alt="Bukti transfer deposit" class="deposit-proof-image"></a>
                @endif
                <a href="{{ route('payments.proof',$invoice) }}" class="btn btn-secondary btn-full" target="_blank" rel="noopener"><x-icon name="file"/>Buka bukti transfer</a>
            </div>
        @endif
    </section>
    <aside class="panel checkout-side">
        <h2>Detail deposit</h2>
        <dl>
            <dt>Nomor deposit</dt><dd class="break-all">{{ $invoice->number }}</dd>
            <dt>Dibuat</dt><dd>{{ $invoice->created_at->format('d M Y H:i') }} WIB</dd>
            <dt>Batas kirim bukti</dt><dd>{{ $invoice->expires_at->format('d M Y H:i') }} WIB</dd>
            <dt>Pengirim / referensi</dt><dd>{{ $invoice->submission_reference ?? 'Belum dikirim' }}</dd>
            <dt>Catatan member</dt><dd>{{ $invoice->submission_note ?: '—' }}</dd>
            <dt>Bukti dikirim</dt><dd>{{ $invoice->submitted_at?->format('d M Y H:i') ?? 'Belum' }}</dd>
            <dt>Ditinjau oleh</dt><dd>{{ $invoice->reviewer?->name ?? 'Belum ditinjau' }}</dd>
            <dt>Waktu tinjauan</dt><dd>{{ $invoice->reviewed_at?->format('d M Y H:i') ?? 'Belum' }}</dd>
            <dt>Catatan admin</dt><dd>{{ $invoice->review_note ?: '—' }}</dd>
            @if($invoice->ledger_transaction_id)<dt>Jurnal ledger</dt><dd>@can('view-reports')<a href="/transactions/{{ $invoice->ledger_transaction_id }}" class="text-link">#NV-{{ $invoice->ledger_transaction_id }} →</a>@else #NV-{{ $invoice->ledger_transaction_id }} @endcan</dd>@endif
        </dl>
        @can('manage')
            @if($invoice->status==='awaiting_review')
                <div class="notice">Cocokkan bukti dengan mutasi rekening sebelum menyetujui deposit.</div>
                <form method="post" action="/payments/{{ $invoice->id }}/review" class="stack" data-confirm="Bukti dan mutasi rekening sudah sesuai? Setujui deposit dan tambahkan saldo?">@csrf @method('patch')<input type="hidden" name="decision" value="approved"><x-field name="review_note" label="Catatan persetujuan (opsional)" maxlength="500"/><button class="btn btn-primary btn-full">Setujui deposit</button></form>
                <form method="post" action="/payments/{{ $invoice->id }}/review" class="stack local-rejection-form" data-confirm="Tolak pengajuan ini? Saldo tidak akan ditambahkan.">@csrf @method('patch')<input type="hidden" name="decision" value="rejected"><x-field name="review_note" label="Alasan penolakan" required maxlength="500"/><button class="btn btn-danger-soft btn-full">Tolak deposit</button></form>
            @endif
        @endcan
        @if($invoice->status==='paid_local')<div class="notice">Deposit disetujui. Saldo sudah ditambahkan ke wallet.</div>@can('transact')<a href="/wallet" class="btn btn-primary btn-full">Buka wallet</a>@endcan @endif
    </aside>
</div>
@endsection
