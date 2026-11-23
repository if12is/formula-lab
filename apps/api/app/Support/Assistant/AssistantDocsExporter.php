<?php

namespace App\Support\Assistant;

use JsonException;

/**
 * The plan files that mirror the assistant (plan/04-ai-haiku): `system-prompt.md` is the system prompt and `tools.json` the tool
 * definitions. The code is the source: lang/ar/assistant.php for the prompt, each tool class for its schema. A tool of the plan that
 * is not built yet (soap_lye, extract_coa...) is kept as the plan wrote it. `php artisan assistant:export-docs` writes them and a test
 * fails when they have drifted.
 */
final class AssistantDocsExporter
{
    public function __construct(private readonly AssistantToolRegistry $tools) {}

    public static function directory(): string
    {
        return base_path('../../plan/04-ai-haiku');
    }

    /** @return array<string, string> File name => the content it must have. */
    public function render(): array
    {
        return [
            'system-prompt.md' => AssistantPrompt::system()."\n",
            'tools.json' => $this->toolsJson(),
        ];
    }

    private function toolsJson(): string
    {
        $path = self::directory().'/tools.json';
        try {
            /** @var list<array<string, mixed>> $planned */
            $planned = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        } catch (JsonException) {
            $planned = [];
        }

        $built = [];
        foreach ($this->tools->definitions() as $definition) {
            $built[$definition['name']] = $definition;
        }

        $plannedNames = array_map(fn (array $entry): string => (string) $entry['name'], $planned);
        $order = array_keys($built);

        $merged = [];
        foreach ($planned as $entry) {
            $name = (string) $entry['name'];
            $merged[] = $built[$name] ?? $entry;

            // A tool the plan did not list yet follows the built tool before it, so the built ones stay together.
            $position = array_search($name, $order, true);
            while ($position !== false && isset($order[$position + 1]) && ! in_array($order[$position + 1], $plannedNames, true)) {
                $merged[] = $built[$order[++$position]];
            }
        }
        foreach ($built as $name => $definition) {
            if (! in_array($name, $plannedNames, true) && ! in_array($definition, $merged, true)) {
                $merged[] = $definition;
            }
        }

        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // The plan file indents with one space per level.
        return (string) preg_replace_callback('/^( {4})+/m', fn (array $m): string => str_repeat(' ', intdiv(strlen($m[0]), 4)), $json)."\n";
    }
}
