<?php

namespace App\Models;

abstract class ImmutableRecord extends Record
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Histori audit dan ledger bersifat permanen. Gunakan transaksi pembalik.'));
        static::deleting(fn () => throw new \LogicException('Histori audit dan ledger tidak dapat dihapus.'));
    }
}
