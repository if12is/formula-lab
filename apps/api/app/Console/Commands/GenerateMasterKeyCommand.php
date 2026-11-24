<?php

namespace App\Console\Commands;

use App\Support\Crypto\Aead;
use Illuminate\Console\Command;

class GenerateMasterKeyCommand extends Command
{
    protected $signature = 'crypto:generate-master-key';

    protected $description = 'Print a new random master key for APP_MASTER_KEY (it is never written to a file)';

    public function handle(): int
    {
        $this->line(__('crypto.command.master_key_generated'));
        $this->line('APP_MASTER_KEY=base64:'.base64_encode(Aead::generateKey()));

        return self::SUCCESS;
    }
}
