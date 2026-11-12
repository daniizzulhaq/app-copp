@if($withdrawal->hasBankAccount())
    <div class="withdrawal-bank-destination">
        <small>{{ $withdrawal->destinationTypeLabel() }}</small>
        <strong>{{ $withdrawal->bank_name }}</strong>
        <span class="money">{{ $withdrawal->bank_account_number }}</span>
        <small>a.n. {{ $withdrawal->bank_account_holder }}</small>
    </div>
@else
    <small class="muted">Tujuan penarikan belum tersedia</small>
@endif
