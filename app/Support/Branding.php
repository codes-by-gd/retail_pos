<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/** The shop logo (Settings → Business). Stored on the public disk; prints and PDFs embed it as a data URI. */
final class Branding
{
    public static function logoPath(): ?string
    {
        $path = settings('business.logo');

        return $path && Storage::disk('public')->exists($path) ? $path : null;
    }

    public static function logoUrl(): ?string
    {
        $path = self::logoPath();

        return $path ? Storage::disk('public')->url($path).'?v='.substr(md5($path), 0, 8) : null;
    }

    /** data:image/…;base64,… — works in print windows, thermal receipts and dompdf without URLs. */
    public static function logoDataUri(): ?string
    {
        $path = self::logoPath();
        if (! $path) {
            return null;
        }

        return Cache::rememberForever('branding.logo.'.md5($path), function () use ($path) {
            $mime = Storage::disk('public')->mimeType($path) ?: 'image/png';

            return "data:{$mime};base64,".base64_encode(Storage::disk('public')->get($path));
        });
    }
}
