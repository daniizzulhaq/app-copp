<?php

namespace App\Support;

/** Presentation labels only. Stored journals and audit records remain unchanged. */
final class UiText
{
    public static function local(?string $text): string
    {
        $text ??= '';
        $text = str_ireplace([
            'SIMULASI — TIDAK MENGGUNAKAN UANG NYATA',
            'SIMULASI – TIDAK MENGGUNAKAN UANG NYATA',
            'RISET LOKAL — PENCATATAN INTERNAL',
            'RISET LOKAL',
        ], '', $text);
        $text = str_ireplace([
            'LOCAL RESEARCH WORKSPACE',
            'LOCAL RESEARCH PORTFOLIO',
            'LOCAL RESEARCH REPORTS',
            'LOCAL RESEARCH LAB',
            'COPP Luxury Simulation Lab',
            'Riset operasional',
        ], [
            'Investment Workspace',
            'Portofolio',
            'Laporan',
            'COPP Luxury',
            'COPP Luxury',
            'Operasional',
        ], $text);
        $text = preg_replace('/\bsaldo\s+fiktif\b/iu', 'saldo', $text);
        $text = preg_replace('/\bdana\s+fiktif\b/iu', 'nominal internal', $text);
        $text = preg_replace('/\bmodal\s+fiktif\b/iu', 'modal', $text);
        $text = preg_replace('/\bnominal\s+fiktif\b/iu', 'nominal', $text);
        $text = preg_replace('/\bsimulasikan\b/iu', 'Catat', $text);
        $text = preg_replace('/\b(?:simulasi|lokal)\b/iu', '', $text);
        $text = preg_replace('/\bsandbox\b/iu', 'manual', $text);
        $text = preg_replace('/\bdummy\b/iu', 'internal', $text);
        $text = preg_replace('/\bfiktif\b/iu', 'internal', $text);
        $text = preg_replace('/\bPaket\b/u', 'Produk Investasi', $text);
        $text = preg_replace('/\bpaket\b/u', 'produk investasi', $text);

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $text));
    }
}
