<?php

namespace Tests\Feature\Architecture;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * K-08 (docs/adr/004-commerce-lives-in-sanad.md): Formula Lab keeps no sales, invoice, customer, distributor, inventory or
 * cashier module. All of that is Sanad's, reached through app/Integrations/Sanad. What may exist here is a cache of Sanad's rows
 * (tables prefixed `sanad_`) and records that point at a Sanad uuid. A test that fails here means a parallel commerce table
 * or screen was added: move it to Sanad instead.
 */
class NoCommerceModuleTest extends TestCase
{
    /** Words that name a commerce or stock concept. */
    private const COMMERCE_WORDS = [
        'sale', 'sales', 'invoice', 'invoices', 'customer', 'customers', 'distributor', 'distributors', 'pos', 'cashier', 'shift', 'shifts',
        'branch', 'branches', 'inventory', 'stock', 'warehouse', 'warehouses', 'supplier', 'suppliers', 'purchase', 'purchases', 'payment', 'payments', 'receipt', 'receipts',
    ];

    /** Routes that belong to the Sanad integration or to local-only test playgrounds, not to a Formula Lab module. */
    private const NOT_A_MODULE_PREFIXES = ['__mock/', '__dev/', 'api/sanad/'];

    /** @return list<string> */
    private function webSegments(): array
    {
        $segments = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (collect(self::NOT_A_MODULE_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($uri, $prefix))) {
                continue;
            }

            $segments[$uri] = strtolower(explode('/', $uri)[0]);
        }

        return array_values(array_unique($segments));
    }

    public function test_the_scan_finds_the_formula_lab_routes_so_the_check_is_not_vacuous(): void
    {
        $this->assertContains('formulas', $this->webSegments());
        $this->assertContains('families', $this->webSegments());
    }

    public function test_no_route_is_a_sales_invoice_customer_distributor_inventory_or_cashier_screen(): void
    {
        foreach (Route::getRoutes() as $route) {
            $uri = strtolower($route->uri());

            if (collect(self::NOT_A_MODULE_PREFIXES)->contains(fn (string $prefix): bool => str_starts_with($uri, $prefix))) {
                continue;
            }

            // The top-level segment names the module; a lookup under another module (price-lists/customers/search) is that module's.
            $this->assertNotContains(explode('/', $uri)[0], self::COMMERCE_WORDS, "Route [{$uri}] is a commerce screen: that lives in Sanad.");
        }
    }

    public function test_no_table_is_a_commerce_or_stock_table_unless_it_caches_sanad(): void
    {
        $tables = array_column(Schema::getTables(), 'name');
        $this->assertContains('formulas', $tables);

        foreach ($tables as $table) {
            if (str_starts_with($table, 'sanad_')) {
                continue;
            }

            $this->assertEmpty(
                array_intersect(explode('_', $table), self::COMMERCE_WORDS) !== [] && ! str_starts_with($table, 'price_list') ? [$table] : [],
                "Table [{$table}] is a commerce or stock table: that lives in Sanad (a cache is named sanad_*).",
            );
        }
    }

    public function test_no_model_or_controller_is_a_commerce_module(): void
    {
        $classes = [];
        foreach (['Models', 'Http/Controllers'] as $directory) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path($directory), \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() === 'php') {
                    $classes[] = $file->getBasename('.php');
                }
            }
        }
        $this->assertContains('Formula', $classes);

        foreach ($classes as $class) {
            $words = array_map('strtolower', preg_split('/(?=[A-Z])/', str_replace('Controller', '', $class), -1, PREG_SPLIT_NO_EMPTY) ?: []);
            // SanadCustomer and friends are Sanad's rows cached here, named for what they are.
            // PriceList* is the pricing output sent to Sanad, with its link to a Sanad customer uuid (ADR 004).
            if (in_array($words[0] ?? '', ['sanad', 'price'], true)) {
                continue;
            }

            $this->assertEmpty(array_intersect($words, self::COMMERCE_WORDS), "{$class} looks like a commerce module: that lives in Sanad.");
        }
    }

    public function test_the_decision_is_recorded_as_an_adr(): void
    {
        $adr = (string) file_get_contents(base_path('../../docs/adr/004-commerce-lives-in-sanad.md'));

        $this->assertStringContainsString('Sanad', $adr);
        $this->assertStringContainsString('K-08', $adr);
        $this->assertStringContainsString('004-commerce-lives-in-sanad.md', (string) file_get_contents(base_path('../../docs/adr/README.md')));
    }
}
