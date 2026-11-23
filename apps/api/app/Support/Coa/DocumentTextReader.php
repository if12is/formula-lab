<?php

namespace App\Support\Coa;

/** Reads the text layer of an uploaded document, so the numbers the model returns can be checked against it. Tests bind a fake. */
interface DocumentTextReader
{
    /** @return string|null The text, or null when the document has none (a scan, an image) or it cannot be read. */
    public function read(string $absolutePath, string $mime): ?string;
}
