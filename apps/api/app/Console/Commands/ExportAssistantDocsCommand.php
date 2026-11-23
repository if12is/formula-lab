<?php

namespace App\Console\Commands;

use App\Support\Assistant\AssistantDocsExporter;
use Illuminate\Console\Command;

class ExportAssistantDocsCommand extends Command
{
    protected $signature = 'assistant:export-docs';

    protected $description = 'Write plan/04-ai-haiku/system-prompt.md and tools.json from the assistant code (the code is the source).';

    public function handle(AssistantDocsExporter $exporter): int
    {
        foreach ($exporter->render() as $file => $content) {
            file_put_contents(AssistantDocsExporter::directory().'/'.$file, $content);
            $this->components->info($file);
        }

        return self::SUCCESS;
    }
}
