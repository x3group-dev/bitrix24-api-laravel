<?php

namespace X3Group\Bitrix24\Tests\Feature;

use X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingReanchorCommand;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Ремонт порталов, у которых app-токен принадлежит не тому человеку.
 *
 * Проверяется не «команда что-то сделала», а три свойства, без которых она бесполезна
 * или опасна:
 *
 *  - ремонт означает ЗАПИСЬ ТОКЕНА, а не только колонки user_id. Перепривязать владельца
 *    и оставить сломанный токен — это ремонт, который ничего не чинит: правило 2 донесёт
 *    свежий токен до портала только когда новый владелец сам откроет приложение, а он
 *    может не открывать его никогда;
 *  - владельцем становится только администратор. Гейт здесь свой (команда пишет в
 *    b24_apps в обход AppTokenWriter::saveIfAllowed), поэтому он закреплён отдельно;
 *  - неудачная проверка возвращает строку РОВНО в прежний вид. Полуоткат оставил бы
 *    портал в состоянии, которого не было ни до, ни после.
 */
class ReanchorAppTokenCommandTest extends TestCase
{
    /**
     * Значения «сломанного» портала: их же ожидаем увидеть после dry-run и после отката.
     *
     * Каждая колонка обязана отличаться от того, что придёт из b24_users (там
     * expires_in = 3600, а expires — заметно дальше). Совпадающее значение сделало бы
     * забытую при откате колонку невидимой: строка выглядела бы восстановленной по
     * случайности, а не потому, что её восстановили.
     */
    private const BROKEN = [
        'access_token' => 'broken-access',
        'refresh_token' => 'broken-refresh',
        'expires_in' => 900,
        'error_update' => 7,
    ];

    private const COLUMNS = ['access_token', 'refresh_token', 'expires', 'expires_in', 'user_id', 'error_update'];

    /**
     * Проверка подменена НА УСПЕШНУЮ намеренно. С настоящей (недоступной в тесте) живой
     * проверкой этот тест ничего не доказывал бы: реализация, которая пишет и на dry-run,
     * упала бы на вызове Битрикса и сама же откатила строку — снимок сошёлся бы, хотя
     * запись была. Успешный вердикт откат отменяет, и лишняя запись остаётся видимой.
     */
    public function test_dry_run_writes_nothing_at_all(): void
    {
        $command = $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-null');

        $this->artisan('bitrix24:reanchor-app-token', ['--dry-run' => true])->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-null'), 'dry-run изменил строку портала');
        self::assertSame(0, $command->verifyCalls, 'dry-run сходил в Битрикс: прогон «посмотреть» бьёт по проду');
    }

