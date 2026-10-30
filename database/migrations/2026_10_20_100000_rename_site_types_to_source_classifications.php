<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site types become source classifications. Rows, ids and codes are kept as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('site_types', 'source_classifications');

        Schema::table('source_classifications', function (Blueprint $table) {
            $table->renameIndex('site_types_code_unique', 'source_classifications_code_unique');
            $table->renameIndex('site_types_name_unique', 'source_classifications_name_unique');
            $table->renameIndex('site_types_active_index', 'source_classifications_active_index');
            $table->renameIndex('site_types_sort_order_name_index', 'source_classifications_sort_order_name_index');
        });
    }

    public function down(): void
    {
        Schema::table('source_classifications', function (Blueprint $table) {
            $table->renameIndex('source_classifications_code_unique', 'site_types_code_unique');
            $table->renameIndex('source_classifications_name_unique', 'site_types_name_unique');
            $table->renameIndex('source_classifications_active_index', 'site_types_active_index');
            $table->renameIndex('source_classifications_sort_order_name_index', 'site_types_sort_order_name_index');
        });

        Schema::rename('source_classifications', 'site_types');
    }
};
