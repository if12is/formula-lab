<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\PackagingBomFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The packaging BOM of one SKU (S-12): its packing configuration, the pack image (a file on a private disk) and its lines.
 * sanad_product_uuid is a soft reference to the finished good in Sanad, which owns the product.
 */
class PackagingBom extends Model
{
    /** @use HasFactory<PackagingBomFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = [
        'tenant_id', 'name', 'sku_code', 'sanad_product_uuid', 'units_per_carton', 'cartons_per_pallet',
        'image_path', 'image_name', 'image_mime', 'image_size', 'image_sha256', 'note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['units_per_carton' => 'integer', 'cartons_per_pallet' => 'integer', 'image_size' => 'integer'];
    }

    /** @return HasMany<PackagingBomLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PackagingBomLine::class)->orderBy('position')->orderBy('created_at')->orderBy('id');
    }

    public function hasImage(): bool
    {
        return $this->image_path !== null;
    }
}
