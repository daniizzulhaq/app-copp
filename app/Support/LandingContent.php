<?php

namespace App\Support;

use App\Models\SystemSetting;

class LandingContent
{
    public static function get(): array
    {
        $saved = json_decode(SystemSetting::whereKey('landing_content')->value('value') ?? '{}', true);
        $content = config('landing');
        foreach ($content as $section => &$fields) {
            foreach ($fields as $key => &$value) {
                if (isset($saved[$section][$key]) && is_string($saved[$section][$key])) {
                    $value = $saved[$section][$key];
                }
            }
        }

        return $content;
    }
}
