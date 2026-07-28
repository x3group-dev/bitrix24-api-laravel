<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Явный владелец app-токена портала. Колонка nullable: NULL означает «владелец не
 * установлен или не доверен», и правило 2 для такой строки не срабатывает (fail-closed).
 *
 * Миграция только создаёт колонку. Заполнение порталов, установленных до её появления, —
 * отдельная команда bitrix24:backfill-app-owner: оно ходит по всей таблице, его результат
 * зависит от накопленного в b24_users знания о правах и потому уточняется от прогона к
 * прогону, а миграция обязана отработать один раз и быстро.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            // signed integer — как b24_users.user_id: значение логически одно и то же
            $table->integer('user_id')->nullable()->after('member_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
