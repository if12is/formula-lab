<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What was recorded for one item of a product's EDA notification file (N-08): attached (with the document) or not applicable (with the reason).
     * An item with no row is missing. The item list itself is the engine's data, so `item_code` is a key into it, not a foreign key.
     */
    public function up(): void
    {
        Schema::create('eda_checklist_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('formula_id')->constrained('formulas')->cascadeOnDelete();
            $table->string('item_code');
            $table->string('status', 20);
            $table->string('document_title')->nullable();
            $table->string('document_reference')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['formula_id', 'item_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eda_checklist_entries');
    }
};
