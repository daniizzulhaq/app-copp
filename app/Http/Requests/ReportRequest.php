<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view-reports');
    }

    public function rules(): array
    {
        return ['scenario' => 'nullable|integer|exists:simulation_scenarios,id', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'kind' => 'nullable|in:deposit,investment,roi_accrued,roi_paid,roi_expired,principal_returned,withdrawal_pending,withdrawal_approved,withdrawal_rejected,seed,reversal,liability_opening', 'q' => 'nullable|string|max:100', 'format' => 'nullable|in:csv,xlsx,pdf', 'sort' => 'nullable|in:asc,desc'];
    }
}
