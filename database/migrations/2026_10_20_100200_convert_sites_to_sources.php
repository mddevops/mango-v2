<?php

use App\Support\CodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sites become universal sources in place: ids, relations and filled fields stay.
 *
 * The former site type is kept in legacy_classification_id for every converted row.
 * Rows typed with the classification coded `classified` move to the source kind coded
 * `classified` and lose the classification (it was a kind, not a classification); the
 * classification row itself stays, deactivated. All other rows get the kind `site` and
 * keep their classification, including VA and user-created values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('sites', 'sources');

        Schema::table('sources', function (Blueprint $table) {
            $table->dropForeign('sites_site_type_id_foreign');
            $table->renameIndex('sites_showroom_id_active_index', 'sources_showroom_id_active_index');
            $table->renameIndex('sites_name_index', 'sources_name_index');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->renameColumn('site_type_id', 'source_classification_id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedBigInteger('source_classification_id')->nullable()->change();
            $table->foreign('source_classification_id')->references('id')->on('source_classifications')->restrictOnDelete();
            $table->string('code', CodeGenerator::MAX_LENGTH)->nullable()->after('id');
            $table->foreignId('source_kind_id')->nullable()->after('showroom_id')->constrained()->restrictOnDelete();
            $table->foreignId('legacy_classification_id')->nullable()->after('note')
                ->constrained('source_classifications')->restrictOnDelete();
        });

        $siteKind = DB::table('source_kinds')->where('code', 'site')->value('id');
        $classifiedKind = DB::table('source_kinds')->where('code', 'classified')->value('id');
        $classified = DB::table('source_classifications')->where('code', 'classified')->value('id');

        DB::table('sources')->update(['legacy_classification_id' => DB::raw('source_classification_id')]);

        if ($classified !== null) {
            DB::table('sources')->where('source_classification_id', $classified)->update([
                'source_kind_id' => $classifiedKind,
                'source_classification_id' => null,
            ]);

            DB::table('source_classifications')->where('id', $classified)->update(['active' => false]);
        }

        DB::table('sources')->whereNull('source_kind_id')->update(['source_kind_id' => $siteKind]);

        $taken = collect();

        DB::table('sources')->orderBy('id')->select(['id', 'name'])->each(function (object $row) use ($taken) {
            $code = CodeGenerator::firstFree(CodeGenerator::base((string) $row->name, 'source'), $taken);
            $taken->push($code);
            DB::table('sources')->where('id', $row->id)->update(['code' => $code]);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('code', CodeGenerator::MAX_LENGTH)->nullable(false)->change();
            $table->unique('code');
            $table->unsignedBigInteger('source_kind_id')->nullable(false)->change();
        });

        Schema::create('source_channel', function (Blueprint $table) {
            $table->foreignId('source_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_channel_id')->constrained()->restrictOnDelete();

            $table->primary(['source_id', 'contact_channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_channel');

        $classified = DB::table('source_classifications')->where('code', 'classified')->value('id');

        DB::table('sources')->whereNull('source_classification_id')->whereNotNull('legacy_classification_id')
            ->update(['source_classification_id' => DB::raw('legacy_classification_id')]);

        DB::table('sources')->whereNull('source_classification_id')->update(['source_classification_id' => $classified]);

        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropConstrainedForeignId('legacy_classification_id');
            $table->dropConstrainedForeignId('source_kind_id');
            $table->dropColumn('code');
            $table->dropForeign(['source_classification_id']);
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->renameColumn('source_classification_id', 'site_type_id');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->unsignedBigInteger('site_type_id')->nullable(false)->change();
            $table->renameIndex('sources_showroom_id_active_index', 'sites_showroom_id_active_index');
            $table->renameIndex('sources_name_index', 'sites_name_index');
        });

        Schema::rename('sources', 'sites');

        Schema::table('sites', function (Blueprint $table) {
            $table->foreign('site_type_id')->references('id')->on('source_classifications')->restrictOnDelete();
        });
    }
};
