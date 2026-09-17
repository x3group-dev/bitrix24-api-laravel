<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Флаг is_system_user: портал переведён на системного пользователя приложения
 * (событие ONAPPUSERREADY), и его токены доступа больше не перезаписываются установкой.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->boolean('is_system_user')->default(false)->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->dropColumn('is_system_user');
        });
    }
};
