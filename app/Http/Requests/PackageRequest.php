<?php

namespace App\Http\Requests;

use App\Models\SimulationScenario;
use App\Models\SystemSetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage');
    }

    public function rules(): array
    {
        $rules = ['name' => 'required|string|max:100', 'minimum_amount' => 'required|integer|min:1|max:1000000000', 'daily_rate_bps' => 'required|integer|min:0|max:10000', 'daily_roi_cap' => 'nullable|integer|min:1|max:1000000000', 'duration_days' => 'required|integer|min:0|max:365', 'total_roi_bps' => 'nullable|integer|min:1|max:100000', 'purchase_limit' => 'required|integer|min:1|max:10', 'fixed_amount' => 'required|boolean', 'description' => 'nullable|string|max:1000', 'active' => 'required|boolean', 'roi_method' => 'sometimes|in:fixed,turnover', 'roi_shares' => 'sometimes|integer|min:1|max:10'];
        $workspace = SimulationScenario::find(SystemSetting::whereKey('local_workspace')->value('value'));
        if ($this->input('roi_method') !== 'turnover') {
            $rules['daily_rate_bps'] = 'required|integer|min:1|max:10000';
            $rules['duration_days'] = 'required|integer|min:1|max:365';
        }
        if ($this->input('roi_method') === 'turnover' || $workspace?->usesTurnoverRoi()) {
            $rules['roi_method'] = 'required|in:turnover';
            $rules['minimum_amount'] = ['required', 'integer', Rule::in([100000, 1000000])];
            if ($this->boolean('active')) {
                $rules['minimum_amount'][] = Rule::unique('roi_packages', 'minimum_amount')->where('active', true)->ignore($this->route('package')?->id);
            }
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('roi_method') === 'turnover') {
            $this->merge(['daily_rate_bps' => 0, 'duration_days' => 0, 'daily_roi_cap' => null,
                'total_roi_bps' => 21000, 'fixed_amount' => true,
                'roi_shares' => (int) $this->input('minimum_amount') === 1000000 ? 10 : 1]);
        }
    }
}
