<?php

return [
    'command' => [
        'created' => 'Backup :file written (:bytes bytes, encrypted).',
        'sha256' => 'SHA-256: :hash',
        'pruned' => 'Old backups pruned: :count.',
        'verified' => 'Backup :file verified: manifest signature, SHA-256 and decryption are all good.',
        'restored' => 'Backup :file restored into database :database.',
        'into_required' => 'Give the name of a new scratch database with --into=<name>.',
        'table' => 'Table',
        'expected' => 'Manifest',
        'actual' => 'Restored',
        'result' => 'Result',
        'match' => 'match',
        'differs' => 'differs',
    ],
];
