<?php

namespace App\Models;

use Database\Factories\CalcRunFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Record of one engine calculation: hashes, engine version and the dataset it used. */
class CalcRun extends Model
{
    /** @use HasFactory<CalcRunFactory> */
    use HasFactory, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'input_hash', 'output_hash', 'engine_version', 'dataset_version'];

    /** @return BelongsTo<DatasetVersion, $this> */
    public function datasetVersion(): BelongsTo
    {
        return $this->belongsTo(DatasetVersion::class, 'dataset_version');
    }
}
