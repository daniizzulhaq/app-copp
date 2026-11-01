@props(['value'])
@php($labels=['awaiting_review'=>'Menunggu verifikasi','paid_local'=>'Terverifikasi','active'=>'Aktif','running'=>'Berjalan','draft'=>'Draft','completed'=>'Selesai','defaulted'=>'Gagal bayar','overdue'=>'Tertunggak','pending'=>'Menunggu','paid_simulated'=>'Berhasil','paid'=>'Dibayar','failed'=>'Gagal','expired'=>'Hangus','due'=>'Siap diklaim','cancelled'=>'Dibatalkan','approved'=>'Disetujui','rejected'=>'Ditolak','archived'=>'Diarsipkan','suspended'=>'Ditangguhkan'])
<span class="status status-{{ $value }}"><i></i>{{ $labels[$value] ?? ucfirst($value) }}</span>
