<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\TestCase;

class AddUserIdToB24AppsMigrationTest extends TestCase
{
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
}
