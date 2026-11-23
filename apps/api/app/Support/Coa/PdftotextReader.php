<?php

namespace App\Support\Coa;

use Illuminate\Process\Exceptions\ProcessTimedOutException as LaravelProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/** The text layer of a PDF through poppler's `pdftotext` (config coa.text_reader). An image, a scan without a text layer or a failing tool give null. */
final class PdftotextReader implements DocumentTextReader
{
    public function read(string $absolutePath, string $mime): ?string
    {
        if ($mime !== 'application/pdf') {
            return null;
        }

        try {
            $result = Process::timeout((int) config('coa.text_reader.timeout_seconds'))
                ->run([(string) config('coa.text_reader.binary'), '-layout', '-enc', 'UTF-8', $absolutePath, '-']);
        } catch (LaravelProcessTimedOutException|ProcessTimedOutException) {
            return null;
        }

        if ($result->failed()) {
            return null;
        }

        $text = mb_substr(trim($result->output()), 0, (int) config('coa.text_reader.max_chars'));

        // Page separators alone (form feeds) are not text.
        return preg_match('/\S/u', str_replace("\f", '', $text)) === 1 ? $text : null;
    }
}
