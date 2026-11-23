<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** What a lab trial can measure. Units and protocol templates per property live in config/lab.php. */
enum LabProperty: string
{
    use HasOptions;

    case Ph = 'ph';
    case Density = 'density';
    case Viscosity = 'viscosity';
    case CloudPoint = 'cloud_point';
    case Foam = 'foam';
    case Stability4w = 'stability_4w';
    /* Quick emulsion stability checks (M-07): the separated phase after the centrifuge and after the thermal cycles. */
    case CentrifugeSeparation = 'centrifuge_separation';
    case ThermalCycleSeparation = 'thermal_cycle_separation';
    case ActiveMatter = 'active_matter';
    /* Fragrance process losses (L-04): % of the mass entering the stage, measured on a trial batch. */
    case AgeingLoss = 'ageing_loss';
    case ChillingLoss = 'chilling_loss';
    case FiltrationLoss = 'filtration_loss';

    /** @return list<string> The units a value of this property may be stored in. */
    public function units(): array
    {
        return config("lab.measurements.{$this->value}.units", []);
    }

    /** The protocol template code that describes how it is measured, or null when none exists yet. */
    public function protocolCode(): ?string
    {
        return config("lab.measurements.{$this->value}.protocol");
    }

    /** @return array{min: string|null, max: string|null} Acceptance limits; null means no limit defined. */
    public function limits(): array
    {
        return config("lab.acceptance_limits.{$this->value}", ['min' => null, 'max' => null]);
    }
}
