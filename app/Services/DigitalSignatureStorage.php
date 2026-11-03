<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DigitalSignatureStorage
{
    public static function store(Request $request, string $field): ?string
    {
        if (! $request->hasFile($field) && ! $request->filled($field)) {
            return null;
        }

        if ($request->hasFile($field)) {
            $request->validate([$field => 'required|image|mimes:png,jpg,jpeg|max:2048']);
            $data = file_get_contents($request->file($field)->getRealPath());
        } else {
            $value = $request->input($field);
            if (! is_string($value) || strlen($value) > 2800000 || ! preg_match('/^data:image\/(png|jpeg|jpg);base64,(.*)$/s', $value, $matches)) {
                throw ValidationException::withMessages([$field => 'Chữ ký phải là ảnh PNG hoặc JPEG, tối đa 2 MB.']);
            }
            $data = base64_decode(str_replace(' ', '+', $matches[2]), true);
        }

        $info = is_string($data) && strlen($data) <= 2097152 ? @getimagesizefromstring($data) : false;
        $extension = $info ? ([IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg'][$info[2]] ?? null) : null;
        if (! $extension) {
            throw ValidationException::withMessages([$field => 'Chữ ký phải là ảnh PNG hoặc JPEG hợp lệ, tối đa 2 MB.']);
        }

        $path = 'digital_signatures/'.Str::uuid().'.'.$extension;
        if (! Storage::disk('public')->put($path, $data)) {
            throw new \RuntimeException('Không thể lưu chữ ký.');
        }

        return $path;
    }
}
