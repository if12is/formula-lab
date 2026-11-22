<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Enums\LabProperty;
use Database\Factories\LabProtocolFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** A simple test protocol template (steps, the kind of instrument, the unit); reference data seeded from config/lab.php. */
class LabProtocol extends Model
{
    /** @use HasFactory<LabProtocolFactory> */
    use Auditable, HasFactory, HasUuids;

    protected $fillable = ['code', 'property', 'unit', 'instrument', 'steps', 'position'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['property' => LabProperty::class, 'steps' => 'array', 'position' => 'integer'];
    }
}
