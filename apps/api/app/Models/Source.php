<?php

namespace App\Models;

use App\Concerns\Auditable;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['tenant_id', 'kind', 'title', 'url', 'license', 'retrieved_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['retrieved_at' => 'date:Y-m-d'];
    }
}
