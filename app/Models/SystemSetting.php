<?php

namespace App\Models;

class SystemSetting extends Record
{
    public const QRIS_METHODS = ['qris_dana' => 'QRIS DANA', 'qris_gopay' => 'QRIS GoPay'];

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

    public static function qrisMethods(): array
    {
        $values = static::where('key', 'like', 'deposit_qris_%')->pluck('value', 'key');
        $methods = [];
        foreach (self::QRIS_METHODS as $key => $label) {
            $methods[$key] = [
                'label' => $label,
                'enabled' => $values->get('deposit_'.$key.'_enabled') === '1',
                'holder' => $values->get('deposit_'.$key.'_holder', ''),
                'image' => $values->get('deposit_'.$key.'_image', ''),
            ];
        }

        return $methods;
    }
}
