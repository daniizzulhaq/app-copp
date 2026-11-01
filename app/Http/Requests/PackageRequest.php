<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage');
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'minimum_amount' => 'required|integer|min:10000|max:1000000000', 'daily_rate_bps' => 'required|integer|min:1|max:10000', 'daily_roi_cap' => 'nullable|integer|min:1|max:1000000000', 'duration_days' => 'required|integer|min:1|max:365', 'total_roi_bps' => 'nullable|integer|min:1|max:100000', 'purchase_limit' => 'required|integer|min:1|max:3', 'fixed_amount' => 'required|boolean', 'description' => 'nullable|string|max:1000', 'active' => 'required|boolean'];
    }
}
