@if($bankAccount['bank'] && $bankAccount['number'] && $bankAccount['holder'])
    <dl class="definition-grid deposit-bank-account">
        <dt>Bank tujuan</dt><dd>{{ $bankAccount['bank'] }}</dd>
        <dt>Nomor rekening</dt><dd class="money break-all"><strong>{{ $bankAccount['number'] }}</strong></dd>
        <dt>Atas nama</dt><dd>{{ $bankAccount['holder'] }}</dd>
    </dl>
@else
    <div class="notice">Rekening tujuan belum diatur. Hubungi admin untuk mendapatkan rekening tujuan sebelum transfer.</div>
@endif
