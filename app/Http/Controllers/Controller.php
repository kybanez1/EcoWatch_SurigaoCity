<?php

namespace App\Http\Controllers;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

abstract class Controller
{
    // Rule used for every photo upload: real image, safe types only, max 5 MB.
    protected const PHOTO_RULE = 'image|mimes:jpg,jpeg,png,webp|max:5120';

    /** Saves an uploaded image into public/uploads/{folder} and returns the path relative to public/. */
    protected function storePhoto(?UploadedFile $file, $folder)
    {
        if (! $file) {
            return null;
        }
        // The extension comes from the real file content, not from the file name the user typed.
        $fileName = Str::random(24) . '.' . $file->extension();
        $file->move(public_path('uploads/' . $folder), $fileName);
        return 'uploads/' . $folder . '/' . $fileName;
    }

    /** Deletes a photo that was uploaded by this system (used when a save fails). */
    protected function deletePhoto($path)
    {
        if ($path && str_starts_with($path, 'uploads/') && file_exists(public_path($path))) {
            unlink(public_path($path));
        }
    }
}
