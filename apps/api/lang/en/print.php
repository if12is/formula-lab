<?php

/*
| The print preview shell (S-17): every printable page is shown as a paper copy first, stamped with a watermark and the hash of the
| version it was made from, and each print is written to the export log (U-03).
*/
return [
    'preview' => 'Print preview',
    'preview_hint' => 'This is the copy that will be printed or saved as PDF, with its watermark and hash.',
    'print' => 'Print',
    'printing' => 'Logging the print…',
    'close' => 'Back to the page',
    'logged' => 'The print was written to the export log.',
    'log_failed' => 'The print was not logged, so it was not sent to the printer. Try again.',
    'save_pdf_hint' => 'To get a PDF, choose "Save as PDF" in the print window.',
    'document' => 'Document',
    'subject' => 'Concerns',
    'hash' => 'Hash',
    'hash_label_version' => 'Formula-version hash',
    'hash_label_trace' => 'Content hash',
    'printed_at' => 'Printed on',
    'reference' => 'Export reference',
    'not_printed_yet' => 'Not printed yet: the reference and time are added when you print.',
    'unlogged' => 'No export reference: this copy was not printed from the print preview.',
    'footer' => 'Check the hash against the system before relying on a paper copy.',
    'watermark' => [
        'approved' => 'Printed copy',
        'unapproved' => 'Draft - not approved',
        'neutral' => 'Printed copy',
    ],
];
