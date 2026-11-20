<?php

namespace App\Http\Controllers;

use App\Services\GotongRoyongBonusService;
use App\Services\ScenarioContext;
use Illuminate\Http\Request;

class RegistrationBonusController extends Controller
{
    public function __invoke(Request $request, ScenarioContext $context, GotongRoyongBonusService $bonuses)
    {
        $bonuses->claimRegistration($request->user(), $context->resolve($request));

        return back()->with('status', 'Bonus daftar Rp5.000 sudah masuk ke saldo wallet.');
    }
}
