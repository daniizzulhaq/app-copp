<?php

namespace App\Http\Controllers;

use App\Http\Requests\PackageRequest;
use App\Models\RoiPackage;
use App\Services\AuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class PackageController extends Controller
{
    public function index(Request $r)
    {
        $r->validate(['q' => 'nullable|string|max:100']);
        $packages = RoiPackage::when($r->q, fn ($q) => $q->where('name', 'like', '%'.$r->q.'%'))->orderBy('minimum_amount')->paginate(12)->withQueryString();

        return view('packages.index', compact('packages'));
    }

    public function create()
    {
        Gate::authorize('manage');

        return view('packages.form', ['package' => new RoiPackage(['active' => true, 'purchase_limit' => 3, 'fixed_amount' => true, 'total_roi_bps' => 21000, 'daily_rate_bps' => 500, 'duration_days' => 42, 'minimum_amount' => 100000])]);
    }

    public function store(PackageRequest $r)
    {
        DB::transaction(function () use ($r) {
            $p = RoiPackage::create($r->validated());
            app(AuditService::class)->record('package.created', $p, null, $p->toArray());
        });

        return redirect('/packages')->with('status', 'Produk Investasi dibuat.');
    }

    public function edit(RoiPackage $package)
    {
        Gate::authorize('manage');

        return view('packages.form', compact('package'));
    }

    public function update(PackageRequest $r, RoiPackage $package)
    {
        DB::transaction(function () use ($r, $package) {
            $before = $package->toArray();
            $package->update($r->validated());
            app(AuditService::class)->record('package.updated', $package, $before, $package->toArray());
        });

        return redirect('/packages')->with('status', 'Produk Investasi diperbarui. Snapshot portofolio tetap tersimpan.');
    }

    public function archive(RoiPackage $package)
    {
        Gate::authorize('manage');
        DB::transaction(function () use ($package) {
            $package->update(['active' => false]);
            app(AuditService::class)->record('package.archived', $package);
        });

        return back()->with('status', 'Produk Investasi diarsipkan.');
    }
}
