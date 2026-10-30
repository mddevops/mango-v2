<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Business-day settings. Every existing showroom gets the 19:00 boundary switched on
 * and keeps the timezone already chosen for it (Europe/Moscow is the column default).
 * Every existing source inherits the showroom rule, so no boundary is copied.
 */
return new class extends Migration
{
    private const TIME = '^([01][0-9]|2[0-3]):[0-5][0-9]$';

    public function up(): void
    {
        Schema::table('showrooms', function (Blueprint $table) {
            $table->boolean('business_day_boundary_enabled')->default(true)->after('timezone');
            $table->char('business_day_boundary', 5)->default('19:00')->after('business_day_boundary_enabled');
        });

        Schema::table('sources', function (Blueprint $table) {
            $table->string('business_day_mode', 16)->default('showroom')->after('note');
            $table->char('business_day_boundary', 5)->nullable()->after('business_day_mode');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE showrooms ADD CONSTRAINT showrooms_business_day_boundary_check CHECK (business_day_boundary REGEXP '".self::TIME."')");
            DB::statement("ALTER TABLE sources ADD CONSTRAINT sources_business_day_check CHECK (
                (business_day_mode = 'custom' AND business_day_boundary IS NOT NULL AND business_day_boundary REGEXP '".self::TIME."')
                OR (business_day_mode IN ('showroom', 'calendar') AND business_day_boundary IS NULL)
            )");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE sources DROP CHECK sources_business_day_check');
            DB::statement('ALTER TABLE showrooms DROP CHECK showrooms_business_day_boundary_check');
        }

        Schema::table('sources', function (Blueprint $table) {
            $table->dropColumn(['business_day_mode', 'business_day_boundary']);
        });

        Schema::table('showrooms', function (Blueprint $table) {
            $table->dropColumn(['business_day_boundary_enabled', 'business_day_boundary']);
        });
    }
};
