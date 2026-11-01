<section class="landing-section" id="master-plan">
    <div class="section-intro"><p class="eyebrow">COPP LUXURY</p><h2>Master plan COPP</h2><p>Sistem jaringan matahari. Maksimal 3 pembelian per produk per akun.</p></div>
    <div class="panel table-wrap"><table><thead><tr><th>Harga plan</th><th>Max ROI harian</th><th>Max Total ROI</th></tr></thead><tbody>
    @foreach(config('copp.plans') as [$price,$daily,$total])
        <tr><td>{{ \App\Support\Money::rupiah($price) }}</td><td>{{ $daily/100 }}%</td><td>{{ $total/100 }}%</td></tr>
    @endforeach
    </tbody></table></div>
    <div class="package-grid copp-mission-grid">
        <article class="package-card"><h3>Misi harian</h3><p>3 akun baru: Rp5.000<br>15 akun baru: Rp15.000<br>5 referral top up: Rp25.000<br>15 referral top up: Rp100.000</p></article>
        <article class="package-card"><h3>Misi sekali saja</h3><p>Omset referral Rp100.000: Rp2.000<br>Omset referral Rp5.000.000: Rp20.000<br>Omset referral Rp25.000.000: Rp150.000</p></article>
        <article class="package-card"><h3>Bonus referral</h3><p>Level 1: 15%<br>Level 2: 3%<br>Level 3–5: 2%<br>Level 6–15: 1%</p></article>
    </div>
    <p class="member-fine-print">Minimal WD Rp50.000 · Admin 10%. Bonus dari top up terverifikasi. Total ROI dihitung terpisah dari pengembalian modal.</p>
</section>
