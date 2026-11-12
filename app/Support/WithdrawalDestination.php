<?php

namespace App\Support;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class WithdrawalDestination
{
    public const FIELDS = ['bank_name', 'bank_account_number', 'bank_account_holder'];

    public static function groups(): array
    {
        return [
            'E-WALLET POPULER' => ['DANA', 'OVO', 'GoPay', 'ShopeePay', 'LinkAja'],
            'BANK NASIONAL & SWASTA' => [
                'Bank BCA', 'Bank BRI', 'Bank Mandiri', 'Bank BNI', 'Bank CIMB Niaga',
                'Bank BTN', 'Bank BSI', 'Bank Permata', 'Bank Danamon', 'Bank Maybank',
                'Bank OCBC', 'Bank Panin', 'Bank Mega', 'Bank BTPN', 'Bank Jago', 'SeaBank',
            ],
        ];
    }

    public static function providers(): array
    {
        return array_merge(...array_values(self::groups()));
    }

    public static function isWallet(?string $provider): bool
    {
        return in_array($provider, self::groups()['E-WALLET POPULER'], true);
    }

    public static function normalize(array $data): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));
        foreach ($data as $field => $value) {
            if (is_string($value)) {
                $data[$field] = $field === 'bank_account_number'
                    ? preg_replace('/[\s-]+/u', '', $value) : trim($value);
            }
        }

        return $data;
    }

    public static function rules(?string $savedProvider = null): array
    {
        $providers = self::providers();
        // Keep previously saved bank names usable without rewriting old accounts.
        if (filled($savedProvider)) {
            $providers[] = $savedProvider;
        }

        return [
            'bank_name' => ['required', 'string', 'max:100', Rule::in($providers)],
            'bank_account_number' => ['required', 'string', 'regex:/^[0-9]{5,34}$/'],
            'bank_account_holder' => ['required', 'string', 'max:150'],
        ];
    }

    public static function messages(): array
    {
        return [
            'bank_name.required' => 'Pilih bank atau e-wallet tujuan WD.',
            'bank_name.in' => 'Pilih bank atau e-wallet dari daftar yang tersedia.',
            'bank_account_number.required' => 'Isi nomor rekening atau nomor HP e-wallet tujuan.',
            'bank_account_number.regex' => 'Nomor rekening atau nomor HP e-wallet harus berisi 5–34 digit angka.',
            'bank_account_holder.required' => 'Isi nama pemilik rekening atau e-wallet.',
        ];
    }

    public static function validate(array $data, ?string $savedProvider = null): array
    {
        return Validator::make(self::normalize($data), self::rules($savedProvider), self::messages())->validate();
    }
}
