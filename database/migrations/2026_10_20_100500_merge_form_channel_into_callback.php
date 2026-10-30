<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A site form with the client's phone reaches the CRM as a callback, so the separate
 * channel `form` was a mistake. Its kind rules and source links move to the channel
 * coded `callback-request`, which keeps its id and code and is renamed to «Обратный
 * звонок». The `form` row stays, deactivated. Other channels are not touched.
 *
 * The merge cannot be split back, so down() leaves the data as it is.
 */
return new class extends Migration
{
    private const CALLBACK = 'callback-request';

    private const FORM = 'form';

    private const CALLBACK_NAME = 'Обратный звонок';

    public function up(): void
    {
        DB::transaction(function () {
            $callback = DB::table('contact_channels')->where('code', self::CALLBACK)->lockForUpdate()->first();
            $form = DB::table('contact_channels')->where('code', self::FORM)->lockForUpdate()->first();
            $now = now();

            if ($callback !== null && $callback->name !== self::CALLBACK_NAME) {
                $taken = DB::table('contact_channels')
                    ->where('name', self::CALLBACK_NAME)
                    ->where('id', '!=', $callback->id)
                    ->exists();

                if ($taken) {
                    throw new RuntimeException('Канал «'.self::CALLBACK_NAME.'» уже существует. Переименуйте его и повторите миграцию.');
                }

                DB::table('contact_channels')->where('id', $callback->id)->update([
                    'name' => self::CALLBACK_NAME,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => $now,
                ]);
            }

            if ($form === null) {
                return;
            }

            if ($callback !== null) {
                $this->moveLinks('source_kind_channel', 'source_kind_id', 'source_kinds', (int) $form->id, (int) $callback->id, $now);
                $this->moveLinks('source_channel', 'source_id', 'sources', (int) $form->id, (int) $callback->id, $now);
            }

            if ($form->active) {
                DB::table('contact_channels')->where('id', $form->id)->update([
                    'active' => false,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        //
    }

    /**
     * Links every owner of the form channel to the callback channel, skipping owners
     * that already have it, then removes the form links. Affected owners get a new
     * lock version, so a form opened before the merge cannot save stale channels.
     */
    private function moveLinks(string $pivot, string $ownerKey, string $ownerTable, int $from, int $to, mixed $now): void
    {
        $owners = DB::table($pivot)->where('contact_channel_id', $from)->pluck($ownerKey)->all();

        if ($owners === []) {
            return;
        }

        DB::table($pivot)->insertOrIgnoreUsing(
            [$ownerKey, 'contact_channel_id'],
            DB::table($pivot)->where('contact_channel_id', $from)->select($ownerKey)->selectRaw('? as contact_channel_id', [$to]),
        );

        DB::table($pivot)->where('contact_channel_id', $from)->delete();

        DB::table($ownerTable)->whereIn('id', $owners)->update([
            'lock_version' => DB::raw('lock_version + 1'),
            'updated_at' => $now,
        ]);
    }
};
