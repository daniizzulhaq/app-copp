<?php

namespace App\Http\Controllers;

use App\Services\AnalysisReportingService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;

class AnalysisReportController extends Controller
{
    public function __invoke(Request $r, ScenarioContext $context, AnalysisReportingService $service)
    {
        $filters = $r->validate(['type' => ['nullable', Rule::in(array_keys(AnalysisReportingService::TYPES))], 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d|after_or_equal:from', 'format' => 'nullable|in:csv,xlsx,pdf', 'page' => 'nullable|integer|min:1']);
        $s = $context->resolve($r);
        $type = $filters['type'] ?? 'runs';
        $data = $service->dataset($s, $type, $filters);
        if (! empty($filters['format'])) {
            return $service->export($data, $filters['format']);
        }
        $rows = collect($data['rows']);
        $data['rows'] = new LengthAwarePaginator($rows->forPage((int) $r->input('page', 1), 20)->values(), $rows->count(), 20, (int) $r->input('page', 1), ['path' => $r->url(), 'query' => $r->except('page')]);
        $data['types'] = AnalysisReportingService::TYPES;

        return view('reports.analysis', $data);
    }
}
