<?php

namespace App\Domains\Catalog\Actions;

use App\Domains\Audit\Actions\RecordAudit;
use App\Domains\Catalog\Models\Product;
use App\Domains\Catalog\Models\ProductImage;
use App\Support\BusinessException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SaveProductImage
{
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const MAX_BYTES = 5 * 1024 * 1024;

    public function execute(int $productId, UploadedFile $file, int $actorId): ProductImage
    {
        $mime = $file->getMimeType();
        $extension = self::TYPES[$mime] ?? null;
        // Content is verified as a real raster image, not only by its declared type.
        if (! $extension || $file->getSize() > self::MAX_BYTES || ! @getimagesize($file->getRealPath())) {
            throw new BusinessException('PRODUCT_IMAGE_INVALID', 'صورة المنتج يجب أن تكون JPEG أو PNG أو WEBP بحد أقصى 5 ميغابايت.');
        }
        $checksum = hash_file('sha256', $file->getRealPath());
        $name = mb_substr(str_replace(['\\', '/', "\r", "\n"], '_', $file->getClientOriginalName()), 0, 255);
        $path = 'products/'.$productId.'/'.Str::uuid().'.'.$extension;
        Storage::disk('public')->putFileAs(dirname($path), $file, basename($path));
        try {
            [$image, $replacedPath] = DB::transaction(function () use ($productId, $file, $actorId, $path, $checksum, $mime, $name) {
                Product::lockForUpdate()->findOrFail($productId);
                $image = ProductImage::where('product_id', $productId)->lockForUpdate()->first();
                if ($image && $image->checksum === $checksum) {
                    return [$image, null];
                }
                $before = $image?->only(['original_name', 'mime_type', 'size', 'checksum']);
                $previousPath = $image?->stored_path;
                $attributes = ['original_name' => $name, 'stored_path' => $path, 'mime_type' => $mime, 'size' => $file->getSize(), 'checksum' => $checksum, 'uploaded_by' => $actorId];
                if ($image) {
                    $image->forceFill($attributes)->save();
                } else {
                    $image = ProductImage::create([...$attributes, 'product_id' => $productId, 'created_at' => now()]);
                }
                app(RecordAudit::class)->execute('catalog.product_image_saved', 'product', $productId, $before, ['original_name' => $name, 'mime_type' => $mime, 'size' => $file->getSize(), 'checksum' => $checksum], $actorId);

                return [$image, $previousPath];
            }, 3);
        } catch (\Throwable $error) {
            // Keep the stored file when the commit outcome is unknown: an orphan file
            // is preferable to deleting one that committed metadata still points to.
            try {
                if (! ProductImage::where('stored_path', $path)->exists()) {
                    Storage::disk('public')->delete($path);
                }
            } catch (\Throwable) {
            }
            throw $error;
        }
        if ($image->stored_path !== $path) {
            Storage::disk('public')->delete($path);
        } elseif ($replacedPath) {
            Storage::disk('public')->delete($replacedPath);
        }

        return $image;
    }

    public function remove(int $productId, int $actorId): void
    {
        $removed = DB::transaction(function () use ($productId, $actorId) {
            Product::lockForUpdate()->findOrFail($productId);
            $image = ProductImage::where('product_id', $productId)->lockForUpdate()->first();
            if (! $image) {
                return null;
            }
            $image->delete();
            app(RecordAudit::class)->execute('catalog.product_image_removed', 'product', $productId, ['original_name' => $image->original_name, 'checksum' => $image->checksum], [], $actorId);

            return $image;
        }, 3);
        if ($removed) {
            Storage::disk('public')->delete($removed->stored_path);
        }
    }
}
