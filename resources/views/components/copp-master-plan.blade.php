@php($landingContent = $landingContent ?? config('landing.master_plan'))
<section class="landing-section" id="master-plan">
    <div class="section-intro"><p class="eyebrow">{{ $landingContent['label'] }}</p><h2>{{ $landingContent['judul'] }}</h2><p>{{ $landingContent['deskripsi'] }}</p></div>
    <div class="panel table-wrap"><table><thead><tr><th>Harga plan</th><th>Max ROI harian</th><th>Max Total ROI</th></tr></thead><tbody>
    @foreach(config('copp.plans') as [$price,$daily,$total])
        <tr><td>{{ \App\Support\Money::rupiah($price) }}</td><td>{{ $daily/100 }}%</td><td>{{ $total/100 }}%</td></tr>
    @endforeach
    </tbody></table></div>
    <div class="package-grid copp-mission-grid">
        @foreach(range(1,3) as $index)
        <article class="package-card"><h3>{{ $landingContent['judul_'.$index] }}</h3><p class="landing-editable-text">{{ $landingContent['isi_'.$index] }}</p></article>
        @endforeach
    </div>
    <p class="member-fine-print landing-editable-text">{{ $landingContent['catatan'] }}</p>
</section>
