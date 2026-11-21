<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ServeMockSanadCommand extends Command
{
    protected $signature = 'sanad:mock-serve {--port= : Port to listen on} {--host=127.0.0.1}';

    protected $description = 'Serve the app with the Sanad mock (/__mock/sanad/api/v1/manufacturing/*) for local dev and e2e';

    public function handle(): int
    {
        if (! app()->environment('local', 'testing')) {
            return self::FAILURE;
        }

        $host = (string) $this->option('host');
        $port = (string) ($this->option('port') ?: config('sanad.mock.serve_port'));

        $this->info(__('sanad.command.mock_url', [
            'url' => "http://{$host}:{$port}/__mock/sanad",
            'token' => config('sanad.mock.token'),
        ]));

        return $this->call('serve', ['--host' => $host, '--port' => $port]);
    }
}
