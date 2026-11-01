<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ScenarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage');
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:100', 'description' => 'nullable|string|max:1000', 'start_date' => 'required|date_format:Y-m-d',
            'initial_members' => 'required|integer|min:0|max:10000', 'daily_new_members' => 'required|integer|min:0|max:1000', 'growth_bps' => 'required|integer|min:-10000|max:10000',
            'decline_bps' => 'required|integer|min:0|max:10000', 'decline_after_day' => 'required|integer|min:0|max:365', 'average_deposit' => 'required|integer|min:10000|max:100000000',
            'daily_rate_bps' => 'required|integer|min:1|max:10000', 'duration_days' => 'required|integer|min:1|max:365', 'payment_frequency_days' => 'required|integer|min:1|max:365',
            'withdrawal_bps' => 'required|integer|min:0|max:10000', 'initial_cash' => 'required|integer|min:0|max:1000000000000'];
    }
}
