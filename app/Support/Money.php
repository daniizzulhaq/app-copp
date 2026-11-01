<?php

namespace App\Support;

final class Money
{
    public static function rupiah(int|string|null $amount): string
    {
        $digits = (string) (int) $amount;
        $negative = str_starts_with($digits, '-');
        if ($negative) {
            $digits = substr($digits, 1);
        }

        return 'Rp'.($negative ? '-' : '').preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $digits);
    }

    public static function rate(int $principal, int $basisPoints, int $days = 1): int
    {
        return intdiv($principal * $basisPoints * $days, 10000);
    }
}
