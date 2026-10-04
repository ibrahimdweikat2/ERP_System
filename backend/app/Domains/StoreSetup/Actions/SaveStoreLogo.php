<?php

namespace App\Domains\StoreSetup\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\StoreSetup\Models\StoreSetting;
use App\Support\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SaveStoreLogo
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MAX_BYTES = 2 * 1024 * 1024;

    public function execute(UploadedFile $file, int $actorId): StoreSetting
    {
        $mime = $file->getMimeType();
        $extension = self::TYPES[$mime] ?? null;
        // Content is verified as a real raster image, not only by its declared type.
        if (! $extension || $file->getSize() > self::MAX_BYTES || ! @getimagesize($file->getRealPath())) {
            throw new BusinessException('STORE_LOGO_INVALID', 'الشعار يجب أن يكون JPEG أو PNG أو WEBP بحد أقصى 2 ميغابايت.');
        }
        $path = 'branding/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
        try {
            [$store, $previous] = DB::transaction(function () use ($path, $actorId) {
                $store = StoreSetting::lockCurrent();
                $previous = $store->logo_path;
                $store->forceFill(['logo_path' => $path])->save();
                app(RecordAudit::class)->execute('store.logo_saved', 'store_setting', $store->id, ['logo_path' => $previous], ['logo_path' => $path], $actorId);

                return [$store, $previous];
            }, 3);
        } catch (\Throwable $error) {
            try {
                if (StoreSetting::where('logo_path', $path)->doesntExist()) {
                    Storage::disk('public')->delete($path);
                }
            } catch (\Throwable) {
            }
            throw $error;
        }
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }

        return $store;
    }

    public function remove(int $actorId): void
    {
        $previous = DB::transaction(function () use ($actorId) {
            $store = StoreSetting::lockCurrent();
            $previous = $store->logo_path;
            if ($previous) {
                $store->forceFill(['logo_path' => null])->save();
                app(RecordAudit::class)->execute('store.logo_removed', 'store_setting', $store->id, ['logo_path' => $previous], [], $actorId);
            }

            return $previous;
        }, 3);
        if ($previous) {
            Storage::disk('public')->delete($previous);
        }
    }
}
