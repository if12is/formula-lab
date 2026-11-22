<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * K-01 / K-09: the product family registry and the formula template library, loaded from the engine's data files
     * (config/families.php). A family names its regulator, IFRA category, required calculations, measured properties and
     * tax regime; each of those is null or empty when the sources do not state it. A template is a structure by role, and
     * its verification status says how far it has been proven. A formula links to its family and, when it started from one,
     * to the template it came from.
     */
    public function up(): void
    {
        Schema::create('product_families', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 60)->unique();
            $table->string('group', 40);
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('regulator', 10)->nullable();
            $table->string('ifra_category', 10)->nullable();
            $table->jsonb('required_calculations');
            $table->jsonb('measured_properties');
            $table->string('tax_regime')->nullable();
            $table->string('notes_key', 60)->nullable();
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence', 30);
            $table->string('verification', 20)->default('pending');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        DB::statement("alter table product_families add constraint product_families_regulator_check check (regulator is null or regulator in ('EOS','EDA'))");
        DB::statement("alter table product_families add constraint product_families_verification_check check (verification in ('pending','verified'))");

        Schema::create('formula_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 80)->unique();
            $table->foreignUuid('product_family_id')->constrained('product_families');
            $table->string('name_ar');
            $table->string('name_en');
            $table->string('verification_status', 20)->default('theoretical');
            $table->string('approved_by')->nullable();
            $table->date('approved_on')->nullable();
            $table->foreignUuid('source_id')->constrained('sources');
            $table->string('confidence', 30);
            // [{ key, role, percentWW|null, qs, optional }]: a structure, never a recipe nobody checked.
            $table->jsonb('lines');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index('product_family_id');
        });

        DB::statement("alter table formula_templates add constraint formula_templates_status_check check (verification_status in ('theoretical','lab_tested','chemist_approved'))");
        DB::statement("alter table formula_templates add constraint formula_templates_approval_check check (verification_status <> 'chemist_approved' or (approved_by is not null and approved_on is not null))");

        Schema::table('formulas', function (Blueprint $table) {
            $table->foreignUuid('product_family_id')->nullable()->after('product_type')->constrained('product_families');
            $table->foreignUuid('formula_template_id')->nullable()->after('product_family_id')->constrained('formula_templates');
        });
    }

    public function down(): void
    {
        Schema::table('formulas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('formula_template_id');
            $table->dropConstrainedForeignId('product_family_id');
        });
        Schema::dropIfExists('formula_templates');
        Schema::dropIfExists('product_families');
    }
};
