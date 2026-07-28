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

        $unparseable = count($summary['unparseable']);
        $notAdmin = count($summary['notAdmin']);

        echo sprintf(
            'b24_apps.user_id: заполнено %d, осталось NULL %d (владелец не админ %d, токен не разобран %d)%s',
            $summary['filled'],
            $unparseable + $notAdmin,
            $notAdmin,
            $unparseable,
            PHP_EOL
        );

        if ($notAdmin > 0) {
            echo '  порталы на ремонт (bitrix24:reanchor-app-token): '
                . implode(', ', array_slice($summary['notAdmin'], 0, 50))
                . ($notAdmin > 50 ? ' …' : '')
                . PHP_EOL;
        }

        if ($unparseable > 0) {
            echo "  ВНИМАНИЕ: владелец не читается из access_token, таких порталов: $unparseable."
                . ' Единичные случаи нормальны, массовые означают ошибку интеграции'
                . ' (не та колонка, сменился формат токена, значения зашифрованы) —'
                . ' разберитесь до того, как чинить порталы поштучно.'
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
