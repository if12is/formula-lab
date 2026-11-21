<?php

namespace Database\Seeders;

use App\Models\LabProtocol;
use Illuminate\Database\Seeder;

/** Loads the test protocol templates of config/lab.php (O-06) into lab_protocols. Idempotent: a protocol is matched by its code. */
class LabProtocolSeeder extends Seeder
{
    public function run(): void
    {
        $position = 0;

        foreach ((array) config('lab.protocols') as $code => $protocol) {
            LabProtocol::query()->updateOrCreate(['code' => $code], [
                'property' => $protocol['property'],
                'unit' => $protocol['unit'],
                'instrument' => $protocol['instrument'],
                'steps' => $protocol['steps'],
                'position' => $position++,
            ]);
        }
    }
}
