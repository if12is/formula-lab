<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * How far a formula template has been proven (K-09): theoretical (structure only), lab tested (a trial held), or approved
 * by a chemist (named, dated). Only the shipped data states it; no screen or action promotes a template.
 */
enum TemplateVerification: string
{
    use HasOptions;

    case Theoretical = 'theoretical';
    case LabTested = 'lab_tested';
    case ChemistApproved = 'chemist_approved';

    public function label(): string
    {
        return __("families.verification.{$this->value}");
    }
}
