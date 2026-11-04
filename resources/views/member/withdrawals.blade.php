<div class="member-page-intro"><p>PENARIKAN SALDO</p><h1>Tarik saldo</h1><span>Dana dikirim admin ke rekening bank yang Anda daftarkan.</span></div>
<details class="member-section" @if(!auth()->user()->hasBankAccount() || $errors->hasAny(['bank_name','bank_account_number','bank_account_holder'])) open @endif>
    <summary>{{ auth()->user()->hasBankAccount() ? 'Ubah rekening bank':'Tambahkan rekening bank' }}</summary>
    @include('profile.bank-account')
</details>
<section class="member-section member-form-card">
    <div class="member-section-heading"><div><h2>Ajukan withdrawal</h2><p>Saldo ditahan sementara hingga admin menyelesaikan transfer.</p></div><span class="member-list-icon violet"><x-icon name="up"/></span></div>
    @unless($hasPurchasedProduct)
        <div class="notice">Beli minimal satu produk terlebih dahulu sebelum mengajukan penarikan. <a href="/packages" class="text-link">Lihat produk →</a></div>
    @endunless
    @if(auth()->user()->hasBankAccount())
        <div class="notice"><strong>Rekening tujuan</strong><br>{{ auth()->user()->bank_name }} · <span class="money">{{ auth()->user()->bank_account_number }}</span><br>a.n. {{ auth()->user()->bank_account_holder }}</div>
    @else
        <div class="notice">Simpan rekening bank Anda di atas sebelum mengajukan withdrawal.</div>
    @endif
    <form method="post" action="/withdrawals" class="stack" data-confirm="Ajukan withdrawal ke rekening terdaftar? Saldo akan ditahan sementara.">
        @csrf
        <input name="request_key" type="hidden" value="{{ old('request_key',(string)\Illuminate\Support\Str::uuid()) }}">
        <input name="method" type="hidden" value="bank_transfer">
        <x-field name="amount" label="Nominal withdrawal (Rp)" type="number" :min="$minimumWithdrawalAmount" max="1000000000" required inputmode="numeric" :placeholder="'Contoh: '.$minimumWithdrawalAmount"/>
        <p class="notice">Minimum WD {{ \App\Support\Money::rupiah($minimumWithdrawalAmount) }}. Biaya admin 10% dipotong dari nominal pengajuan. Contoh: WD {{ \App\Support\Money::rupiah($minimumWithdrawalAmount) }}, admin {{ \App\Support\Money::rupiah(\App\Support\Money::rate($minimumWithdrawalAmount, 1000)) }}, diterima {{ \App\Support\Money::rupiah($minimumWithdrawalAmount - \App\Support\Money::rate($minimumWithdrawalAmount, 1000)) }}.</p>
        <x-field name="note" label="Catatan (opsional)" maxlength="500" placeholder="Tambahkan catatan pengajuan"/>
        <div class="member-inline-note"><x-icon name="shield"/><p>Periksa rekening tujuan sebelum mengirim. Jika pengajuan ditolak, saldo dikembalikan ke wallet.</p></div>
        <button class="btn btn-primary btn-full" @disabled(!$hasPurchasedProduct || !auth()->user()->hasBankAccount())>Ajukan penarikan <x-icon name="up"/></button>
    </form>
</section>
<section class="member-section">
    <div class="member-section-heading"><h2>Riwayat penarikan</h2><span class="tag">{{ $withdrawals->total() }}</span></div>
    <form class="member-history-filter">
        <label class="member-search"><x-icon name="search"/><input name="q" value="{{ request('q') }}" placeholder="Cari catatan penarikan" aria-label="Cari withdrawal"></label>
        <div class="member-filter-row">
            <select name="status" aria-label="Status penarikan"><option value="">Semua status</option>@foreach(['pending'=>'Menunggu transfer','approved'=>'Selesai','rejected'=>'Ditolak'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select>
            <select name="sort" aria-label="Urutan penarikan"><option value="desc">Terbaru</option><option value="asc" @selected(request('sort')==='asc')>Terlama</option></select>
            <button class="btn btn-secondary btn-sm">Filter</button>
        </div>
    </form>
    <div class="member-history-cards">
        @forelse($withdrawals as $withdrawal)
            <article class="member-history-card">
                <div class="member-history-top"><span class="member-list-icon violet"><x-icon name="up"/></span><div><strong>Transfer bank</strong><small>#WD-{{ str_pad($withdrawal->id,5,'0',STR_PAD_LEFT) }} · {{ $withdrawal->created_at->format('d M Y') }}</small></div></div>
                <div class="member-history-bottom"><strong>{{ \App\Support\Money::rupiah($withdrawal->amount) }}</strong><x-status :value="$withdrawal->status==='approved' ? 'completed' : $withdrawal->status"/></div>
                <p>Admin: {{ \App\Support\Money::rupiah($withdrawal->admin_fee) }} &middot; Diterima: <strong>{{ \App\Support\Money::rupiah($withdrawal->netAmount()) }}</strong></p>
                @include('withdrawals.bank-destination')
                @if($withdrawal->note)<p>{{ $withdrawal->note }}</p>@endif
                @if($withdrawal->review_note)<p>Catatan admin: {{ $withdrawal->review_note }}</p>@endif
                <div class="member-history-review"><x-icon name="clock"/><span>{{ $withdrawal->reviewed_at ? 'Ditinjau '.$withdrawal->reviewed_at->format('d M Y H:i'):'Menunggu tinjauan dan transfer admin' }}</span></div>
            </article>
        @empty
            <x-empty title="Belum ada withdrawal" message="Pengajuan penarikan Anda akan muncul di sini."/>
        @endforelse
    </div>
    <div class="pagination">{{ $withdrawals->links() }}</div>
</section>
