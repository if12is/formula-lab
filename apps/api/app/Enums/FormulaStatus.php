<?php

namespace App\Enums;

enum FormulaStatus: string
{
    case Draft = 'draft';
    case LabTesting = 'lab_testing';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
