<?php

namespace App\Models;

use Database\Factories\SanadSupplierFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** READ-ONLY CACHE of a Sanad Supplier. Financial fields are deliberately not mirrored. */
class SanadSupplier extends Model
{
    /** @use HasFactory<SanadSupplierFactory> */
    use HasFactory;

    protected $table = 'sanad_suppliers_cache';

    protected $primaryKey = 'uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'uuid', 'tenant_id', 'code', 'name', 'name_en', 'tax_number', 'payment_terms',
        'is_active', 'updated_at', 'deleted', 'synced_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'payment_terms' => 'integer',
            'is_active' => 'boolean',
            'updated_at' => 'datetime',
            'deleted' => 'boolean',
            'synced_at' => 'datetime',
        ];
    }
}
