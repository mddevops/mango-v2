<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recordings are no longer downloaded: the states of the local fetch become «recorded»
 * (Mango reported the recording) or stay «pending». Columns of the fetch and local files
 * are kept as they are; nothing is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('call_recordings')
            ->whereIn('state', ['fetching', 'ready', 'failed'])
            ->update(['state' => 'recorded', 'claim_token' => null, 'claimed_at' => null]);

        DB::table('call_recordings')
            ->where('state', 'pending')
            ->where(fn ($query) => $query->whereNotNull('added_at')->orWhere(fn ($completed) => $completed
                ->where('recording_state', 'Completed')
                ->where(fn ($code) => $code->whereNull('completion_code')->orWhere('completion_code', 1000))))
            ->update(['state' => 'recorded']);
    }

    public function down(): void
    {
        // The states of the removed fetch are not restored.
    }
};
