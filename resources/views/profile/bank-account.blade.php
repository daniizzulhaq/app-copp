@can('transact')
<section class="panel padded-panel" id="rekening-bank">
    <h2>Rekening bank saya</h2>
    <p class="muted text-sm">Admin menggunakan rekening ini untuk mengirim withdrawal Anda. Pastikan nomor rekening dan nama pemilik sudah benar.</p>
    <form method="post" action="{{ route('profile.bank-account') }}" class="stack">
        @csrf @method('put')
        <x-field name="bank_name" label="Nama bank" :value="auth()->user()->bank_name" required maxlength="100" placeholder="Contoh: BCA, BRI, Mandiri"/>
        <x-field name="bank_account_number" label="Nomor rekening" :value="auth()->user()->bank_account_number" required maxlength="50" inputmode="numeric" autocomplete="off" placeholder="Nomor rekening tujuan withdrawal"/>
        <x-field name="bank_account_holder" label="Nama pemilik rekening" :value="auth()->user()->bank_account_holder" required maxlength="150" autocomplete="name" placeholder="Nama sesuai rekening bank"/>
        <small class="muted">Perubahan rekening berlaku untuk pengajuan baru. Rekening pada pengajuan yang sudah dikirim tetap menggunakan data saat pengajuan.</small>
        <button class="btn btn-primary btn-full">Simpan rekening bank</button>
    </form>
</section>
@endcan
