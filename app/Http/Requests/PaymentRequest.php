<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transact');
    }

    public function rules(): array
    {
        return ['amount' => 'required|integer|min:10000|max:1000000000', 'method' => 'required|in:manual_local,qris_dana,qris_gopay,va_sandbox,qris_dummy,bank_dummy', 'request_key' => 'required|uuid'];
    }
}
