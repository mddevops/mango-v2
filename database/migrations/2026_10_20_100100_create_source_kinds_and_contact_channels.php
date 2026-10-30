<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Source kinds, contact channels and the editable rules which classifications and
 * channels each kind allows. Initial values are inserted here because existing sites
 * are converted to sources in the next migration; ReferenceDataSeeder adds the same
 * values to installations that miss them and never overwrites user changes.
 */
return new class extends Migration
{
    private const KINDS = [
        ['code' => 'site', 'name' => 'Сайт', 'sort_order' => 10],
        ['code' => 'quiz', 'name' => 'Квиз', 'sort_order' => 20],
        ['code' => 'bot', 'name' => 'Бот', 'sort_order' => 30],
        ['code' => 'social', 'name' => 'Соцсеть', 'sort_order' => 40],
        ['code' => 'classified', 'name' => 'Классифайд', 'sort_order' => 50],
        ['code' => 'partner', 'name' => 'Партнёр', 'sort_order' => 60],
        ['code' => 'manual', 'name' => 'Ручной ввод', 'sort_order' => 70],
    ];

    private const CHANNELS = [
        ['code' => 'callback-request', 'name' => 'Запрос обратного звонка', 'sort_order' => 10],
        ['code' => 'direct-call', 'name' => 'Прямой звонок', 'sort_order' => 20],
        ['code' => 'chat', 'name' => 'Чат', 'sort_order' => 30],
        ['code' => 'form', 'name' => 'Форма/заявка', 'sort_order' => 40],
    ];

    private const CLASSIFICATIONS = [
        ['code' => 'advertising', 'name' => 'Реклама', 'sort_order' => 10],
        ['code' => 'seo', 'name' => 'SEO', 'sort_order' => 20],
        ['code' => 'advertising-seo', 'name' => 'Реклама/SEO', 'sort_order' => 30],
        ['code' => 'va', 'name' => 'VA', 'sort_order' => 50],
    ];

    private const KIND_CLASSIFICATIONS = [
        'site' => ['advertising', 'seo', 'advertising-seo'],
        'quiz' => ['advertising', 'seo', 'advertising-seo'],
    ];

    private const KIND_CHANNELS = [
        'site' => ['callback-request', 'direct-call', 'chat', 'form'],
        'quiz' => ['callback-request', 'form'],
        'bot' => ['callback-request', 'direct-call', 'chat', 'form'],
        'social' => ['callback-request', 'direct-call', 'chat', 'form'],
        'classified' => ['callback-request', 'direct-call', 'chat', 'form'],
        'partner' => ['callback-request', 'direct-call', 'chat', 'form'],
        'manual' => ['callback-request', 'direct-call', 'chat', 'form'],
    ];

    public function up(): void
    {
        Schema::create('source_kinds', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'name']);
        });

        Schema::create('contact_channels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name', 100)->unique();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('lock_version')->default(0);
            $table->timestamps();

            $table->index(['sort_order', 'name']);
        });

        Schema::create('source_kind_classification', function (Blueprint $table) {
            $table->foreignId('source_kind_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_classification_id')->constrained()->cascadeOnDelete();

            $table->primary(['source_kind_id', 'source_classification_id'], 'source_kind_classification_primary');
        });

        Schema::create('source_kind_channel', function (Blueprint $table) {
            $table->foreignId('source_kind_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_channel_id')->constrained()->cascadeOnDelete();

            $table->primary(['source_kind_id', 'contact_channel_id']);
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('sort_order')->default(0)->after('name');
            $table->index(['sort_order', 'name']);
        });

        $now = now();

        foreach (self::KINDS as $row) {
            $this->insertMissing('source_kinds', $row, $now);
        }

        foreach (self::CHANNELS as $row) {
            $this->insertMissing('contact_channels', $row, $now);
        }

        foreach (self::CLASSIFICATIONS as $row) {
            $this->insertMissing('source_classifications', $row, $now);
        }

        $kinds = DB::table('source_kinds')->pluck('id', 'code');
        $channels = DB::table('contact_channels')->pluck('id', 'code');
        $classifications = DB::table('source_classifications')->pluck('id', 'code');

        foreach (self::KIND_CLASSIFICATIONS as $kind => $codes) {
            foreach ($codes as $code) {
                if (isset($kinds[$kind], $classifications[$code])) {
                    DB::table('source_kind_classification')->insertOrIgnore([
                        'source_kind_id' => $kinds[$kind],
                        'source_classification_id' => $classifications[$code],
                    ]);
                }
            }
        }

        foreach (self::KIND_CHANNELS as $kind => $codes) {
            foreach ($codes as $code) {
                if (isset($kinds[$kind], $channels[$code])) {
                    DB::table('source_kind_channel')->insertOrIgnore([
                        'source_kind_id' => $kinds[$kind],
                        'contact_channel_id' => $channels[$code],
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropIndex(['sort_order', 'name']);
            $table->dropColumn('sort_order');
        });

        Schema::dropIfExists('source_kind_channel');
        Schema::dropIfExists('source_kind_classification');
        Schema::dropIfExists('contact_channels');
        Schema::dropIfExists('source_kinds');
    }

    /**
     * @param  array{code: string, name: string, sort_order: int}  $row
     */
    private function insertMissing(string $table, array $row, mixed $now): void
    {
        $exists = DB::table($table)
            ->where('code', $row['code'])
            ->orWhere('name', $row['name'])
            ->exists();

        if (! $exists) {
            DB::table($table)->insert([...$row, 'active' => true, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now]);
        }
    }
};
