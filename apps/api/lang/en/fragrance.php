<?php

/*
| The fragrance oil view of a material (L-01): its IFRA certificates and what is still missing. The limits themselves are
| values on a certificate; nothing here is a regulatory number.
*/
return [
    'tab' => 'Fragrance oil',
    'certificate_added' => 'IFRA certificate added.',
    'certificate_removed' => 'IFRA certificate removed.',
    'intro' => 'A fragrance oil is checked against the limits of its own IFRA certificate. Add the certificate, then the limit of each product category from it. A limit takes its source and date from the certificate.',
    'checks_title' => 'What is on file',
    'checks' => [
        'supplier' => 'Supplier',
        'certificate' => 'IFRA certificate',
        'ifra_limits' => 'Limit per category from a certificate',
        'density' => 'Density',
        'flash_point' => 'Flash point',
        'price' => 'Price',
    ],
    'present' => 'On file',
    'missing' => 'Missing',
    'supplier_value' => 'Supplier: {name}',
    'complete' => 'Everything a fragrance oil needs is on file.',
    'incomplete' => 'Some data is missing. A missing value is never read as zero: the checks and the cost stop and say what is missing.',
    'certificates_title' => 'IFRA certificates',
    'add_certificate' => 'Add certificate',
    'add_limit' => 'Add category limit',
    'no_certificates_title' => 'No IFRA certificate yet',
    'no_certificates_description' => 'Without a certificate the IFRA check of this oil stays unknown, and that is not a pass.',
    'no_limits' => 'No category limit has been read from this certificate yet.',
    'columns' => [
        'certificate_number' => 'Certificate',
        'ifra_amendment' => 'Amendment',
        'issuer' => 'Issued by',
        'issued_on' => 'Issued on',
        'source' => 'Document',
        'confidence' => 'Confidence',
        'limits' => 'Category limits',
        'actions' => 'Actions',
    ],
    'form' => [
        'title' => 'Add IFRA certificate',
        'description' => 'The certificate document the limits are read from.',
        'certificate_number' => 'Certificate number',
        'ifra_amendment' => 'IFRA amendment',
        'ifra_amendment_hint' => 'As printed on the certificate. Optional.',
        'issuer' => 'Issued by',
        'issuer_hint' => 'The supplier or lab that issued it. Optional.',
        'issued_on' => 'Issued on',
        'issued_on_hint' => 'The date on the certificate. Limits read from it are valid from this date.',
        'source' => 'Document source',
        'confidence' => 'Confidence',
        'submit' => 'Add certificate',
    ],
    'limit' => [
        'certificate' => 'IFRA certificate',
        'certificate_hint' => 'The certificate this limit is written on. Its source, confidence and date are used.',
        'certificate_none' => 'Add an IFRA certificate to this oil first (Fragrance oil tab).',
        'from_certificate' => 'Source, confidence and date come from the certificate.',
    ],
    'remove_title' => 'Remove this certificate?',
    'remove_description' => 'Certificate {certificate} is removed. The change stays in the audit trail.',
];
