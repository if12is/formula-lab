<?php

namespace Tests\Feature\Architecture;

use App\Exceptions\Domain\BusinessRuleViolationException;
use App\Http\Requests\Concerns\TranslatesValidationMessages;
use BackedEnum;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/** Enforces the layering rules of orchestration/context/laravel-conventions.md. */
class ArchitectureTest extends TestCase
{
    /** @return list<string> Absolute paths of the PHP files under app/<directory>. */
    private function files(string $directory): array
    {
        $root = app_path($directory);
        if (! is_dir($root)) {
            return [];
        }

        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<class-string> */
    private function classes(string $directory): array
    {
        return array_map(
            fn (string $path): string => 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($path, strlen(app_path()) + 1)),
            $this->files($directory),
        );
    }

    /** @return list<class-string> Concrete classes (no traits, interfaces or abstracts). */
    private function concreteClasses(string $directory): array
    {
        return array_values(array_filter(
            $this->classes($directory),
            fn (string $class): bool => (new ReflectionClass($class))->isInstantiable(),
        ));
    }

    public function test_the_architecture_scan_finds_the_layers(): void
    {
        $this->assertNotEmpty($this->concreteClasses('Services'));
        $this->assertNotEmpty($this->concreteClasses('Actions'));
        $this->assertNotEmpty($this->classes('Http/Controllers'));
    }

    public function test_services_are_final(): void
    {
        foreach ($this->concreteClasses('Services') as $class) {
            $this->assertTrue((new ReflectionClass($class))->isFinal(), "{$class} must be final");
        }
    }

    public function test_actions_are_final(): void
    {
        foreach ($this->concreteClasses('Actions') as $class) {
            $this->assertTrue((new ReflectionClass($class))->isFinal(), "{$class} must be final");
        }
    }

    public function test_every_action_has_exactly_one_public_method_named_invoke(): void
    {
        foreach ($this->concreteClasses('Actions') as $class) {
            $methods = array_values(array_filter(
                (new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC),
                fn (ReflectionMethod $method): bool => $method->getName() !== '__construct',
            ));

            $this->assertSame(
                ['__invoke'],
                array_map(fn (ReflectionMethod $method): string => $method->getName(), $methods),
                "{$class} must expose a single public __invoke()",
            );
        }
    }

    public function test_controllers_never_validate_inline(): void
    {
        foreach ($this->files('Http/Controllers') as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/\$request->validate\(|->validateWithBag\(|Validator::make\(/',
                (string) file_get_contents($path),
                "{$path} validates inline; use a FormRequest",
            );
        }
    }

    public function test_app_code_has_no_hard_coded_arabic(): void
    {
        foreach ($this->files('') as $path) {
            $this->assertDoesNotMatchRegularExpression('/\p{Arabic}/u', (string) file_get_contents($path), "{$path} contains Arabic text; move it to lang/");
        }
    }

    public function test_every_form_request_uses_the_translated_messages_trait(): void
    {
        foreach ($this->concreteClasses('Http/Requests') as $class) {
            $this->assertContains(TranslatesValidationMessages::class, class_uses_recursive($class), "{$class} must use TranslatesValidationMessages");
            $this->assertTrue(is_subclass_of($class, FormRequest::class), "{$class} must extend FormRequest");
        }
    }

    public function test_every_enum_offers_label_options_and_values(): void
    {
        foreach ($this->classes('Enums') as $class) {
            if (! enum_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            $this->assertTrue($reflection->implementsInterface(BackedEnum::class), "{$class} must be backed");
            foreach (['label', 'options', 'values'] as $method) {
                $this->assertTrue($reflection->hasMethod($method), "{$class} must have {$method}()");
            }
        }
    }

    public function test_domain_exceptions_extend_the_shared_base(): void
    {
        foreach ($this->concreteClasses('Exceptions/Domain') as $class) {
            $this->assertTrue(is_a($class, BusinessRuleViolationException::class, true), "{$class} must extend BusinessRuleViolationException");
        }
    }

    public function test_env_is_only_read_in_config_files(): void
    {
        foreach ($this->files('') as $path) {
            $this->assertDoesNotMatchRegularExpression('/\benv\(/', (string) file_get_contents($path), "{$path} calls env() outside config/");
        }
    }

    public function test_app_code_does_not_declare_strict_types(): void
    {
        foreach ($this->files('') as $path) {
            $this->assertStringNotContainsString('declare(strict_types=1)', (string) file_get_contents($path), $path);
        }
    }
}
