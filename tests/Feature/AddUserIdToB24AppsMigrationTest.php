<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Миграция обязана делать ровно одно: заводить колонку. Заполнение вынесено в
 * bitrix24:backfill-app-owner, и вернуть его в up() — значит снова превратить деплой в
 * проход по всей таблице b24_apps с непредсказуемым временем.
 */
class AddUserIdToB24AppsMigrationTest extends TestCase
{
    private const PATH = __DIR__ . '/../../database/migrations/2026_07_28_000000_add_user_id_to_b24_apps_table.php';

    public function test_user_id_column_exists_and_is_nullable(): void
    {
        self::assertTrue(Schema::hasColumn('b24_apps', 'user_id'));

        $app = B24App::query()->create([
            'member_id' => 'm-1',
            'domain' => 'portal.bitrix24.ru',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);

        self::assertNull($app->fresh()->user_id);
    }

    public function test_user_id_is_fillable(): void
    {
        $app = B24App::query()->create([
            'member_id' => 'm-2',
            'domain' => 'portal.bitrix24.ru',
            'access_token' => 'a',
            'refresh_token' => 'r',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'user_id' => 221,
        ]);

        self::assertSame(221, $app->fresh()->user_id);
    }

    /**
     * Портал, которому бэкофилл ЗАПОЛНИЛ БЫ владельца (в токене админ, подтверждённый по
     * b24_users), проходит через миграцию нетронутым.
     *
     * Колонку сначала убираем и заводим заново уже при живых данных — иначе миграция,
     * отработавшая на пустой базе в setUp, ничего бы и не заполнила, и тест был бы зелёным
     * при любой реализации up().
     */
    public function test_migration_creates_the_column_but_fills_nothing(): void
    {
        $migration = $this->migration();
        $migration->down();

        DB::table('b24_apps')->insert([
            'member_id' => 'm-1',
            'domain' => 'p1.bitrix24.ru',
            // 24 hex-символа префикса, затем 8 hex-цифр владельца (221 = 0xdd)
            'access_token' => 'deadbeefdeadbeefdeadbeef000000ddcafebabecafebabe',
            'refresh_token' => 'refresh-m-1',
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);
        DB::table('b24_users')->insert([
            'member_id' => 'm-1',
            'domain' => 'p1.bitrix24.ru',
            'user_id' => 221,
            'is_admin' => true,
            'access_token' => 'deadbeefdeadbeefdeadbeef000000ddcafebabecafebabe',
            'refresh_token' => 'refresh-m-1-221',
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);

        $migration->up();

        self::assertTrue(Schema::hasColumn('b24_apps', 'user_id'));
        self::assertNull(
            DB::table('b24_apps')->where('member_id', 'm-1')->value('user_id'),
            'миграция заполнила владельца сама: заполнение живёт в bitrix24:backfill-app-owner',
        );
    }

    private function migration(): Migration
    {
        return require self::PATH;
    }
}
