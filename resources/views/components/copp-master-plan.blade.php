@php($landingContent = $landingContent ?? config('landing.master_plan'))
<section class="landing-section" id="master-plan">
<div class="section-intro"><p class="eyebrow">{{ $landingContent['label'] }}</p><h2>{{ $landingContent['judul'] }}</h2><p>Paket Rp100.000, maksimal 10 pembelian per akun. Pool ROI 25% omzet produk. Maksimal Rp210.000 lalu selesai tanpa pengembalian modal tambahan.</p></div>
<div class="panel table-wrap"><table><thead><tr><th>Harga produk</th><th>Bagian ROI</th><th>Maksimal total ROI</th></tr></thead><tbody>
@foreach(config('copp.plans') as [$price,$daily,$total,$shares])
<tr><td>{{ \App\Support\Money::rupiah($price) }}</td><td>{{ $shares }} bagian</td><td>{{ \App\Support\Money::rupiah(intdiv($price * $total, 10000)) }}</td></tr>
@endforeach
</tbody></table></div>
<div class="package-grid copp-mission-grid">
@foreach(range(1,3) as $index)
<article class="package-card"><h3>{{ $landingContent['judul_'.$index] }}</h3><p class="landing-editable-text">{{ $landingContent['isi_'.$index] }}</p></article>
@endforeach
</div><p class="member-fine-print landing-editable-text">{{ $landingContent['catatan'] }}</p>
</section>
