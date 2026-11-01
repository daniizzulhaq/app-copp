<?php

namespace App\Models;

class SystemSetting extends Record
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public static function depositAccount(): array
    {
        $values = static::whereIn('key', ['deposit_bank_name', 'deposit_account_number', 'deposit_account_holder'])->pluck('value', 'key');

        return [
            'bank' => $values->get('deposit_bank_name'),
            'number' => $values->get('deposit_account_number'),
            'holder' => $values->get('deposit_account_holder'),
        ];
    }
}
