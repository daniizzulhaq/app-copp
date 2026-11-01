@props(['transactions'])
<div class="member-transaction-list">
    @forelse($transactions as $tx)
        @php
            $incoming = in_array($tx->kind,['deposit','roi_paid','principal_returned','withdrawal_rejected']);
            $outgoing = in_array($tx->kind,['investment','withdrawal_pending']);
            $icon = $incoming ? 'down' : ($outgoing ? 'up':'clock');
            $label = match($tx->kind) {
                'deposit'=>'Deposit manual', 'investment'=>'Aktivasi produk investasi', 'roi_paid'=>'ROI dibayar',
                'principal_returned'=>'Pengembalian modal',
                'roi_accrued'=>'ROI jatuh tempo', 'withdrawal_pending'=>'Withdrawal diajukan',
                'withdrawal_approved'=>'Withdrawal disetujui', 'withdrawal_rejected'=>'Saldo dikembalikan',
                default=>$tx->description,
            };
        @endphp
        <article class="member-transaction">
            <span class="member-list-icon {{ $incoming ? 'mint':($outgoing ? 'violet':'amber') }}"><x-icon :name="$icon"/></span>
            <div class="member-transaction-info"><strong>{{ \App\Support\UiText::local($label) }}</strong><small>{{ $tx->effective_date->format('d M Y') }} · #NV-{{ str_pad($tx->id,6,'0',STR_PAD_LEFT) }}</small></div>
            <div class="member-transaction-value {{ $incoming ? 'positive':'' }}"><b>{{ $incoming ? '+':($outgoing ? '−':'') }}{{ \App\Support\Money::rupiah($tx->entries_max_amount) }}</b><small>{{ $tx->kind === 'roi_accrued' ? 'Kewajiban internal':'Transaksi' }}</small></div>
        </article>
    @empty
        <x-empty title="Belum ada transaksi" message="Aktivitas saldo Anda akan tampil di sini."/>
    @endforelse
</div>
