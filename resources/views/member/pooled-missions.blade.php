<section class="panel padded-panel"><h2>Misi member</h2><div class="package-grid copp-mission-grid">
<article class="package-card"><h3>Bonus daftar</h3><strong>Rp5.000</strong><p>Diklaim sekali oleh akun yang baru mendaftar pada plan aktif.</p></article>
<article class="package-card"><h3>Undang teman mendaftar</h3><strong>Rp5.000 per teman</strong><p>Otomatis masuk saat teman baru mendaftar menggunakan kode referral Anda.</p></article>
<article class="package-card"><h3>Cashback aktivasi</h3><strong>Rp10.000</strong><p>Sekali per akun setelah deposit terverifikasi minimal Rp100.000 dan pembelian produk.</p></article>
</div></section>
@include('member.registration-bonus')
<section class="panel padded-panel"><h2>Bonus aktivasi produk</h2><p>Nominal berikut berlaku per pembelian produk Rp100.000 maupun Rp1.000.000. Deposit saja tidak membayar komisi jaringan.</p><div class="table-wrap"><table><thead><tr><th>Penerima</th><th>Bonus</th></tr></thead><tbody><tr><td>Pengundang langsung</td><td>Rp25.000</td></tr>@foreach(range(1,5) as $level)<tr><td>Level {{ $level }} (upline setelah pengundang langsung)</td><td>Rp5.000</td></tr>@endforeach</tbody></table></div></section>
