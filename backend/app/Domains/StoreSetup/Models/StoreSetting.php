<?php

namespace App\Domains\StoreSetup\Models;

use App\Support\Tenancy\CompanySingleton;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StoreSetting extends Model
{
    use CompanySingleton;

    protected $guarded = ['id'];

    protected $hidden = ['logo_path'];

    protected $appends = ['logo_url'];

    public function logoUrl(): ?string
    {
        if (! $this->logo_path) {
            return null;
        }
        $url = Storage::disk('public')->url($this->logo_path);

        return parse_url($url, PHP_URL_HOST) === null ? $url : (string) parse_url($url, PHP_URL_PATH);
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logoUrl();
    }

    protected function casts(): array
    {
        return ['vat_registered' => 'boolean', 'accounting_configured_at' => 'datetime', 'setup_completed_at' => 'datetime'];
    }
}
