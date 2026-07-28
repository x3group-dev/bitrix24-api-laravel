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
 *
 * Миграция намеренно сделана повторно запускаемой. На MySQL DDL не откатывается
 * (Schema\Grammars\Grammar::$transactions = false), а Migrator помечает миграцию
 * выполненной только после успешного возврата из up(). Если бэкофилл упадёт на середине
 * — а он делает тысячи UPDATE по строкам, которые прод постоянно обновляет, так что lock
 * wait timeout вполне реален — колонка останется в базе, а записи в migrations не будет.
 * Без защиты следующий php artisan migrate снова выполнил бы ALTER и умер на «Duplicate
 * column name 'user_id'», и чинить это пришлось бы руками в БД на каждом приложении.
 * Поэтому ALTER стоит под проверкой hasColumn, а бэкофилл берёт только user_id IS NULL
 * и продолжает с места обрыва.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('b24_apps', 'user_id')) {
            Schema::table('b24_apps', function (Blueprint $table) {
                // signed integer — как b24_users.user_id: значение логически одно и то же
                $table->integer('user_id')->nullable()->after('member_id')->index();
            });
        }

        $summary = (new AppOwnerBackfill())->run();

        $notAdmin = count($summary['notAdmin']);
        $unknownOwner = count($summary['unknownOwner']);
        $unparseable = count($summary['unparseable']);

        echo sprintf(
            'b24_apps.user_id: заполнено %d, осталось NULL %d'
                . ' (владелец не админ %d, владелец неизвестен %d, токен не разобран %d)%s',
            $summary['filled'],
            $notAdmin + $unknownOwner + $unparseable,
            $notAdmin,
            $unknownOwner,
            $unparseable,
            PHP_EOL
        );

        if ($notAdmin > 0) {
            echo '  порталы на ремонт (bitrix24:reanchor-app-token): '
                . $this->portals($summary['notAdmin'])
                . PHP_EOL;
        }

        if ($unknownOwner > 0) {
            echo "  владелец не найден в b24_users, таких порталов: $unknownOwner."
                . ' Само по себе это не поломка: is_admin заполняется только когда'
                . ' пользователь открывает фронтенд приложения, так что у чисто'
                . ' API-приложений сюда закономерно попадает весь флот.'
                . PHP_EOL;
        }

        if ($unparseable > 0) {
            echo "  ВНИМАНИЕ: владелец не читается из access_token, таких порталов: $unparseable."
                . ' Единичные случаи нормальны, массовые означают ошибку интеграции'
                . ' (не та колонка, сменился формат токена, значения зашифрованы) —'
                . ' разберитесь до того, как чинить порталы поштучно: '
                . $this->portals($summary['unparseable'])
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

    /**
     * @param  list<string>  $domains
     */
    private function portals(array $domains): string
    {
        return implode(', ', array_slice($domains, 0, 50))
            . (count($domains) > 50 ? ' …' : '');
    }
};
