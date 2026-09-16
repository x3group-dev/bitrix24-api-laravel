<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Якорь app-токена: портал переведён на системного пользователя приложения
 * (событие ONAPPUSERREADY), и его токен больше не перезаписывается установкой.
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
