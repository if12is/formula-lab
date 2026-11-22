<?php

namespace App\Support\Families;

use JsonException;
use RuntimeException;

/**
 * The one reader of the product family data files (config/families.php). The vocabulary (which calculations and measured
 * properties exist, what a template line role can be) is part of the registry file, so the database rows and the screens
 * resolve it from here and never keep a second list.
 */
final class FamilyData
{
    /** @var array<string, mixed>|null */
    private ?array $registry = null;

    /** @return array<string, mixed> */
    public function registry(): array
    {
        return $this->registry ??= $this->readJson((string) config('families.registry_path'));
    }

    /** @return list<array<string, mixed>> The template files' templates, in file order. */
    public function templates(): array
    {
        $templates = [];

        foreach (glob((string) config('families.templates_glob')) ?: [] as $file) {
            $document = $this->readJson($file);

            foreach ($document['templates'] ?? [] as $template) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /** @return list<array{id: string, status: string, calculatorKey: string|null}> */
    public function calculations(): array
    {
        return $this->registry()['vocabulary']['calculations'];
    }

    /** @return array{id: string, status: string, calculatorKey: string|null}|null */
    public function calculation(string $id): ?array
    {
        foreach ($this->calculations() as $entry) {
            if ($entry['id'] === $id) {
                return $entry;
            }
        }

        return null;
    }

    /** @return list<array{id: string, labProperty: string|null}> */
    public function measuredProperties(): array
    {
        return $this->registry()['vocabulary']['measuredProperties'];
    }

    /** The lab loop's property (App\Enums\LabProperty value) a measured property id is recorded as, or null while the lab cannot record it. */
    public function labPropertyOf(string $id): ?string
    {
        foreach ($this->measuredProperties() as $entry) {
            if ($entry['id'] === $id) {
                return $entry['labProperty'];
            }
        }

        return null;
    }

    /** @return list<string> */
    public function roles(): array
    {
        return $this->registry()['vocabulary']['roles'];
    }

    /** @return list<array{id: string, title: string, confidence: string}> */
    public function sources(): array
    {
        return $this->registry()['sources'];
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        try {
            $document = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $document = null;
        }

        if (! is_array($document)) {
            throw new RuntimeException("Not a family data file: {$file}");
        }

        return $document;
    }
}
