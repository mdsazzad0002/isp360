<?php

namespace App\Support;

use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Generates the favicon/PWA icon set (16/32/180/192/512) from a single
 * source image — normally the company logo, so a business only has to
 * upload one image and every icon size the browser/OS asks for (tab
 * favicon, Apple touch icon, PWA install icon) stays in sync with it
 * automatically. Runs on every logo save; a manually uploaded favicon
 * (if the admin wants a distinct icon from the logo) can be passed in
 * directly instead and takes the same path.
 */
class FaviconGenerator
{
    private const SIZES = [16, 32, 180, 192, 512];

    /**
     * @param string $sourcePath absolute filesystem path to the source image
     * @return array<string, string> size => path relative to public/, e.g. "192" => "uploads/favicon/xyz-192.png"
     */
    public static function generate(string $sourcePath): array
    {
        $manager = new ImageManager(new Driver());
        $source = $manager->read($sourcePath);

        $directory = 'uploads/favicon';
        $absoluteDirectory = public_path($directory);
        if (! is_dir($absoluteDirectory)) {
            mkdir($absoluteDirectory, 0755, true);
        }

        $baseName = 'favicon_' . Str::random(10);
        $paths = [];

        foreach (self::SIZES as $size) {
            $resized = (clone $source)->cover($size, $size);
            $relativePath = $directory . '/' . $baseName . '-' . $size . '.png';
            $resized->toPng()->save(public_path($relativePath));
            $paths[(string) $size] = $relativePath;
        }

        return $paths;
    }

    /**
     * Deletes every size file a previous generate() call produced, given
     * its returned path map — called before regenerating so old icon
     * files don't pile up on disk with each logo change.
     */
    public static function delete(?array $sizes): void
    {
        if (! $sizes) {
            return;
        }

        foreach ($sizes as $path) {
            $fullPath = public_path($path);
            if (is_file($fullPath)) {
                @unlink($fullPath);
            }
        }
    }
}
