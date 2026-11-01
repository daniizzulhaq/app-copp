@extends('layouts.app')
@section('title','Withdrawal')
@section('content')
@if(auth()->user()->isStaff())
    <div class="page-heading"><div><p class="eyebrow">WITHDRAWALS</p><h1>Withdrawal<span class="heading-dot">.</span></h1><p>Transfer manual ke rekening tujuan pada pengajuan, lalu tandai selesai setelah transfer berhasil.</p></div><span class="tag">{{ \App\Support\UiText::local($s->name) }}</span></div>
    <section class="panel">
        <div class="panel-heading">
            <h2>Daftar permintaan</h2>
            <form class="table-filters">
                <input name="q" value="{{ request('q') }}" placeholder="Cari member atau catatan" aria-label="Cari withdrawal">
                <select name="status" aria-label="Status"><option value="">Semua status</option>@foreach(['pending'=>'Menunggu transfer','approved'=>'Selesai','rejected'=>'Ditolak'] as $value=>$label)<option value="{{ $value }}" @selected(request('status')===$value)>{{ $label }}</option>@endforeach</select>
                <select name="sort" aria-label="Urutan"><option value="desc">Terbaru</option><option value="asc" @selected(request('sort')==='asc')>Terlama</option></select>
                <button class="btn btn-secondary btn-sm">Filter</button>
            </form>
        </div>
        <div class="table-wrap"><table>
            <thead><tr><th>Permintaan</th><th>Member</th><th class="num">Nominal</th><th>Rekening tujuan / catatan</th><th>Status</th><th>Tindakan</th></tr></thead>
            <tbody>
                @forelse($withdrawals as $w)
                    <tr>
                        <td><b>#WD-{{ str_pad($w->id,5,'0',STR_PAD_LEFT) }}</b><small>{{ $w->created_at->format('d M Y H:i') }}</small></td>
                        <td>{{ $w->user->name }}</td>
                        <td class="num money">{{ \App\Support\Money::rupiah($w->amount) }}<small>Admin {{ \App\Support\Money::rupiah($w->admin_fee) }}</small><small>Transfer <b>{{ \App\Support\Money::rupiah($w->netAmount()) }}</b></small></td>
                        <td>@include('withdrawals.bank-destination',['withdrawal'=>$w])@if($w->note)<small>{{ $w->note }}</small>@endif @if($w->review_note)<small>Catatan admin: {{ $w->review_note }}</small>@endif</td>
                        <td><x-status :value="$w->status==='approved' ? 'completed' : $w->status"/></td>
                        <td>
                            @can('manage')
                                @if($w->status==='pending')
                                    <form method="post" action="/withdrawals/{{ $w->id }}" class="stack" data-confirm="Pastikan transfer sudah berhasil ke rekening tujuan pengajuan ini. Tandai withdrawal selesai?">
                                        @csrf @method('patch')
                                        <input name="decision" value="approved" type="hidden">
                                        <x-field name="review_note" label="Referensi transfer / catatan (opsional)" maxlength="500"/>
                                        <button class="btn btn-primary btn-sm" @disabled(($s->isLocal() || $w->method==='bank_transfer') && !$w->hasBankAccount())>Sudah ditransfer</button>
                                    </form>
                                    <form method="post" action="/withdrawals/{{ $w->id }}" class="stack local-rejection-form" data-confirm="Tolak pengajuan dan kembalikan saldo ke wallet member?">
                                        @csrf @method('patch')<input name="decision" value="rejected" type="hidden">
                                        <x-field name="review_note" label="Alasan penolakan (opsional)" maxlength="500"/>
                                        <button class="btn btn-danger-soft btn-sm">Tolak withdrawal</button>
                                    </form>
                                @else
                                    {{ $w->reviewed_at?->format('d M Y H:i') }}
                                @endif
                            @else
                                {{ $w->reviewed_at?->format('d M Y H:i') ?? 'Menunggu admin' }}
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6"><x-empty title="Belum ada withdrawal"/></td></tr>
                @endforelse
            </tbody>
        </table></div>
        <div class="pagination">{{ $withdrawals->links() }}</div>
    </section>
@else
    @include('member.withdrawals')
@endif
@endsection
