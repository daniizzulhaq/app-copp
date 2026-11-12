@can('transact')
<section class="panel padded-panel" id="rekening-bank">
    <h2>Bank / E-Wallet saya</h2>
    <p class="muted text-sm">Simpan tujuan WD agar terisi otomatis saat mengajukan penarikan. Pastikan nomor dan nama pemilik sudah benar.</p>
    <form method="post" action="{{ route('profile.bank-account') }}" class="stack">
        @csrf @method('put')
        @include('withdrawals.destination-fields')
        <small class="muted">Perubahan tujuan berlaku untuk pengajuan baru. Tujuan pada pengajuan yang sudah dikirim tetap menggunakan data saat pengajuan.</small>
        <button class="btn btn-primary btn-full">Simpan tujuan WD</button>
    </form>
</section>
@endcan
