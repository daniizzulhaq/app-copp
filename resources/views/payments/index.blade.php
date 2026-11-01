@extends('layouts.app') @section('title','Deposit manual') @section('content')
@if($s->isLocal())
@include('payments.local-index')
@else

@if(auth()->user()->isStaff())

<div class="page-heading"><div><p class="eyebrow">MANUAL DEPOSIT</p><h1>Deposit manual<span class="heading-dot">.</span></h1><p>Buat dan konfirmasikan invoice internal tanpa memindahkan uang.</p></div><span class="tag">{{ \App\Support\UiText::local($s->name) }}</span></div>
@can('transact')<section class="panel form-panel"><div class="panel-heading"><div><h2>Buat invoice</h2><p>Saldo bertambah setelah pembayaran internal dikonfirmasi.</p></div><x-icon name="wallet"/></div><form method="post" action="/payments" class="inline-form">@csrf<input name="request_key" type="hidden" value="{{ old('request_key', (string)\Illuminate\Support\Str::uuid()) }}"><x-field name="amount" label="nominal (Rp)" type="number" min="10000" max="1000000000" step="1" required placeholder="1000000"/><label class="field"><span>Metode manual</span><select name="method"><option value="va_sandbox">Virtual Account manual</option><option value="qris_dummy">QRIS internal nonpembayaran</option><option value="bank_dummy">Transfer bank internal</option></select></label><button class="btn btn-primary"><x-icon name="plus"/>Buat invoice</button></form></section>@endcan
<section class="panel"><div class="panel-heading"><h2>Riwayat invoice</h2><form class="table-filters"><input name="q" value="{{ request('q') }}" placeholder="Cari nomor invoice" aria-label="Cari invoice"><select name="status" aria-label="Status invoice"><option value="">Semua status</option>@foreach(['pending','paid_simulated','failed','expired','cancelled'] as $st)<option value="{{ $st }}" @selected(request('status')===$st)>{{ ucwords(str_replace('_',' ',$st)) }}</option>@endforeach</select><select name="sort" aria-label="Urutan"><option value="desc">Terbaru</option><option value="asc" @selected(request('sort')==='asc')>Terlama</option></select><button class="btn btn-secondary btn-sm">Filter</button></form></div><div class="table-wrap"><table><thead><tr><th>Invoice</th><th>Member</th><th>Metode</th><th class="num">Nominal</th><th>Status</th><th></th></tr></thead><tbody>@forelse($invoices as $i)<tr><td><b>#{{ strtoupper(substr($i->number,0,8)) }}</b><small>{{ $i->created_at->format('d M Y H:i') }}</small></td><td>{{ $i->user->name }}</td><td>{{ \App\Support\UiText::local(str_replace('_',' ',$i->method)) }}</td><td class="num money">{{ \App\Support\Money::rupiah($i->amount) }}</td><td><x-status :value="$i->status"/></td><td><a class="text-link" href="/payments/{{ $i->id }}">Detail →</a></td></tr>@empty<tr><td colspan="6"><x-empty title="Belum ada invoice" message="Buat invoice pertama untuk mengisi wallet."/></td></tr>@endforelse</tbody></table></div><div class="pagination">{{ $invoices->links() }}</div></section>
@else
@include('member.payments')
@endif

@endif
@endsection
