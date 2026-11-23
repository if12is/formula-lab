<?php

namespace App\Console\Commands;

use App\Services\Assistant\AssistantConsentService;
use Illuminate\Console\Command;

class ExpireAssistantConsentsCommand extends Command
{
    protected $signature = 'assistant:expire-consents';

    protected $description = 'Close the assistant privacy consents whose allowed time ran out (a closing row is appended to the consent log).';

    public function handle(AssistantConsentService $consent): int
    {
        $this->components->info(__('assistant_ui.consent.expired_count', ['count' => $consent->expireStale()]));

        return self::SUCCESS;
    }
}
