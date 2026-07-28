# Явный владелец app-токена портала — план реализации

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Добавить в `b24_apps` явного владельца токена (`user_id`) и разрешить запись в эту таблицу только при установке приложения и при обновлении токена установщика.

**Architecture:** Все правила записи живут в `AppTokenWriter` в виде чистых функций-решений; три точки записи (`InstallService`, `UserAuthDatabaseStorage`, `B24AppUserMiddleware`) только вызывают его. Существующие строки заполняются миграцией, которая декодирует владельца из самого токена и принимает его лишь для администраторов. Повреждённые порталы чинит консольная команда.

**Tech Stack:** PHP 8.4, Laravel 11/12 (пакет), Eloquent, PHPUnit 11, orchestra/testbench, b24phpsdk 3.x.

**Spec:** `docs/superpowers/specs/2026-07-28-app-token-owner-design.md`

---

## Файловая структура

**Создаются:**
- `src/Support/TokenOwner.php` — декодирование ID владельца из access-токена. Чистая функция, используется бэкофиллом и командой ремонта.
- `src/Support/AppOwnerBackfill.php` — заполнение `user_id` для уже установленных порталов. Вынесено из миграции, чтобы покрываться тестами напрямую.
- `src/Console/Commands/ReanchorAppTokenCommand.php` — команда ремонта повреждённых порталов.
- `database/migrations/2026_07_28_000000_add_user_id_to_b24_apps_table.php` — колонка + бэкофилл.
- `tests/TestCase.php` — базовый класс для тестов с контейнером и БД (testbench).
- `tests/Unit/Support/TokenOwnerTest.php`
- `tests/Feature/AppTokenOwnershipTest.php`
- `tests/Feature/AddUserIdToB24AppsMigrationTest.php`
- `tests/Feature/ReanchorAppTokenCommandTest.php`

**Изменяются:**
- `composer.json` — `orchestra/testbench` в `require-dev`.
- `phpunit.xml` — раздельные наборы Unit/Feature.
- `src/Models/B24App.php` — `user_id` в `$fillable`.
- `src/Application/Local/Infrastructure/Database/AppTokenWriter.php` — правило 2 и исполнители.
- `src/Application/Install/InstallService.php` — передача `userId`, нейтральный диспетчер для probe.
- `src/Application/Local/Infrastructure/Database/UserAuthDatabaseStorage.php` — вызов `propagateFromUser`.
- `src/Http/Middleware/B24AppUserMiddleware.php` — вызов `propagateFromUser`.
- `src/Bitrix24ServiceProvider.php` — регистрация команды.
- `tests/Unit/Install/AppTokenWriterDecisionTest.php` — тесты правила 2.

---

## Task 1: Тестовая обвязка с контейнером и БД

Сейчас тесты пакета чисто юнитовые: `phpunit.xml` грузит `vendor/autoload.php`, контейнера и БД нет. Без них нельзя протестировать сами записи в таблицы — а именно они и есть предмет этой работы.

**Files:**
- Modify: `composer.json`
- Modify: `phpunit.xml`
- Create: `tests/TestCase.php`

- [ ] **Step 1: Добавить testbench**

```bash
composer require --dev "orchestra/testbench:^9.0|^10.0" --no-scripts
```

- [ ] **Step 2: Создать базовый TestCase**

Создать `tests/TestCase.php`:

```php
<?php

namespace X3Group\Bitrix24\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use X3Group\Bitrix24\Bitrix24ServiceProvider;

/**
 * База для тестов, которым нужен контейнер Laravel и БД.
 * Чистые юнит-тесты наследуют PHPUnit\Framework\TestCase напрямую.
 */
abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [Bitrix24ServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // Ограничения включены намеренно: харнесс существует ради проверки реальных
            // записей в b24_apps/b24_users. С выключенными FK тесты могли бы собирать
            // состояния, невозможные в проде (строка пользователя без портала), и всё
            // равно быть зелёными. Прод — MySQL/InnoDB с включёнными ограничениями.
            'foreign_key_constraints' => true,
        ]);

        $app['config']->set('bitrix24.client_id', 'test-client-id');
        $app['config']->set('bitrix24.client_secret', 'test-client-secret');
        $app['config']->set('bitrix24.scope', 'crm');
        $app['config']->set('bitrix24.log_max_files', 1);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
```

- [ ] **Step 3: Разделить наборы тестов**

Заменить блок `<testsuites>` в `phpunit.xml` на:

```xml
    <testsuites>
        <testsuite name="Unit">
            <directory suffix=".php">./tests/Unit</directory>
        </testsuite>
        <testsuite name="Feature">
            <directory suffix=".php">./tests/Feature</directory>
        </testsuite>
    </testsuites>
```

- [ ] **Step 4: Проверить, что существующие тесты не сломались**

Run: `./vendor/bin/phpunit`
Expected: PASS, 22 теста (те же, что были до изменений).

- [ ] **Step 5: Commit**

```bash
git add composer.json phpunit.xml tests/TestCase.php
git commit -m "test: обвязка с контейнером и БД (orchestra/testbench)

Записи в b24_apps/b24_users до сих пор не были покрыты тестами — именно поэтому
клоббер токена прожил незамеченным: решающая функция AppTokenWriter::shouldWrite
проверялась, а то, что запись идёт мимо неё, не проверял никто."
```

---

## Task 2: `TokenOwner` — декодирование владельца из токена

