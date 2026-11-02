<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use App\Services\AuditService;
use App\Support\LandingContent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LandingContentController extends Controller
{
    public function show()
    {
        return view('landing', ['content' => LandingContent::get()]);
    }

    public function edit()
    {
        return view('settings.landing-content', ['content' => LandingContent::get()]);
    }

    public function update(Request $request)
    {
        $defaults = config('landing');
        $rules = ['content' => ['required', 'array:'.implode(',', array_keys($defaults)), function ($attribute, $value, $fail) {
            if (strlen(json_encode($value, JSON_UNESCAPED_UNICODE)) > 60000) {
                $fail('Total konten terlalu panjang. Ringkas isi artikel sebelum menyimpan.');
            }
        }]];
        foreach ($defaults as $section => $fields) {
            $rules['content.'.$section] = 'required|array:'.implode(',', array_keys($fields));
            foreach ($fields as $key => $value) {
                $rules['content.'.$section.'.'.$key] = 'required|string|max:'.self::maxLength($key);
            }
        }
        $data = $request->validate($rules);
        DB::transaction(function () use ($data) {
            $before = LandingContent::get();
            SystemSetting::updateOrCreate(['key' => 'landing_content'], ['value' => json_encode($data['content'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
            app(AuditService::class)->record('landing-content.updated', null, $before, $data['content']);
        });

        return back()->with('status', 'Artikel landing page disimpan dan langsung tampil di halaman awal.');
    }

    public static function maxLength(string $key): int
    {
        return str_starts_with($key, 'isi_') || str_starts_with($key, 'jawaban_') || str_starts_with($key, 'deskripsi') || $key === 'catatan' ? 2000 : 250;
    }
}
