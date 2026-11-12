@php
    $savedProvider = auth()->user()->bank_name;
    $selectedProvider = old('bank_name', $savedProvider);
@endphp
<label class="field">
    <span>Bank / E-Wallet tujuan <b aria-hidden="true">*</b></span>
    <select name="bank_name" required @error('bank_name') aria-invalid="true" aria-describedby="error-bank_name" @enderror>
        <option value="">-- Pilih Bank / E-Wallet --</option>
        @foreach(\App\Support\WithdrawalDestination::groups() as $group => $providers)
            <optgroup label="{{ $group }}">
                @foreach($providers as $provider)
                    <option value="{{ $provider }}" @selected($selectedProvider === $provider)>{{ $provider }}</option>
                @endforeach
            </optgroup>
        @endforeach
        @if(filled($savedProvider) && !in_array($savedProvider, \App\Support\WithdrawalDestination::providers(), true))
            <optgroup label="TUJUAN TERSIMPAN"><option value="{{ $savedProvider }}" @selected($selectedProvider === $savedProvider)>{{ $savedProvider }}</option></optgroup>
        @endif
    </select>
    @error('bank_name')<small class="field-error" id="error-bank_name">{{ $message }}</small>@enderror
</label>
<x-field name="bank_account_number" label="Nomor rekening / nomor HP e-wallet" :value="auth()->user()->bank_account_number" required maxlength="50" inputmode="numeric" autocomplete="off" placeholder="Contoh: 081234567890" hint="Untuk e-wallet, isi nomor HP yang terdaftar. Untuk bank, isi nomor rekening."/>
<x-field name="bank_account_holder" label="Nama pemilik rekening / e-wallet" :value="auth()->user()->bank_account_holder" required maxlength="150" autocomplete="name" placeholder="Nama sesuai rekening atau e-wallet"/>
