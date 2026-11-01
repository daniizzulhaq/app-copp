@if($withdrawal->hasBankAccount())
    <div class="withdrawal-bank-destination">
        <strong>{{ $withdrawal->bank_name }}</strong>
        <span class="money">{{ $withdrawal->bank_account_number }}</span>
        <small>a.n. {{ $withdrawal->bank_account_holder }}</small>
    </div>
@else
    <small class="muted">Rekening tujuan belum tersedia</small>
@endif
