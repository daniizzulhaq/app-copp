<?php

namespace App\Http\Controllers;

use App\Models\RoiObligation;
use App\Services\DailyRoiClaimService;
use Illuminate\Http\Request;

class DailyRoiClaimController extends Controller
{
    public function __invoke(Request $request, RoiObligation $obligation, DailyRoiClaimService $service)
    {
        $service->claim($request->user(), $obligation);

        return back()->with('status', 'ROI hari ini sudah diklaim dan masuk ke saldo wallet.');
    }
}
