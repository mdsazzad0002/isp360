<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

// Stores uploaded files safely (audit C3). The extension comes from the file's content, never from
// the name the client sent, and must be on an allow-list, so "photo.php" holding a JPEG is saved as
// .jpg and a script can't land under public/. Names are random. Anything that is not a public
// picture (ticket attachments, documents) goes to the private disk and is served by a controller.
class Upload
{
    public const IMAGES = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    public const ICONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'ico'];
    public const DOCUMENTS = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];

    public const PRIVATE_DISK = 'local';

    // Validation rule for an optional upload field of this kind (mimes also checks the content).
    public static function rule(array $allowed = self::IMAGES, int $maxKb = 2048): string
    {
        return 'nullable|file|mimes:' . implode(',', $allowed) . '|max:' . $maxKb;
    }

    // The safe extension of $file, or an exception when its content isn't one of $allowed.
    public static function extension(UploadedFile $file, array $allowed): string
    {
        $extension = strtolower((string) $file->guessExtension());
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        if ($extension === '' || ! in_array($extension, $allowed, true)) {
            throw new RuntimeException('This file type is not allowed.');
        }
        return $extension;
    }

    // Saves under public/$directory and returns the relative path ("uploads/user/U1_x.jpg").
    public static function toPublic(UploadedFile $file, string $directory, string $prefix, array $allowed = self::IMAGES): string
    {
        $extension = self::extension($file, $allowed);
        $prefix = preg_replace('/[^A-Za-z0-9_-]/', '', $prefix) ?: 'file';
        $name = $prefix . '_' . Str::random(24) . '.' . $extension;
        $file->move(public_path($directory), $name);
        return trim($directory, '/') . '/' . $name;
    }

    // Saves on the private disk and returns its path there ("tickets/3/x.pdf").
    public static function toPrivate(UploadedFile $file, string $directory, array $allowed = self::DOCUMENTS): string
    {
        $extension = self::extension($file, $allowed);
        $path = $file->storeAs(trim($directory, '/'), Str::random(40) . '.' . $extension, self::PRIVATE_DISK);
        if (! $path) {
            throw new RuntimeException('The file could not be saved.');
        }
        return $path;
    }

    public static function privateExists(?string $path): bool
    {
        return $path && Storage::disk(self::PRIVATE_DISK)->exists($path);
    }
}
