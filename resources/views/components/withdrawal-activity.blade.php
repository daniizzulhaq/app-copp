@if(isset($withdrawalActivity))
<section class="panel padded-panel">
    <div class="panel-heading"><div><h2>Member yang sudah WD</h2><p>{{ \Carbon\Carbon::parse($withdrawalActivity['from'])->format('d M Y') }} – {{ \Carbon\Carbon::parse($withdrawalActivity['to'])->format('d M Y') }}</p></div></div>
    <div class="metric-strip"><span>Member berhasil WD <b>{{ $withdrawalActivity['member_count'] }}</b></span><span>Total diterima member <b>{{ \App\Support\Money::rupiah($withdrawalActivity['net_amount']) }}</b></span></div>
    <p>Grafik menghitung member unik yang penarikannya diselesaikan admin setiap hari. Nominal adalah jumlah bersih setelah biaya admin.</p>
    <div class="chart-container"><canvas data-chart="withdrawal-members" role="img" aria-label="Grafik jumlah member berhasil WD per hari"></canvas></div>
    <script type="application/json" id="withdrawal-activity-data">{!! json_encode($withdrawalActivity['chart'], JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
</section>
@endif