    public function test_reanchors_to_the_freshest_admin_writing_both_the_token_and_the_owner(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 100);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);   // свежее
        $this->user('m-null', 30, isAdmin: false, expires: time() + 99999); // не админ, но свежее всех

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(20, $app->user_id, 'владельцем стал не самый свежий администратор');
        self::assertSame(
            'user-20-access',
            $app->access_token,
            'перепривязали владельца, а токен оставили сломанным: портал так и не починен',
        );
        self::assertSame('user-20-refresh', $app->refresh_token, 'refresh-токен остался от прежнего владельца');
        self::assertSame(
            0,
            (int) $app->error_update,
            'счётчик ошибок не сброшен: при error_update >= 10 портал исключён из ротации обновления токенов, '
            . 'то есть починенный портал остался бы мёртвым',
        );
    }

    /**
     * Колонка expires в b24_apps — АБСОЛЮТНЫЙ timestamp (так её читают
     * {@see \X3Group\Bitrix24\Console\Commands\RemoveUninstalledPortals} и
     * {@see \X3Group\Bitrix24\Bitrix24App::renewTokens}), и ровно такой же смысл у
     * b24_users.expires (B24AppUserMiddleware пишет туда time() + AUTH_EXPIRES - 600).
     * Значит копия переносится как есть.
     *
     * Ловушка рядом: путь AppAuthDatabaseStorage::save() считает expires СРОКОМ ЖИЗНИ и
     * пишет now() + expires. Прогнать через него уже абсолютное значение — значит сложить
     * два timestamp-а и припарковать портал в 2083 году, откуда его не вычистит даже
     * уборщик мёртвых порталов.
     */
    public function test_the_written_expiry_stays_an_absolute_timestamp(): void
    {
        $adminExpires = time() + 3000;

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: $adminExpires);

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame($adminExpires, (int) $app->expires, 'срок жизни токена доехал до колонки не как есть');
        self::assertLessThan(
            time() + 86400,
            (int) $app->expires,
            'expires уехал в будущее: абсолютный timestamp прогнали через now()->addSeconds()',
        );
        self::assertSame(3600, (int) $app->expires_in);
    }

    public function test_portal_without_any_admin_is_reported_and_left_alone(): void
    {
        $this->portal('m-noadmin', null);
        $this->user('m-noadmin', 40, isAdmin: false, expires: time() + 3600);

        $before = $this->snapshot('m-noadmin');

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])
            ->expectsOutputToContain('нужна переустановка')
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-noadmin'), 'портал без администраторов всё-таки тронули');
    }

    public function test_healthy_portal_is_not_a_candidate(): void
    {
        $this->portal('m-ok', 50);
        $this->user('m-ok', 50, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-ok');

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])
            ->expectsOutputToContain('не найдено')
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-ok'), 'здоровый портал перепривязали заново');
    }

    /**
     * Вторая половина условия отбора: владелец записан, но админом быть перестал.
     * Такой портал внешне здоров (user_id не NULL), а админ-методы у него уже отвечают
     * «нет прав» — без этой ветки он остался бы невидимым для ремонта навсегда.
     */
    public function test_owner_who_lost_admin_rights_is_reanchored(): void
    {
        $this->portal('m-demoted', 50);
        $this->user('m-demoted', 50, isAdmin: false, expires: time() + 9999);
        $this->user('m-demoted', 60, isAdmin: true, expires: time() + 3600);

        $this->artisan('bitrix24:reanchor-app-token', ['--skip-verify' => true])->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-demoted')->first();

        self::assertSame(60, $app->user_id);
        self::assertSame('user-60-access', $app->access_token);
    }

    public function test_user_option_overrides_the_automatic_choice(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 100);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000); // выбрали бы его

        $this->artisan('bitrix24:reanchor-app-token', ['--user' => 10, '--skip-verify' => true])
            ->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(10, $app->user_id, '--user не перебил автоматический выбор');
        self::assertSame('user-10-access', $app->access_token);
    }

    /**
     * Гейт админства (ловушка №1). Команда пишет в b24_apps сама, поэтому проверка
     * AppTokenWriter::shouldWrite вызывается здесь явно; если её убрать, оператор одной
     * опечаткой в --user узаконит рядового сотрудника владельцем портала — то есть
     * ровно ту поломку, ради которой команда и написана.
     *
     * Отбор кандидата под --user специально идёт БЕЗ фильтра is_admin: отказ обязан
     * приходить от гейта, иначе «не админ» и «нет такой строки» слились бы в один ответ,
     * а сам гейт остался бы непроверенным.
     */
    public function test_user_option_refuses_a_non_admin(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);
        $this->user('m-null', 30, isAdmin: false, expires: time() + 99999);

        $before = $this->snapshot('m-null');

        $this->artisan('bitrix24:reanchor-app-token', ['--user' => 30, '--skip-verify' => true])
            ->expectsOutputToContain('не администратор')
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-null'), 'не-админа записали владельцем портала');
    }

    public function test_user_option_pointing_at_an_unknown_user_writes_nothing(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);

        $before = $this->snapshot('m-null');

        $this->artisan('bitrix24:reanchor-app-token', ['--user' => 999, '--skip-verify' => true])
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-null'), '--user с несуществующим пользователем что-то записал');
    }

    /**
     * Ради чего вообще нужен откат: неудачная проверка обязана вернуть строку РОВНО в
     * прежний вид. Проверяются все шесть колонок разом — забытая при откате колонка
     * (error_update, expires_in) оставит портал в состоянии, которого не было ни до
     * ремонта, ни после.
     */
    public function test_rollback_restores_every_column_when_verification_says_not_admin(): void
    {
        $this->useCommand(verdict: false);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-null');

        $this->artisan('bitrix24:reanchor-app-token')
            ->expectsOutputToContain('откат')
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-null'), 'после отката строка портала отличается от исходной');
    }

    public function test_rollback_restores_every_column_when_verification_throws(): void
    {
        $this->useCommand(verdict: new \RuntimeException('portal is unreachable'));

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-null');

        $this->artisan('bitrix24:reanchor-app-token')
            ->expectsOutputToContain('откат')
            ->assertExitCode(0);

        self::assertSame($before, $this->snapshot('m-null'), 'исключение проверки оставило портал недочиненным');
    }

    /**
     * Успешная проверка — единственный случай, когда запись остаётся. Зеркало обоих
     * тестов отката: без него «откат всегда» проходил бы их оба.
     */
    public function test_successful_verification_keeps_the_written_token(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $this->artisan('bitrix24:reanchor-app-token')->assertExitCode(0);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(10, $app->user_id);
        self::assertSame('user-10-access', $app->access_token);
    }

    public function test_member_option_narrows_the_run_to_one_portal(): void
    {
        $this->portal('m-a', null);
        $this->user('m-a', 10, isAdmin: true, expires: time() + 3600);
        $this->portal('m-b', null);
        $this->user('m-b', 20, isAdmin: true, expires: time() + 3600);

        $untouched = $this->snapshot('m-b');

        $this->artisan('bitrix24:reanchor-app-token', ['--member' => 'm-a', '--skip-verify' => true])
            ->assertExitCode(0);

        self::assertSame(10, B24App::query()->where('member_id', 'm-a')->value('user_id'));
        self::assertSame($untouched, $this->snapshot('m-b'), '--member не ограничил прогон одним порталом');
    }

    public function test_limit_bounds_the_number_of_portals_touched(): void
    {
        $this->portal('m-a', null);
        $this->user('m-a', 10, isAdmin: true, expires: time() + 3600);
        $this->portal('m-b', null);
        $this->user('m-b', 20, isAdmin: true, expires: time() + 3600);

        $untouched = $this->snapshot('m-b');

        $this->artisan('bitrix24:reanchor-app-token', ['--limit' => 1, '--skip-verify' => true])
            ->assertExitCode(0);

        self::assertSame(10, B24App::query()->where('member_id', 'm-a')->value('user_id'));
        self::assertSame($untouched, $this->snapshot('m-b'), '--limit не ограничил число обработанных порталов');
    }

    /**
     * Подменяет команду в контейнере: живой вызов user.admin из теста недоступен, а
     * подменять его надо ДО того, как консольное приложение соберёт список команд.
     */
    private function useCommand(bool|\Throwable $verdict): RecordingReanchorCommand
    {
        $command = new RecordingReanchorCommand($verdict);

        $this->app->bind(ReanchorAppTokenCommand::class, fn() => $command);

        return $command;
    }

    /** @return array<string, mixed> */
    private function snapshot(string $memberId): array
    {
        $app = B24App::query()->where('member_id', $memberId)->first();

        $snapshot = [];

        foreach (self::COLUMNS as $column) {
            $snapshot[$column] = $app->{$column} === null ? null : (string) $app->{$column};
        }

        return $snapshot;
    }

    private function portal(string $memberId, ?int $ownerId): void
    {
        B24App::query()->create(self::BROKEN + [
            'member_id' => $memberId,
            'domain' => $memberId . '.bitrix24.ru',
            'application_token' => 'app-token',
            'expires' => time() + 111,
            'user_id' => $ownerId,
        ]);
    }

    private function user(string $memberId, int $userId, bool $isAdmin, int $expires): void
    {
        B24User::query()->create([
            'member_id' => $memberId,
            'user_id' => $userId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => "user-{$userId}-access",
            'refresh_token' => "user-{$userId}-refresh",
            'expires' => $expires,
            'expires_in' => 3600,
            'is_admin' => $isAdmin,
        ]);
    }
}
