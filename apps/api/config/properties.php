<?php

/*
| The registry of material property keys: the one list the material page, the property form and the calculators read.
| `units` are the units a value of the key may be stored in (an empty list is never used: a dimensionless quantity still
| carries its own label, e.g. HLB or pH). `label_key` is the lang key that names the property. A key is a code, so a
| new property is added here and in lang/{ar,en}/properties.php (tests/Feature/Materials/PropertyRegistryTest.php).
*/
return [
    'density' => ['units' => ['g/mL', 'kg/L'], 'label_key' => 'properties.density'],
    'active_matter' => ['units' => ['%w/w'], 'label_key' => 'properties.active_matter'],
    'purity' => ['units' => ['%w/w'], 'label_key' => 'properties.purity'],
    'acid_value' => ['units' => ['mgKOH/g'], 'label_key' => 'properties.acid_value'],
    'sap' => ['units' => ['mgKOH/g', 'gNaOH/g'], 'label_key' => 'properties.sap'],
    'sap_range' => ['units' => ['mgKOH/g', 'gNaOH/g'], 'label_key' => 'properties.sap_range'],
    'required_hlb' => ['units' => ['HLB'], 'label_key' => 'properties.required_hlb'],
    'hlb' => ['units' => ['HLB'], 'label_key' => 'properties.hlb'],
    'ethanol_strength' => ['units' => ['%v/v', '%w/w'], 'label_key' => 'properties.ethanol_strength'],
    'available_chlorine' => ['units' => ['%w/w'], 'label_key' => 'properties.available_chlorine'],
    'ph' => ['units' => ['pH'], 'label_key' => 'properties.ph'],
    'viscosity' => ['units' => ['mPa·s'], 'label_key' => 'properties.viscosity'],
    /* A fragrance oil's flash point, from its safety data sheet (L-01). */
    'flash_point' => ['units' => ['°C'], 'label_key' => 'properties.flash_point'],
    'ifra_limit' => ['units' => ['%w/w'], 'label_key' => 'properties.ifra_limit'],
];