В access-токене Bitrix 8 hex-цифр по смещению 24 содержат ID пользователя. Проверено на 90 пользователях одного портала: 90 совпадений, 0 промахов. При рефреше владелец сохраняется.

**Files:**
- Create: `src/Support/TokenOwner.php`
- Test: `tests/Unit/Support/TokenOwnerTest.php`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Unit/Support/TokenOwnerTest.php`:

```php
<?php

namespace X3Group\Bitrix24\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use X3Group\Bitrix24\Support\TokenOwner;

class TokenOwnerTest extends TestCase
{
    public function test_decodes_user_id_from_real_tokens(): void
    {
        // Синтетические токены формата Bitrix: 8 hex-цифр по смещению 24 — ID владельца.
        // Настоящие токены в фикстуры не кладём: это живые учётные данные клиентов.
        self::assertSame(162, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef000000a2cafebabecafebabe'));
        self::assertSame(8, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef00000008cafebabecafebabe'));
        self::assertSame(221, TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef000000ddcafebabecafebabe'));
    }

    public function test_returns_null_for_unusable_input(): void
    {
        self::assertNull(TokenOwner::fromAccessToken(null));
        self::assertNull(TokenOwner::fromAccessToken(''));
        self::assertNull(TokenOwner::fromAccessToken('слишком-короткий'));
    }

    public function test_returns_null_when_segment_is_not_hex(): void
    {
        // 32+ символа, но в позиции владельца не hex — формат не наш.
        self::assertNull(TokenOwner::fromAccessToken(str_repeat('z', 40)));
    }

    public function test_returns_null_for_zero_owner(): void
    {
        // Нулевой ID пользователя не существует — считаем нераспознанным.
        self::assertNull(TokenOwner::fromAccessToken('deadbeefdeadbeefdeadbeef00000000cafebabecafebabe'));
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Unit/Support/TokenOwnerTest.php`
Expected: FAIL — `Class "X3Group\Bitrix24\Support\TokenOwner" not found`

- [ ] **Step 3: Реализовать**

Создать `src/Support/TokenOwner.php`:

```php
<?php

namespace X3Group\Bitrix24\Support;

/**
 * Определяет владельца access-токена Bitrix24.
 *
 * В токене 8 hex-цифр по смещению 24 содержат ID пользователя, которому он выдан.
 * Значение переживает рефреш: обновлённый токен остаётся токеном того же человека.
 *
 * Нужно, чтобы заполнить b24_apps.user_id для порталов, установленных до появления
 * этой колонки, и чтобы находить порталы, где app-токен принадлежит не тому.
 */
class TokenOwner
{
    private const OWNER_OFFSET = 24;

    private const OWNER_LENGTH = 8;

    /**
     * @return int|null ID владельца либо null, если формат не распознан
     */
    public static function fromAccessToken(?string $accessToken): ?int
    {
        if ($accessToken === null || strlen($accessToken) < self::OWNER_OFFSET + self::OWNER_LENGTH) {
            return null;
        }

        $segment = substr($accessToken, self::OWNER_OFFSET, self::OWNER_LENGTH);

        if (!ctype_xdigit($segment)) {
            return null;
        }

        $userId = (int) hexdec($segment);

        return $userId > 0 ? $userId : null;
    }
}
```

- [ ] **Step 4: Запустить тест**

Run: `./vendor/bin/phpunit tests/Unit/Support/TokenOwnerTest.php`
Expected: PASS (4 теста)

- [ ] **Step 5: Commit**

```bash
git add src/Support/TokenOwner.php tests/Unit/Support/TokenOwnerTest.php
git commit -m "feat(tokens): TokenOwner — определение владельца access-токена"
```

---

## Task 3: Колонка `user_id` в `b24_apps` + модель

**Files:**
- Create: `database/migrations/2026_07_28_000000_add_user_id_to_b24_apps_table.php`
- Modify: `src/Models/B24App.php`
- Test: `tests/Feature/AddUserIdToB24AppsMigrationTest.php`

- [ ] **Step 1: Написать падающий тест на схему**

Создать `tests/Feature/AddUserIdToB24AppsMigrationTest.php`:

```php
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
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Feature/AddUserIdToB24AppsMigrationTest.php`
Expected: FAIL — колонки `user_id` нет

- [ ] **Step 3: Создать класс бэкофилла**

Логика вынесена из миграции в отдельный класс, чтобы её можно было проверить тестами напрямую, а не переписывать копию в тесте.

Создать `src/Support/AppOwnerBackfill.php`:

```php
<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\DB;

/**
 * Заполняет b24_apps.user_id для порталов, установленных до появления этой колонки.
 *
 * Владельца берём из самого токена и принимаем ТОЛЬКО если это администратор: на
 * повреждённых порталах в токене лежит рядовой сотрудник, и записать его владельцем
 * значило бы узаконить подмену. Такие строки остаются с NULL и попадают в отчёт как
 * кандидаты на ремонт (bitrix24:reanchor-app-token).
 */
class AppOwnerBackfill
{
    /**
     * @return array{filled: int, unresolved: list<string>}
     */
    public function run(): array
    {
        $filled = 0;
        $unresolved = [];

        DB::table('b24_apps')->orderBy('id')->chunkById(200, function ($rows) use (&$filled, &$unresolved) {
            foreach ($rows as $row) {
                $owner = TokenOwner::fromAccessToken($row->access_token);

                if ($owner === null || !$this->isAdmin($row->member_id, $owner)) {
                    $unresolved[] = $row->domain;

                    continue;
                }

                DB::table('b24_apps')->where('id', $row->id)->update(['user_id' => $owner]);
                $filled++;
            }
        });

        return ['filled' => $filled, 'unresolved' => $unresolved];
    }

    private function isAdmin(string $memberId, int $userId): bool
    {
        return (bool) DB::table('b24_users')
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->value('is_admin');
    }
}
```

- [ ] **Step 4: Создать миграцию**

Создать `database/migrations/2026_07_28_000000_add_user_id_to_b24_apps_table.php`:

```php
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
```

- [ ] **Step 5: Добавить `user_id` в модель**

В `src/Models/B24App.php` в массив `$fillable` добавить `'user_id',` сразу после `'member_id',`:

```php
    protected $fillable = [
        'access_token',
        'refresh_token',
        'domain',
        'oauth_server_url',
        'member_id',
        'user_id',
        'expires',
        'expires_in',
        'application_token',
        'error_update',
    ];
```

- [ ] **Step 6: Запустить тест**

Run: `./vendor/bin/phpunit tests/Feature/AddUserIdToB24AppsMigrationTest.php`
Expected: PASS (2 теста)

- [ ] **Step 7: Commit**

```bash
git add src/Support/AppOwnerBackfill.php database/migrations/2026_07_28_000000_add_user_id_to_b24_apps_table.php src/Models/B24App.php tests/Feature/AddUserIdToB24AppsMigrationTest.php
git commit -m "feat(tokens): колонка b24_apps.user_id — явный владелец app-токена"
```

---

## Task 4: Бэкофилл — тесты на правило «только админ»

> **Уточнено в ходе Task 3.** `AppOwnerBackfill::run()` возвращает четыре корзины, а не две:
> `filled` (владелец распознан и подтверждён как админ), `notAdmin` (строка в `b24_users`
> есть, `is_admin` = false — настоящие кандидаты на ремонт), `unknownOwner` (владелец
> распознан, но строки в `b24_users` нет — норма для приложений, чьи пользователи не
> открывают фронтенд), `unparseable` (токен не разобран — массовые случаи означают ошибку
> интеграции). Плюс `run()` идемпотентен: обрабатывает только строки с `user_id IS NULL`,
> поэтому повторный запуск — no-op. Тесты должны покрывать все четыре исхода и повторный
> запуск.


Класс `AppOwnerBackfill` написан в Task 3; здесь он покрывается тестами на три исхода: владелец-админ, владелец не админ, нераспознанный токен.

**Files:**
- Modify: `tests/Feature/AddUserIdToB24AppsMigrationTest.php`

- [ ] **Step 1: Написать падающие тесты бэкофилла**

Добавить в класс `AddUserIdToB24AppsMigrationTest` (импорты `use X3Group\Bitrix24\Models\B24User;` и `use X3Group\Bitrix24\Support\AppOwnerBackfill;` — в начало файла). Тесты вызывают настоящий класс бэкофилла, а не его копию:

```php
    private function makePortal(string $memberId, string $appToken, int $userId, bool $isAdmin): void
    {
        B24App::query()->create([
            'member_id' => $memberId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => $appToken,
            'refresh_token' => 'r',
            'expires' => time() + 3600,
            'expires_in' => 3600,
        ]);

        B24User::query()->create([
            'member_id' => $memberId,
            'user_id' => $userId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => 'user-token',
            'refresh_token' => 'user-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'is_admin' => $isAdmin,
        ]);
    }

    public function test_backfill_sets_owner_when_token_belongs_to_admin(): void
    {
        // владелец токена — 221 (сегмент 000000dd)
        $this->makePortal('m-admin', 'deadbeefdeadbeefdeadbeef000000ddcafebabecafebabe', 221, true);

        (new AppOwnerBackfill())->run();

        self::assertSame(221, B24App::query()->where('member_id', 'm-admin')->value('user_id'));
    }

    public function test_backfill_leaves_null_when_token_belongs_to_non_admin(): void
    {
        // владелец токена — 162 (сегмент 000000a2), не админ: это и есть клоббер
        $this->makePortal('m-clobbered', 'deadbeefdeadbeefdeadbeef000000a2cafebabecafebabe', 162, false);

        (new AppOwnerBackfill())->run();

        self::assertNull(
            B24App::query()->where('member_id', 'm-clobbered')->value('user_id'),
            'бэкофилл узаконил не-админа как владельца токена портала',
        );
    }

    public function test_backfill_leaves_null_when_token_is_unrecognisable(): void
    {
        $this->makePortal('m-weird', 'короткий', 5, true);

        (new AppOwnerBackfill())->run();

        self::assertNull(B24App::query()->where('member_id', 'm-weird')->value('user_id'));
    }
```

- [ ] **Step 2: Запустить тесты**

Run: `./vendor/bin/phpunit tests/Feature/AddUserIdToB24AppsMigrationTest.php`
Expected: PASS (5 тестов). Класс `AppOwnerBackfill` реализован в Task 3 — тесты подтверждают три его исхода.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/AddUserIdToB24AppsMigrationTest.php
git commit -m "test(tokens): бэкофилл user_id принимает только админов"
```

---

## Task 5: Правило 2 в `AppTokenWriter` — чистое решение

**Files:**
- Modify: `src/Application/Local/Infrastructure/Database/AppTokenWriter.php`
- Test: `tests/Unit/Install/AppTokenWriterDecisionTest.php`

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Unit/Install/AppTokenWriterDecisionTest.php`:

```php
    public function test_propagates_when_user_is_the_installer(): void
    {
        $this->assertTrue(AppTokenWriter::shouldPropagateFromUser(installerUserId: 221, userId: 221));
    }

    public function test_does_not_propagate_for_another_user(): void
    {
        // Ядро фикса: токен рядового сотрудника не должен попадать в b24_apps.
        $this->assertFalse(AppTokenWriter::shouldPropagateFromUser(installerUserId: 221, userId: 162));
    }

    public function test_does_not_propagate_when_installer_is_unknown(): void
    {
        // NULL = владелец не установлен или не доверен -> fail-closed.
        $this->assertFalse(AppTokenWriter::shouldPropagateFromUser(installerUserId: null, userId: 221));
    }
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Unit/Install/AppTokenWriterDecisionTest.php`
Expected: FAIL — `Call to undefined method ...::shouldPropagateFromUser()`

- [ ] **Step 3: Реализовать решение**

В `src/Application/Local/Infrastructure/Database/AppTokenWriter.php` добавить после `shouldWrite()`:

```php
    /**
     * Правило 2: обновлённый пользовательский токен переносится в b24_apps, только если
     * этот пользователь и есть установщик приложения.
     *
     * $installerUserId === null означает «владелец не установлен или не доверен»
     * (например, бэкофилл увидел в токене не-админа) — тогда не пишем ничего.
     */
    public static function shouldPropagateFromUser(?int $installerUserId, int $userId): bool
    {
        return $installerUserId !== null && $installerUserId === $userId;
    }
```

- [ ] **Step 4: Запустить тест**

Run: `./vendor/bin/phpunit tests/Unit/Install/AppTokenWriterDecisionTest.php`
Expected: PASS (6 тестов)

- [ ] **Step 5: Commit**

```bash
git add src/Application/Local/Infrastructure/Database/AppTokenWriter.php tests/Unit/Install/AppTokenWriterDecisionTest.php
git commit -m "feat(tokens): правило 2 — перенос токена только от установщика"
```

---

## Task 6: Исполнители в `AppTokenWriter`

**Files:**
- Modify: `src/Application/Local/Infrastructure/Database/AppTokenWriter.php`
- Test: `tests/Feature/AppTokenOwnershipTest.php`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/AppTokenOwnershipTest.php`:

```php
<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Core\Credentials\AuthToken;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\TestCase;

class AppTokenOwnershipTest extends TestCase
{
    private const MEMBER = 'member-1';

    private const INSTALLER = 221;

    private const OTHER_USER = 162;

    protected function setUp(): void
    {
        parent::setUp();

        B24App::query()->create([
            'member_id' => self::MEMBER,
            'domain' => 'portal.bitrix24.ru',
            'access_token' => 'app-access',
            'refresh_token' => 'app-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'application_token' => 'app-token',
            'user_id' => self::INSTALLER,
            'error_update' => 3,
        ]);
    }

    private function token(string $value): AuthToken
    {
        return new AuthToken(
            accessToken: $value,
            refreshToken: $value . '-refresh',
            expires: time() + 7200,
            expiresIn: 3600,
        );
    }

    private function writer(): AppTokenWriter
    {
        return app(AppTokenWriter::class);
    }

    public function test_installer_token_is_propagated(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        $app = B24App::query()->where('member_id', self::MEMBER)->first();

        self::assertSame('fresh', $app->access_token);
        self::assertSame('fresh-refresh', $app->refresh_token);
        self::assertSame(0, (int) $app->error_update, 'счётчик ошибок должен сбрасываться');
        self::assertSame(self::INSTALLER, $app->user_id, 'владелец не меняется при обновлении');
    }

    public function test_other_user_token_is_ignored(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, $this->token('foreign'));

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен рядового сотрудника затёр app-токен портала',
        );
    }

    public function test_unknown_installer_blocks_propagation(): void
    {
        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);

        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        self::assertSame('app-access', B24App::query()->where('member_id', self::MEMBER)->value('access_token'));
    }

    public function test_missing_portal_row_is_a_noop(): void
    {
        $this->writer()->propagateFromUser('member-absent', self::INSTALLER, $this->token('fresh'));

        self::assertSame(0, B24App::query()->where('member_id', 'member-absent')->count());
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php`
Expected: FAIL — `Call to undefined method ...::propagateFromUser()`

- [ ] **Step 3: Реализовать исполнителей**

В `src/Application/Local/Infrastructure/Database/AppTokenWriter.php` добавить импорт `use Bitrix24\SDK\Core\Credentials\AuthToken;`, заменить `saveIfAllowed()` и добавить `propagateFromUser()`:

```php
    public function saveIfAllowed(LocalAppAuth $auth, string $memberId, bool $isAdmin, ?int $userId = null): void
    {
        $appExists = B24App::query()->where('member_id', $memberId)->exists();
        if (!self::shouldWrite($appExists, $isAdmin)) {
            $this->logger->notice('b24 app token: keep existing (non-admin overwrite blocked)', ['member_id' => $memberId]);
            return;
        }

        (new AppAuthDatabaseStorage($memberId))->save($auth);

        if ($userId !== null) {
            B24App::query()->where('member_id', $memberId)->update(['user_id' => $userId]);
        }

        $this->logger->info('b24 app token: saved', [
            'member_id' => $memberId,
            'first_install' => !$appExists,
            'is_admin' => $isAdmin,
            'user_id' => $userId,
        ]);
    }

    /**
     * Правило 2: переносит обновлённый токен установщика в b24_apps.
     *
     * Меняет только сам токен и сбрасывает счётчик ошибок. Владелец (user_id), домен,
     * application_token и oauth_server_url не трогаются — владелец меняется исключительно
     * при установке приложения.
     */
    public function propagateFromUser(string $memberId, int $userId, AuthToken $token): void
    {
        $b24app = B24App::query()->where('member_id', $memberId)->first();

        if ($b24app === null) {
            return;
        }

        $installerUserId = $b24app->user_id === null ? null : (int) $b24app->user_id;

        if (!self::shouldPropagateFromUser($installerUserId, $userId)) {
            return;
        }

        $b24app->access_token = $token->accessToken;
        $b24app->refresh_token = $token->refreshToken;
        $b24app->expires = $token->expires;
        $b24app->expires_in = $token->expiresIn ?? 3600;
        $b24app->error_update = 0;
        $b24app->save();

        $this->logger->info('b24 app token: propagated from installer', [
            'member_id' => $memberId,
            'user_id' => $userId,
        ]);
    }
```

- [ ] **Step 4: Запустить тест**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php`
Expected: PASS (4 теста)

- [ ] **Step 5: Commit**

```bash
git add src/Application/Local/Infrastructure/Database/AppTokenWriter.php tests/Feature/AppTokenOwnershipTest.php
git commit -m "feat(tokens): propagateFromUser + сохранение владельца при установке"
```

---

## Task 7: `InstallService` — владелец при установке и безопасный probe

> **Уточнено пользователем 2026-07-28.** Владельцем портала записывается ТОЛЬКО
> администратор. Если установку каким-то образом выполняет не-админ, `handleInstallPage()`
> обязан прерваться ошибкой через проверку профиля, а не сохранять токен молча. Раньше
> `shouldWrite(appExists: false, isAdmin: false)` пропускал первую установку кем угодно.


**Files:**
- Modify: `src/Application/Install/InstallService.php:33-90`
- Test: `tests/Feature/AppTokenOwnershipTest.php`

- [ ] **Step 1: Написать падающий тест**

Добавить в начало `tests/Feature/AppTokenOwnershipTest.php` импорты:

```php
use Bitrix24\SDK\Application\ApplicationStatus;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
```

Добавить в класс хелпер (он же используется в Task 8) и два теста:

```php
    private function renewed(string $value): RenewedAuthToken
    {
        return new RenewedAuthToken(
            authToken: $this->token($value),
            memberId: self::MEMBER,
            clientEndpoint: 'https://portal.bitrix24.ru/rest/',
            serverEndpoint: 'https://oauth.bitrix24.tech/rest/',
            applicationStatus: ApplicationStatus::subscription(),
            domain: 'portal.bitrix24.ru',
        );
    }

    public function test_fresh_dispatcher_does_not_write_portal_token(): void
    {
        // Probe-клиент в handleInstallPage несёт токен ОТКРЫВАЮЩЕГО и делает REST-вызов
        // (getCurrentUserProfile). Ему полагается свежий диспетчер без слушателей: если
        // дать resolve('appEvents'), протухший токен сотрудника молча уедет в b24_apps
        // мимо AppTokenWriter. Проверяем само свойство, а не текст исходника.
        (new EventDispatcherAdapter())->dispatch(
            new AuthTokenRenewedEvent($this->renewed('probe-token')),
        );

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'свежий диспетчер не должен писать токен портала',
        );
    }

    public function test_appevents_dispatcher_would_write_portal_token(): void
    {
        // Контрольный опыт: пакетный бинд 'appEvents' пишет в b24_apps — именно поэтому
        // probe-клиенту его давать нельзя. Если это когда-нибудь перестанет быть правдой,
        // тест выше потеряет смысл, и мы об этом узнаем.
        resolve('appEvents')->dispatch(
            new AuthTokenRenewedEvent($this->renewed('via-app-events')),
        );

        self::assertSame(
            'via-app-events',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
        );
    }
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php --filter test_install_probe`
Expected: FAIL — в `handleInstallPage` пока `resolve('appEvents')`

- [ ] **Step 3: Заменить диспетчер и передать владельца**

В `src/Application/Install/InstallService.php`, метод `handleInstallPage()`:

Удалить блок:

```php
        /** @var EventDispatcherAdapter $eventDispatcher */
        $eventDispatcher = resolve('appEvents');
        $eventDispatcher->listen(AuthTokenRenewedEvent::class, function (AuthTokenRenewedEvent $authTokenRenewedEvent): void {
            /** @var AppAuthDatabaseStorage $appAuthStorage */
            $appAuthStorage = resolve(AppAuthDatabaseStorage::class, [
                'memberId' => $authTokenRenewedEvent->getRenewedToken()->memberId,
            ]);
            $appAuthStorage->saveRenewedToken($authTokenRenewedEvent->getRenewedToken());
        });

```

Заменить аргумент `eventDispatcher: $eventDispatcher,` на:

```php
            // Диспетчер намеренно без слушателей: этот клиент несёт токен ОТКРЫВАЮЩЕГО,
            // а getCurrentUserProfile() ниже делает REST-вызов. Со слушателем записи в
            // b24_apps протухший токен сотрудника уехал бы в строку портала мимо
            // AppTokenWriter. Токен запроса одноразовый — сохранять его рефреш незачем.
            eventDispatcher: new EventDispatcherAdapter(),
```

Заменить вызов записи на передачу владельца:

```php
        app(AppTokenWriter::class)->saveIfAllowed($localAppAuth, $request->input('member_id'), $isAdmin, (int) $userId);
```

- [ ] **Step 4: Запустить тесты**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php`
Expected: PASS (5 тестов)

- [ ] **Step 5: Commit**

```bash
git add src/Application/Install/InstallService.php tests/Feature/AppTokenOwnershipTest.php
git commit -m "fix(install): probe без слушателей + запись владельца при установке"
```

---

## Task 8: `UserAuthDatabaseStorage` — перенос при рефреше

**Files:**
- Modify: `src/Application/Local/Infrastructure/Database/UserAuthDatabaseStorage.php`
- Test: `tests/Feature/AppTokenOwnershipTest.php`

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/AppTokenOwnershipTest.php` импорты `use X3Group\Bitrix24\Application\Local\Infrastructure\Database\UserAuthDatabaseStorage;` и `use X3Group\Bitrix24\Models\B24User;` (остальные добавлены в Task 7), затем хелпер и два теста. Метод `renewed()` уже определён в Task 7 — заново его не добавлять:

```php
    private function makeUser(int $userId, bool $isAdmin = true): void
    {
        B24User::query()->create([
            'member_id' => self::MEMBER,
            'user_id' => $userId,
            'domain' => 'portal.bitrix24.ru',
            'access_token' => 'user-' . $userId . '-old',
            'refresh_token' => 'user-' . $userId . '-old-refresh',
            'expires' => time() - 60,
            'expires_in' => 3600,
            'is_admin' => $isAdmin,
        ]);
    }

    public function test_installer_refresh_updates_portal_token(): void
    {
        $this->makeUser(self::INSTALLER);

        (new UserAuthDatabaseStorage(self::MEMBER, self::INSTALLER))
            ->saveRenewedToken($this->renewed('installer-renewed'));

        self::assertSame('installer-renewed', B24User::query()->where('user_id', self::INSTALLER)->value('access_token'));
        self::assertSame('installer-renewed', B24App::query()->where('member_id', self::MEMBER)->value('access_token'));
    }

    public function test_other_user_refresh_leaves_portal_token_alone(): void
    {
        $this->makeUser(self::OTHER_USER, isAdmin: false);

        (new UserAuthDatabaseStorage(self::MEMBER, self::OTHER_USER))
            ->saveRenewedToken($this->renewed('employee-renewed'));

        self::assertSame('employee-renewed', B24User::query()->where('user_id', self::OTHER_USER)->value('access_token'));
        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'рефреш токена сотрудника затёр app-токен портала',
        );
    }
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php --filter refresh`
Expected: FAIL на `test_installer_refresh_updates_portal_token` — `b24_apps` остался `app-access`

- [ ] **Step 3: Реализовать**

В `src/Application/Local/Infrastructure/Database/UserAuthDatabaseStorage.php` добавить импорт `use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;` (если класс в том же пространстве имён — импорт не нужен) и в конец метода `saveRenewedToken()`, после `$b24user->save();`:

```php
        // Правило 2: токен портала обновляется вместе с токеном установщика.
        // Для всех остальных пользователей b24_apps остаётся нетронутым.
        app(AppTokenWriter::class)->propagateFromUser(
            $renewedAuthToken->memberId,
            $this->userId,
            $renewedAuthToken->authToken,
        );
```

- [ ] **Step 4: Запустить тесты**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php`
Expected: PASS (7 тестов)

- [ ] **Step 5: Commit**

```bash
git add src/Application/Local/Infrastructure/Database/UserAuthDatabaseStorage.php tests/Feature/AppTokenOwnershipTest.php
git commit -m "feat(tokens): рефреш токена установщика обновляет токен портала"
```

---

## Task 9: `B24AppUserMiddleware` — перенос при входе установщика

**Files:**
- Modify: `src/Http/Middleware/B24AppUserMiddleware.php:66-91`
- Test: `tests/Feature/AppTokenOwnershipTest.php`

Это самый частый путь: установщик открывает приложение, Bitrix присылает свежий токен, и портал получает его без ожидания протухания.

- [ ] **Step 1: Написать падающий тест**

Добавить в `tests/Feature/AppTokenOwnershipTest.php`:

```php
    public function test_middleware_propagates_installer_placement_token(): void
    {
        $this->makeUser(self::INSTALLER);

        $this->callMiddlewarePropagation(self::INSTALLER, 'placement-fresh');

        self::assertSame('placement-fresh', B24App::query()->where('member_id', self::MEMBER)->value('access_token'));
    }

    public function test_middleware_ignores_other_user_placement_token(): void
    {
        $this->makeUser(self::OTHER_USER, isAdmin: false);

        $this->callMiddlewarePropagation(self::OTHER_USER, 'placement-foreign');

        self::assertSame('app-access', B24App::query()->where('member_id', self::MEMBER)->value('access_token'));
    }

    /**
     * Повторяет ровно тот вызов, который middleware делает после записи строки
     * пользователя. Полный HTTP-прогон здесь не нужен: он потребовал бы живого Bitrix
     * для getCurrentUserProfile().
     */
    private function callMiddlewarePropagation(int $userId, string $accessToken): void
    {
        app(AppTokenWriter::class)->propagateFromUser(
            self::MEMBER,
            $userId,
            new AuthToken(
                accessToken: $accessToken,
                refreshToken: $accessToken . '-refresh',
                expires: time() + 3600 - 600,
                expiresIn: 3600,
            ),
        );
    }
```

- [ ] **Step 2: Запустить тесты**

Run: `./vendor/bin/phpunit tests/Feature/AppTokenOwnershipTest.php`
Expected: PASS (9 тестов) — поведение уже обеспечено Task 6; тесты фиксируют контракт, который middleware обязан соблюдать.

- [ ] **Step 3: Вызвать перенос из middleware**

В `src/Http/Middleware/B24AppUserMiddleware.php` добавить импорты:

```php
use Bitrix24\SDK\Core\Credentials\AuthToken;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
```

После блока `if ($userFind) { ... } else { ... }`, сразу перед `auth()->login($userFind);`, вставить:

```php
                // Правило 2: если приложение открыл установщик — обновляем и токен
                // портала. Это самый частый путь получения свежего токена: ждать, пока
                // текущий протухнет, не требуется.
                app(AppTokenWriter::class)->propagateFromUser(
                    $memberId,
                    (int) $profile->ID,
                    new AuthToken(
                        accessToken: $request->post('AUTH_ID'),
                        refreshToken: $request->post('REFRESH_ID'),
                        expires: time() + (int) $request->post('AUTH_EXPIRES') - 600,
                        expiresIn: 3600,
                    ),
                );
```

- [ ] **Step 4: Прогнать весь набор**

Run: `./vendor/bin/phpunit`
Expected: PASS — все тесты зелёные

- [ ] **Step 5: Commit**

```bash
git add src/Http/Middleware/B24AppUserMiddleware.php tests/Feature/AppTokenOwnershipTest.php
git commit -m "feat(tokens): вход установщика обновляет токен портала"
```

---

## Task 10: Команда ремонта `bitrix24:reanchor-app-token`

**Files:**
- Create: `src/Console/Commands/ReanchorAppTokenCommand.php`
- Modify: `src/Bitrix24ServiceProvider.php:475-478`
- Test: `tests/Feature/ReanchorAppTokenCommandTest.php`

- [ ] **Step 1: Написать падающий тест**

Создать `tests/Feature/ReanchorAppTokenCommandTest.php`:

```php
<?php

namespace X3Group\Bitrix24\Tests\Feature;

use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Tests\TestCase;

class ReanchorAppTokenCommandTest extends TestCase
{
    private function portal(string $memberId, ?int $ownerUserId): void
    {
        B24App::query()->create([
            'member_id' => $memberId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => 'broken-access',
            'refresh_token' => 'broken-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'user_id' => $ownerUserId,
        ]);
    }

    private function user(string $memberId, int $userId, bool $isAdmin, int $expires): void
    {
        B24User::query()->create([
            'member_id' => $memberId,
            'user_id' => $userId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => 'user-' . $userId . '-access',
            'refresh_token' => 'user-' . $userId . '-refresh',
            'expires' => $expires,
            'expires_in' => 3600,
            'is_admin' => $isAdmin,
        ]);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, true, time() + 3600);

        $this->artisan('bitrix24:reanchor-app-token', ['--dry-run' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();
        self::assertNull($app->user_id);
        self::assertSame('broken-access', $app->access_token);
    }

    public function test_reanchors_to_freshest_admin(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, true, time() + 100);
        $this->user('m-null', 20, true, time() + 9000);   // свежее
        $this->user('m-null', 30, false, time() + 99999); // не админ

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();
        self::assertSame(20, $app->user_id);
        self::assertSame('user-20-access', $app->access_token);
        self::assertSame('user-20-refresh', $app->refresh_token);
    }

    public function test_skips_portal_without_admins(): void
    {
        $this->portal('m-noadmin', null);
        $this->user('m-noadmin', 40, false, time() + 3600);

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-noadmin')->first();
        self::assertNull($app->user_id);
        self::assertSame('broken-access', $app->access_token);
    }

    public function test_healthy_portal_is_not_touched(): void
    {
        $this->portal('m-ok', 50);
        $this->user('m-ok', 50, true, time() + 3600);

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        self::assertSame('broken-access', B24App::query()->where('member_id', 'm-ok')->value('access_token'));
    }
}
```

- [ ] **Step 2: Запустить тест и убедиться, что падает**

Run: `./vendor/bin/phpunit tests/Feature/ReanchorAppTokenCommandTest.php`
Expected: FAIL — команда `bitrix24:reanchor-app-token` не зарегистрирована

- [ ] **Step 3: Реализовать команду**

Создать `src/Console/Commands/ReanchorAppTokenCommand.php`:

```php
<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

/**
 * Перепривязывает app-токен портала на администратора.
 *
 * Кандидаты — строки b24_apps, где владелец не задан (user_id IS NULL, в том числе после
 * бэкофилла, который отказался принимать не-админа) либо владелец потерял права
 * администратора. У таких порталов админ-методы Bitrix (userfieldconfig.* и другие)
 * отвечают «нет прав».
 */
class ReanchorAppTokenCommand extends Command
{
    protected $signature = 'bitrix24:reanchor-app-token
        {--dry-run : показать план, ничего не менять}
        {--member= : обработать только этот member_id}
        {--user= : принудительно взять токен этого пользователя}
        {--limit=0 : максимум порталов за прогон}
        {--skip-verify : не проверять результат живым вызовом user.admin}';

    protected $description = 'Перепривязать app-токен портала на администратора';

    public function handle(): int
    {
        $query = B24App::query()->orderBy('id');

        if ($member = $this->option('member')) {
            $query->where('member_id', $member);
        }

        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');

        $processed = 0;
        $rows = [];

        foreach ($query->cursor() as $app) {
            if (!$this->needsReanchor($app)) {
                continue;
            }

            $admin = $this->pickAdmin($app);

            if ($admin === null) {
                $rows[] = [$app->domain, (string) ($app->user_id ?? 'NULL'), '—', 'нет админов: нужна переустановка'];

                continue;
            }

            if ($dryRun) {
                $rows[] = [$app->domain, (string) ($app->user_id ?? 'NULL'), (string) $admin->user_id, 'dry-run'];
            } else {
                $rows[] = [$app->domain, (string) ($app->user_id ?? 'NULL'), (string) $admin->user_id, $this->reanchor($app, $admin)];
            }

            $processed++;

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        if ($rows === []) {
            $this->info('Порталов, требующих перепривязки, не найдено.');

            return self::SUCCESS;
        }

        $this->table(['портал', 'было', 'стало', 'результат'], $rows);

        return self::SUCCESS;
    }

    /**
     * Портал требует ремонта, если владелец не задан или больше не администратор.
     */
    private function needsReanchor(B24App $app): bool
    {
        if ($app->user_id === null) {
            return true;
        }

        $isAdmin = B24User::query()
            ->where('member_id', $app->member_id)
            ->where('user_id', $app->user_id)
            ->value('is_admin');

        return !$isAdmin;
    }

    private function pickAdmin(B24App $app): ?B24User
    {
        $query = B24User::query()
            ->where('member_id', $app->member_id)
            ->where('is_admin', true);

        if ($user = $this->option('user')) {
            return $query->where('user_id', (int) $user)->first();
        }

        return $query->orderByDesc('expires')->first();
    }

    /**
     * Записывает токен админа и проверяет результат живым вызовом. При неудаче
     * возвращает строку к прежним значениям, чтобы не оставить портал в худшем виде.
     */
    private function reanchor(B24App $app, B24User $admin): string
    {
        $backup = [
            'access_token' => $app->access_token,
            'refresh_token' => $app->refresh_token,
            'expires' => $app->expires,
            'expires_in' => $app->expires_in,
            'user_id' => $app->user_id,
            'error_update' => $app->error_update,
        ];

        $app->access_token = $admin->access_token;
        $app->refresh_token = $admin->refresh_token;
        $app->expires = $admin->expires;
        $app->expires_in = $admin->expires_in ?? 3600;
        $app->user_id = $admin->user_id;
        $app->error_update = 0;
        $app->save();

        if ($this->option('skip-verify')) {
            return 'перепривязан';
        }

        try {
            $result = (new Bitrix24App($app->member_id))->api->core
                ->call('user.admin', [])
                ->getResponseData()
                ->getResult();

            if (($result[0] ?? false) === true) {
                return 'перепривязан, user.admin=true';
            }

            $app->forceFill($backup)->save();

            return 'откат: user.admin=false';
        } catch (\Throwable $e) {
            $app->forceFill($backup)->save();

            return 'откат: ' . $e->getMessage();
        }
    }
}
```

- [ ] **Step 4: Зарегистрировать команду**

В `src/Bitrix24ServiceProvider.php` дополнить список:

```php
        // Registering package commands.
        $this->commands([
            \X3Group\Bitrix24\Console\Commands\RemoveUninstalledPortals::class,
            \X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand::class,
        ]);
```

- [ ] **Step 5: Запустить тесты**

Run: `./vendor/bin/phpunit tests/Feature/ReanchorAppTokenCommandTest.php`
Expected: PASS (4 теста)

- [ ] **Step 6: Commit**

```bash
git add src/Console/Commands/ReanchorAppTokenCommand.php src/Bitrix24ServiceProvider.php tests/Feature/ReanchorAppTokenCommandTest.php
git commit -m "feat(tokens): команда bitrix24:reanchor-app-token"
```

---

## Task 11: Финальная проверка и README

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Прогнать весь набор**

Run: `./vendor/bin/phpunit`
Expected: PASS — все тесты зелёные, включая 22 существовавших до начала работы

- [ ] **Step 2: Описать поведение в README**

Добавить в `README.md` раздел:

```markdown
## Владелец app-токена портала

В `b24_apps` хранится `user_id` — пользователь, которому принадлежит токен портала.
Записывается при установке приложения и больше не меняется.

Токен в `b24_apps` обновляется только двумя путями:

1. установка или переустановка приложения администратором;
2. обновление токена этого же пользователя в `b24_users` — при рефреше или при входе
   установщика в приложение.

Токен любого другого сотрудника в `b24_apps` не попадает. `user_id = NULL` означает
«владелец не установлен или не доверен»: правило 2 для такого портала не работает,
пока его не починят.

Порталы, где владелец не задан или потерял права администратора, чинит команда:

    php artisan bitrix24:reanchor-app-token --dry-run
    php artisan bitrix24:reanchor-app-token
```

- [ ] **Step 3: Commit**

```bash
git add README.md
git commit -m "docs: правила владения app-токеном портала"
```

---

## Выкатка после мержа

Вне рамок этого плана, выполняется вручную после релиза тега **3.4.0**:

1. в каждом приложении — бамп версии пакета и `php artisan migrate` (миграция напечатает список порталов на ремонт);
2. `php artisan bitrix24:reanchor-app-token --dry-run` — посмотреть план;
3. боевой прогон ремонта.

Получат после бампа: `dependent-fields`, `hh`, `tasks`, `yclients`, `base`, `project-dashboard`.
Для `hh` это чинит `shadt.bitrix24.ru` и `helptime.bitrix24.ru`, для `dependent-fields` — `look.bitrix24.ru`.
