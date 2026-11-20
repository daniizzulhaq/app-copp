@if(isset($s) && app(\App\Services\GotongRoyongBonusService::class)->canClaimRegistration(auth()->user(), $s))
<section class="panel padded-panel"><h2>Bonus akun baru Rp5.000</h2><p>Klaim sekali untuk menambahkan bonus daftar ke saldo wallet.</p>
    <form method="post" action="{{ route('bonuses.registration.claim') }}">@csrf<button class="btn btn-primary">Klaim bonus daftar Rp5.000</button></form>
</section>
@endif
