<?php

namespace App\Models;

use App\Enums\ExportKind;
use App\Enums\PrintWatermark;
use App\Exceptions\Domain\ImmutableRecordException;
use Database\Factories\ExportLogFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One printed or saved-as-PDF document: who, what, when and the hash printed on it. Append-only. */
class ExportLog extends Model
{
    /** @use HasFactory<ExportLogFactory> */
    use HasFactory, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'user_id', 'kind', 'subject_label', 'subject_id', 'formula_id', 'formula_version_id', 'content_hash', 'watermark', 'exported_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw ImmutableRecordException::appendOnly('export_logs'));
        static::deleting(fn () => throw ImmutableRecordException::appendOnly('export_logs'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ExportKind::class,
            'watermark' => PrintWatermark::class,
            'exported_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Formula, $this> */
    public function formula(): BelongsTo
    {
        return $this->belongsTo(Formula::class);
    }

    /** @return BelongsTo<FormulaVersion, $this> */
    public function formulaVersion(): BelongsTo
    {
        return $this->belongsTo(FormulaVersion::class);
    }
}
