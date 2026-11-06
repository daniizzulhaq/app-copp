<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class InvestmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('transact');
    }

    public function rules(): array
    {
        return ['amount' => 'required|integer|min:1|max:1000000000', 'roi_package_id' => 'required|integer|exists:roi_packages,id', 'request_key' => 'required|uuid'];
    }
}
