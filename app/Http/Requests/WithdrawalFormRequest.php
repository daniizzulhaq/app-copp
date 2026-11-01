<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WithdrawalFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transact');
    }

    public function rules(): array
    {
        return ['amount' => 'required|integer|min:50000|max:1000000000', 'method' => 'required|in:bank_transfer,bank_dummy,wallet_dummy', 'note' => 'nullable|string|max:500', 'request_key' => 'required|uuid'];
    }
}
