<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use X3Group\Bitrix24\Support\AppOwnerBackfill;

/**
 * Явный владелец app-токена портала.
 *
 * До этой колонки владение было неявным — оно закодировано только внутри самой строки
 * токена, поэтому любая запись молча меняла владельца. Колонка nullable: NULL означает
 * «владелец не установлен или не доверен», и правило «обновлять токен портала вместе с
 * токеном установщика» для такой строки не срабатывает (fail-closed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->unsignedInteger('user_id')->nullable()->after('member_id')->index();
        });

        $summary = (new AppOwnerBackfill())->run();

        echo sprintf(
            'b24_apps.user_id: заполнено %d, осталось NULL %d%s',
            $summary['filled'],
            count($summary['unresolved']),
            PHP_EOL
        );

        if ($summary['unresolved'] !== []) {
            echo '  порталы на ремонт (bitrix24:reanchor-app-token): '
                . implode(', ', array_slice($summary['unresolved'], 0, 50))
                . (count($summary['unresolved']) > 50 ? ' …' : '')
                . PHP_EOL;
        }
    }

    public function down(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
