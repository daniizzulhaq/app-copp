<?php

namespace App\Http\Requests;

use App\Models\SystemSetting;
use App\Support\Money;
use App\Support\WithdrawalDestination;
use Illuminate\Foundation\Http\FormRequest;

class WithdrawalFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transact');
    }

    public function rules(): array
    {
        $rules = ['amount' => 'required|integer|min:'.SystemSetting::minimumWithdrawalAmount().'|max:1000000000', 'method' => 'required|in:bank_transfer,bank_dummy,wallet_dummy', 'note' => 'nullable|string|max:500', 'request_key' => 'required|uuid'];
        if ($this->hasAny(WithdrawalDestination::FIELDS)) {
            $rules = array_merge($rules, WithdrawalDestination::rules($this->user()->bank_name));
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(WithdrawalDestination::normalize($this->only(WithdrawalDestination::FIELDS)));
    }

    public function messages(): array
    {
        return array_merge(WithdrawalDestination::messages(), ['amount.min' => 'WD minimal '.Money::rupiah(SystemSetting::minimumWithdrawalAmount()).'.']);
    }
}
