<?php

namespace App\Models;

use Database\Factories\DatasetVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A published reference-data snapshot; the id is the SHA-256 of its canonical JSON. */
class DatasetVersion extends Model
{
    /** @use HasFactory<DatasetVersionFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'notes', 'manifest'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['manifest' => 'array', 'created_at' => 'datetime'];
    }
}
