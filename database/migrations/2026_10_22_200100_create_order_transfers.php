<?php

use App\Support\CodeGenerator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer of an order to another showroom.
 *
 * - `order_statuses.purpose` marks a status the system sets itself. The seeded
 *   «Передано» status gets the `transferred` purpose and is enabled; a status with a
 *   purpose stays active and is never the initial one.
 * - The system source kind «Другой салон» and `sources.transfer_from_showroom_id`: one
 *   internal source per direction «from → to», owned by the receiving showroom.
 * - `order_transfers`: one row per transfer; the source order may be transferred once
 *   and a repeated operation id is rejected by the database.
 */
return new class extends Migration
{
    private const TRANSFERRED = 'transferred';

    private const KIND_CODE = 'other-showroom';

    public function up(): void
    {
        Schema::table('order_statuses', function (Blueprint $table) {
            $table->string('purpose', 32)->nullable()->unique()->after('is_initial');
        });

        DB::table('order_statuses')
            ->where('code', CodeGenerator::base('Передано', 'status'))
            ->where('is_initial', false)
            ->update(['purpose' => self::TRANSFERRED, 'active' => true, 'updated_at' => now()]);

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_statuses ADD CONSTRAINT order_statuses_purpose_check CHECK (purpose IS NULL OR (active = 1 AND is_initial = 0))');
        }

        // An empty reference means a fresh installation: ReferenceDataSeeder adds the kind there.
        if (DB::table('source_kinds')->exists() && ! DB::table('source_kinds')->where('code', self::KIND_CODE)->orWhere('name', 'Другой салон')->exists()) {
            $kindId = DB::table('source_kinds')->insertGetId([
                'code' => self::KIND_CODE,
                'name' => 'Другой салон',
                'sort_order' => ((int) DB::table('source_kinds')->max('sort_order')) + 10,
                'active' => true,
                'lock_version' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach (DB::table('contact_channels')->pluck('id') as $channelId) {
                DB::table('source_kind_channel')->insert(['source_kind_id' => $kindId, 'contact_channel_id' => $channelId]);
            }
        }

        Schema::table('sources', function (Blueprint $table) {
            $table->foreignId('transfer_from_showroom_id')->nullable()->after('showroom_id')->constrained('showrooms')->restrictOnDelete();
            $table->unique(['showroom_id', 'transfer_from_showroom_id'], 'sources_transfer_direction_unique');
        });

        Schema::create('order_transfers', function (Blueprint $table) {
            $table->id();
            $table->uuid('operation_id')->unique();
            $table->foreignId('source_order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('target_order_id')->unique()->constrained('orders')->restrictOnDelete();
            $table->foreignId('from_showroom_id')->constrained('showrooms')->restrictOnDelete();
            $table->foreignId('to_showroom_id')->constrained('showrooms')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name');
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_transfers');

        Schema::table('sources', function (Blueprint $table) {
            $table->dropUnique('sources_transfer_direction_unique');
            $table->dropConstrainedForeignId('transfer_from_showroom_id');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_statuses DROP CHECK order_statuses_purpose_check');
        }

        Schema::table('order_statuses', function (Blueprint $table) {
            $table->dropUnique(['purpose']);
            $table->dropColumn('purpose');
        });
    }
};
