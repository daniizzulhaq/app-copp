@props(['turnover', 'showFilter' => false])
<section class="panel">
    <div class="panel-heading"><div><h2>Omzet masuk, alokasi ROI &amp; beban bonus</h2><p>Pantau pembelian produk, alokasi ROI 30%, dan beban bonus referral serta misi.</p></div><span class="tag">30% untuk ROI</span></div>
    @if($showFilter)
        <form class="report-filters" method="get">
            <label class="field"><span>Dari tanggal</span><input type="date" name="from" value="{{ $turnover['from'] }}"></label>
            <label class="field"><span>Sampai tanggal</span><input type="date" name="to" value="{{ $turnover['to'] }}"></label>
            <button class="btn btn-secondary btn-sm">Tampilkan omzet</button>
        </form>
    @endif
    <div class="padded-panel">
        <h3>Hari ini</h3>
        <div class="kpi-grid">
            @foreach([['Omzet masuk produk', $turnover['today']->gross_amount, 'Pembelian produk yang berhasil'], ['Alokasi ROI 30%', $turnover['today']->roi_amount, '30% dari omzet produk'], ['Beban bonus', $turnover['today']->bonus_amount, 'Bonus referral + bonus misi']] as [$label, $value, $hint])
                <article class="kpi-card"><div class="kpi-top"><span>{{ $label }}</span></div><strong>{{ \App\Support\Money::rupiah($value) }}</strong><small>{{ $hint }}</small></article>
            @endforeach
        </div>
        <div class="metric-strip">
            <span>Dana masuk (deposit terverifikasi) <b>{{ \App\Support\Money::rupiah($turnover['today']->funds_in) }}</b></span>
            <span>Bonus referral <b>{{ \App\Support\Money::rupiah($turnover['today']->referral_bonus) }}</b></span>
            <span>Bonus misi <b>{{ \App\Support\Money::rupiah($turnover['today']->mission_bonus) }}</b></span>
        </div>
    </div>
    <div class="padded-panel">
        <h3>Total periode {{ \Carbon\Carbon::parse($turnover['from'])->format('d M Y') }} sampai {{ \Carbon\Carbon::parse($turnover['to'])->format('d M Y') }}</h3>
        <div class="metric-strip">
            <span>Omzet masuk produk <b>{{ \App\Support\Money::rupiah($turnover['gross']) }}</b></span>
            <span>Alokasi ROI 30% <b>{{ \App\Support\Money::rupiah($turnover['roi']) }}</b></span>
            <span>Beban bonus <b>{{ \App\Support\Money::rupiah($turnover['bonus']) }}</b></span>
            <span>Dana masuk (deposit terverifikasi) <b>{{ \App\Support\Money::rupiah($turnover['funds_in']) }}</b></span>
            <span>Bonus referral <b>{{ \App\Support\Money::rupiah($turnover['referral_bonus']) }}</b></span>
            <span>Bonus misi <b>{{ \App\Support\Money::rupiah($turnover['mission_bonus']) }}</b></span>
        </div>
        <p class="muted text-sm">Contoh: omzet produk Rp1.000.000 × 30% = alokasi ROI Rp300.000. Deposit dihitung sebagai dana masuk saat disetujui; omzet produk dihitung saat pembelian berhasil. Keduanya ditampilkan terpisah. Alokasi ROI dicatat terpisah dari ROI yang diklaim member.</p>
        <p class="muted text-sm">Beban bonus adalah hak bonus referral dan misi yang tercatat dan otomatis dikreditkan ke wallet member. Nilai ini belum berarti sudah ditransfer ke bank. Penarikan mengikuti pengajuan WD. Tanggal pencatatan menggunakan WIB.</p>
    </div>
    <div class="table-wrap"><table>
        <thead><tr><th>Tanggal (WIB)</th><th class="num">Dana masuk deposit</th><th class="num">Omzet masuk produk</th><th class="num">Alokasi ROI</th><th class="num">Bonus referral</th><th class="num">Bonus misi</th><th class="num">Beban bonus</th></tr></thead>
        <tbody>
            @forelse($turnover['days'] as $day)
                <tr>
                    <td>{{ $day->period_date->format('d M Y') }}</td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->funds_in) }}</td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->gross_amount) }}<small>{{ $day->purchase_count }} pembelian</small></td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->roi_amount) }}<small>{{ number_format($day->roi_rate_bps / 100, 0, ',', '.') }}% omzet produk</small></td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->referral_bonus) }}</td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->mission_bonus) }}</td>
                    <td class="num money">{{ \App\Support\Money::rupiah($day->bonus_amount) }}</td>
                </tr>
            @empty
                <tr><td colspan="7"><x-empty title="Belum ada pencatatan pada periode ini" message="Deposit terverifikasi, pembelian produk, dan bonus akan muncul sesuai tanggal pencatatannya."/></td></tr>
            @endforelse
        </tbody>
    </table></div>
    <div class="pagination">{{ $turnover['days']->links() }}</div>
</section>
