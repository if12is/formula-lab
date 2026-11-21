<?php

namespace App\Enums;

/** Confidence level of a stored value (plan/00-research/02-interim-data-sources.md). */
enum Confidence: string
{
    case L1CoaBatch = 'L1_coa_batch';
    case L2SupplierTds = 'L2_supplier_tds';
    case L3Standard = 'L3_standard';
    case L4RegulatoryDb = 'L4_regulatory_db';
    case L5Reference = 'L5_reference';
    case L6Community = 'L6_community';
    case L7Estimate = 'L7_estimate';
}
