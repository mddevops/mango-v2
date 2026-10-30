<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The «Другой салон» kind was linked to every channel, including disabled ones that no
 * other kind offers; it keeps only the active channels, as the reference seeder does.
 */
return new class extends Migration
{
    public function up(): void
    {
        $kindId = DB::table('source_kinds')->where('code', 'other-showroom')->value('id');

        if ($kindId === null) {
            return;
        }

        DB::table('source_kind_channel')
            ->where('source_kind_id', $kindId)
            ->whereIn('contact_channel_id', DB::table('contact_channels')->where('active', false)->select('id'))
            ->delete();
    }

    public function down(): void {}
};
