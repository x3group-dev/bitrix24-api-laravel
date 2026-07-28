<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use X3Group\Bitrix24\Console\Commands\ReanchorAppTokenCommand;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingReanchorCommand;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Ремонт порталов, у которых app-токен принадлежит не тому человеку.
 *
 * Проверяется не «команда что-то сделала», а четыре свойства, без которых она бесполезна
 * или опасна:
 *
 *  - ремонт означает ЗАПИСЬ ТОКЕНА, а не только колонки user_id. Перепривязать владельца
 *    и оставить сломанный токен — это ремонт, который ничего не чинит: правило 2 донесёт
 *    свежий токен до портала только когда новый владелец сам откроет приложение, а он
 *    может не открывать его никогда;
 *  - трогается РОВНО ТОТ портал и берётся РОВНО ЕГО админ. user_id — маленькое целое,
 *    общее для тысяч порталов, поэтому во всех фикстурах ниже токены именованы через
 *    member_id: значение вида «m-a/user-10-access» показывает не только чей это токен, но
 *    и с какого он портала. Без этого запись чужого токена в портал — а это ровно та
 *    авария, ради которой всё писалось, — выглядела бы в тесте как успех;
 *  - владельцем становится только администратор, и только со свежим токеном;
 *  - неудачная проверка возвращает строку РОВНО в прежний вид, но не затирает того, что
 *    успело приехать в неё за время проверки.
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

    // --- dry-run ---

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

        [$exit, $out] = $this->runCommand(['--dry-run' => true]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame($before, $this->snapshot('m-null'), 'dry-run изменил строку портала');
        self::assertSame(0, $command->verifyCalls, 'dry-run сходил в Битрикс: прогон «посмотреть» бьёт по проду');
    }

    // --- ремонт ---

    public function test_reanchors_to_the_freshest_admin_writing_both_the_token_and_the_owner(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 100);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);   // свежее
        $this->user('m-null', 30, isAdmin: false, expires: time() + 99999); // не админ, но свежее всех

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(20, $app->user_id, 'владельцем стал не самый свежий администратор');
        self::assertSame(
            'm-null/user-20-access',
            $app->access_token,
            'перепривязали владельца, а токен оставили сломанным: портал так и не починен',
        );
        self::assertSame('m-null/user-20-refresh', $app->refresh_token, 'refresh-токен остался от прежнего владельца');
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
        $this->useCommand(verdict: true);

        $adminExpires = time() + 3000;

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: $adminExpires);

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame($adminExpires, (int) $app->expires, 'срок жизни токена доехал до колонки не как есть');
        self::assertLessThan(
            time() + 86400,
            (int) $app->expires,
            'expires уехал в будущее: абсолютный timestamp прогнали через now()->addSeconds()',
        );
        self::assertSame(3600, (int) $app->expires_in);
    }

    /**
     * Вторая половина условия отбора: владелец записан, но админом быть перестал.
     * Такой портал внешне здоров (user_id не NULL), а админ-методы у него уже отвечают
     * «нет прав» — без этой ветки он остался бы невидимым для ремонта навсегда.
     */
    public function test_owner_who_lost_admin_rights_is_reanchored(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-demoted', 50);
        $this->user('m-demoted', 50, isAdmin: false, expires: time() + 9999);
        $this->user('m-demoted', 60, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');

        $app = B24App::query()->where('member_id', 'm-demoted')->first();

        self::assertSame(60, $app->user_id);
        self::assertSame('m-demoted/user-60-access', $app->access_token);
    }

    public function test_healthy_portal_is_not_a_candidate(): void
    {
        $this->portal('m-ok', 50);
        $this->user('m-ok', 50, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-ok');

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('не найдено', $out);

        self::assertSame($before, $this->snapshot('m-ok'), 'здоровый портал перепривязали заново');
    }

    // --- границы портала ---

    /**
     * Портал чинится ТОЛЬКО своими администраторами.
     *
     * Здесь user_id 10 существует на обоих порталах — как на настоящем флоте, где это
     * маленькое целое общее для тысяч клиентов. У ремонтируемого портала свой админ (11),
     * а рекорд по свежести держит админ ЧУЖОГО портала.
     *
     * Выбор админа без фильтра по member_id записал бы в m-target токен портала m-foreign
     * — ровно ту аварию, ради устранения которой вся фича и написана, только теперь
     * совершённую инструментом ремонта.
     *
     * Тот же тест ловит и потерю корреляции в проверке владельца: без неё m-foreign
     * (владелец 10, админ у себя) увидел бы чужую строку (m-target, 10, не админ) и
     * поехал бы в ремонт.
     */
    public function test_a_portal_is_repaired_only_from_its_own_admins(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-target', 10);
        $this->user('m-target', 10, isAdmin: false, expires: time() + 100);
        $this->user('m-target', 11, isAdmin: true, expires: time() + 1000);

        $this->portal('m-foreign', 10);
        $this->user('m-foreign', 10, isAdmin: true, expires: time() + 99999);

        $foreignBefore = $this->snapshot('m-foreign');

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');

        $app = B24App::query()->where('member_id', 'm-target')->first();

        self::assertSame(11, $app->user_id, 'владельцем стал администратор другого портала');
        self::assertSame(
            'm-target/user-11-access',
            $app->access_token,
            'в портал записан токен ЧУЖОГО портала: выбор администратора идёт мимо member_id',
        );
        self::assertSame($foreignBefore, $this->snapshot('m-foreign'), 'здоровый чужой портал утащили в ремонт');
    }

    /**
     * Чужой администратор не спасает портал, у которого своих админов нет: такой портал
     * обязан остаться в корзине «нужна переустановка», а не поехать в ремонт.
     *
     * Проверяется по счётчикам прогона, а не по строке портала: без корреляции по
     * member_id портал уехал бы в ремонт, там не нашёл бы своего админа и в БД остался бы
     * нетронутым — по одной только строке БД подмена была бы невидима.
     */
    public function test_a_foreign_admin_does_not_rescue_a_portal_without_its_own(): void
    {
        $this->portal('m-lonely', 40);
        $this->user('m-lonely', 40, isAdmin: false, expires: time() + 3600);

        $this->portal('m-rich', 70);
        $this->user('m-rich', 70, isAdmin: true, expires: time() + 99999);

        $before = $this->snapshot('m-lonely');

        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('нужна переустановка', $out);
        self::assertStringContainsString('на переустановку: 1', $out);

        self::assertSame($before, $this->snapshot('m-lonely'), 'портал без своих админов всё-таки тронули');
    }

    /**
     * Портал, про права владельца которого у нас нет НИКАКИХ данных, не ремонтируется и —
     * главное — не объявляется требующим переустановки.
     *
     * Это не редкий угол, а норма для целого класса приложений: is_admin заполняет только
     * B24AppUserMiddleware, то есть заход на размещение. Приложение, живущее на
     * routes/b24app.php, не заполняет b24_users вовсе, поэтому «нет строки про владельца»
     * — состояние всего его флота. Отправить всех этих клиентов на переустановку значит
     * сломать работающее приложение советом. Ровно эту границу проводит и
     * {@see \X3Group\Bitrix24\Support\AppOwnerBackfill} своей корзиной unknownOwner.
     *
     * Заодно тест ловит потерю корреляции по member_id в проверке владельца: user_id 10
     * лежит не-админом на ДРУГОМ портале, и без корреляции m-api уехал бы в ремонт.
     */
    public function test_a_portal_with_no_evidence_about_its_owner_is_counted_not_repaired(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-api', 10);
        $this->user('m-api', 11, isAdmin: true, expires: time() + 1000);

        // Тот же user_id, но на другом портале и не админ.
        $this->portal('m-other', null);
        $this->user('m-other', 10, isAdmin: false, expires: time() + 1000);

        $before = $this->snapshot('m-api');

        [$exit, $out] = $this->runCommand(['--member' => 'm-api']);
        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('без данных о правах владельца: 1', $out);
        self::assertStringNotContainsString('нужна переустановка', $out);

        self::assertSame($before, $this->snapshot('m-api'), 'портал без свидетельств против владельца перепривязали');
    }

    /**
     * Приложение, у которого b24_users пуст ЦЕЛИКОМ, — и ни один его портал не объявлен
     * сломанным.
     *
     * Это не выдуманный угол, а нормальное состояние приложения на routes/b24app.php:
     * is_admin пишет только B24AppUserMiddleware, то есть заход на размещение, которого у
     * такого приложения нет вовсе. После бэкофилла его порталы делятся на два вида —
     * user_id остался NULL (владельца не подтвердили) и user_id заполнен со старой
     * установки, — и оба вида здоровы: ставил приложение администратор, прошедший живую
     * проверку в InstallService.
     *
     * Соблазн, который тест закрывает: описать «нужна переустановка» как «владелец не
     * подтверждён админом И админов нет». Формулировка выглядит эквивалентной и на всех
     * остальных фикстурах ведёт себя одинаково, но именно она отправляет на переустановку
     * весь флот такого приложения — то есть ломает работающих клиентов советом. Отсюда
     * условие построено от ПОЛОЖИТЕЛЬНОГО свидетельства: владелец найден в b24_users и там
     * написано, что он не админ.
     */
    public function test_an_api_only_portal_is_never_told_to_reinstall(): void
    {
        $this->portal('m-api-null', null);
        $this->portal('m-api-owned', 8);

        $before = [$this->snapshot('m-api-null'), $this->snapshot('m-api-owned')];

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('без данных о правах владельца: 2', $out);
        self::assertStringNotContainsString('нужна переустановка', $out);

        self::assertSame(
            $before,
            [$this->snapshot('m-api-null'), $this->snapshot('m-api-owned')],
            'портал приложения без фронтенда тронули',
        );
    }

    // --- выбор администратора ---

    public function test_user_option_overrides_the_automatic_choice(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 100);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000); // выбрали бы его

        [$exit, $out] = $this->runCommand(['--user' => 10]);
        self::assertSame(0, $exit, 'код возврата прогона');

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(10, $app->user_id, '--user не перебил автоматический выбор');
        self::assertSame('m-null/user-10-access', $app->access_token);
    }

    /**
     * Гейт админства (ловушка №1). Команда пишет в b24_apps сама, поэтому проверка
     * AppTokenWriter::shouldWrite вызывается здесь явно; если её убрать, оператор одной
     * опечаткой в --user узаконит рядового сотрудника владельцем портала — то есть ровно
     * ту поломку, ради которой команда и написана.
     *
     * Отбор кандидата под --user специально идёт БЕЗ фильтра is_admin: отказ обязан
     * приходить от гейта, иначе «не админ» и «нет такой строки» слились бы в один ответ,
     * а сам гейт остался бы непроверенным.
     */
    public function test_user_option_refuses_a_non_admin(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);
        $this->user('m-null', 30, isAdmin: false, expires: time() + 99999);

        $before = $this->snapshot('m-null');

        [$exit, $out] = $this->runCommand(['--user' => 30]);
        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('не администратор', $out);

        self::assertSame($before, $this->snapshot('m-null'), 'не-админа записали владельцем портала');
    }

    public function test_user_option_pointing_at_an_unknown_user_writes_nothing(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-null', null);
        $this->user('m-null', 20, isAdmin: true, expires: time() + 9000);

        $before = $this->snapshot('m-null');

        [$exit, $out] = $this->runCommand(['--user' => 999]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame($before, $this->snapshot('m-null'), '--user с несуществующим пользователем что-то записал');
    }

    /**
     * Админ, не заходивший в приложение дольше жизни refresh-токена, — не якорь, а мина.
     *
     * У сломанного портала токен рядового сотрудника обычно ЖИВОЙ: приложение работает,
     * не работают только админские методы. Перепривязка на давно протухшего админа
     * поменяла бы «половину функций» на «портал не отвечает вовсе», а обратного прогона
     * нет.
     */
    public function test_a_stale_admin_is_not_used_as_a_new_anchor(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-stale', null);
        $this->user('m-stale', 10, isAdmin: true, expires: time() - 40 * 86400);

        $before = $this->snapshot('m-stale');

        [$exit, $out] = $this->runCommand();

        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('нет админа со свежим токеном', $out);

        // Итог обязан сходиться, и портал обязан быть ВНЕ корзины ремонта: попав в неё, он
        // не вышел бы оттуда никогда и съедал бы бюджет каждого прогона.
        self::assertStringContainsString(
            'На ремонт: 0 (перепривязано: 0, откатов: 0, откат отменён: 0, отказов: 0,'
            . ' без кандидата: 0, план: 0), ждут входа админа: 1',
            $out,
        );

        // Переустановка тут ни при чём: достаточно, чтобы админ открыл приложение, и
        // B24AppUserMiddleware обновит его строку в b24_users.
        self::assertStringNotContainsString('нужна переустановка', $out);

        self::assertSame($before, $this->snapshot('m-stale'), 'портал перепривязали на заведомо мёртвый токен');
    }

    /**
     * Портал, у которого все админы протухли, не должен блокировать очередь.
     *
     * Он не чинится ни сегодня, ни завтра, не исчезает из БД и стоит первым по id — то
     * есть при попадании в корзину ремонта забирал бы --limit себе на каждом прогоне, а
     * починяемый портал за ним не чинился бы никогда. Ровно эта форма старвейшна уже была
     * один раз (тогда её создавали строки «нет админов вообще»), поэтому проверяется не
     * «нашёлся ли кандидат», а то, что бюджет дошёл до починяемого портала.
     */
    public function test_a_stale_only_portal_does_not_block_the_queue(): void
    {
        $this->useCommand(verdict: true);

        // Протухший и ПЕРВЫЙ по id.
        $this->portal('m-stale', null);
        $this->user('m-stale', 10, isAdmin: true, expires: time() - 40 * 86400);

        $this->portal('m-fix', null);
        $this->user('m-fix', 50, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand(['--limit' => 1]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame(
            50,
            B24App::query()->where('member_id', 'm-fix')->value('user_id'),
            'бюджет прогона забрал портал с протухшими админами: повторные запуски не сдвинутся с места',
        );
    }

    public function test_max_age_zero_disables_the_freshness_floor(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-stale', null);
        $this->user('m-stale', 10, isAdmin: true, expires: time() - 40 * 86400);

        [$exit, $out] = $this->runCommand(['--max-age' => 0]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame(10, B24App::query()->where('member_id', 'm-stale')->value('user_id'));
    }

    // --- живая проверка и откат ---

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

        [$exit, $out] = $this->runCommand();
        self::assertSame(1, $exit, 'код возврата прогона');
        self::assertStringContainsString('перепривязано: 0, откатов: 1', $out);

        self::assertSame($before, $this->snapshot('m-null'), 'после отката строка портала отличается от исходной');
    }

    public function test_rollback_restores_every_column_when_verification_throws(): void
    {
        $this->useCommand(verdict: new \RuntimeException('portal is unreachable'));

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-null');

        [$exit, $out] = $this->runCommand();
        self::assertSame(1, $exit, 'код возврата прогона');
        self::assertStringContainsString('откат', $out);

        self::assertSame($before, $this->snapshot('m-null'), 'исключение проверки оставило портал недочиненным');
    }

    /**
     * Откат не должен затирать то, что приехало в строку за время проверки.
     *
     * Это не гипотетическая гонка: живой вызов идёт через Bitrix24App, то есть по шине
     * appEvents, слушатель которой сохраняет обновлённый токен прямо в b24_apps. Слепой
     * откат вернул бы туда предыдущее значение, которое Битрикс к этому моменту уже
     * отозвал, — то есть починил бы права ценой полностью мёртвого портала.
     */
    public function test_rollback_is_cancelled_when_the_row_changed_underneath(): void
    {
        $this->useCommand(
            verdict: false,
            onVerify: fn() => B24App::query()
                ->where('member_id', 'm-null')
                ->update(['access_token' => 'renewed-mid-flight']),
        );

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand();
        self::assertSame(1, $exit, 'код возврата прогона');
        self::assertStringContainsString('откат отменён', $out);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(
            'renewed-mid-flight',
            $app->access_token,
            'откат затёр настоящий свежий токен значением, которое Битрикс уже отозвал',
        );

        // А вот владелец обязан вернуться. Проверка только что сказала, что этот человек
        // не админ; оставить его в user_id значит закрепить за порталом подтверждённого
        // не-админа — правило 2 начнёт переносить его токен в b24_apps на каждом заходе,
        // то есть исходная авария станет стабильной, причём установленной инструментом
        // ремонта. Здесь прежний владелец NULL, то есть портал уехал бы из fail-closed в
        // «стабильно ломается».
        self::assertNull(
            $app->user_id,
            'портал остался закреплён за подтверждённым не-админом: ремонт застабилизировал исходную аварию',
        );

        // error_update намеренно НЕ восстанавливаем: обнулить его мог законный перенос
        // токена, а само обнуление безвредно.
        self::assertSame(0, (int) $app->error_update);
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

        [$exit, $out] = $this->runCommand();
        self::assertSame(0, $exit, 'код возврата прогона');
        self::assertStringContainsString('перепривязано: 1, откатов: 0', $out);

        $app = B24App::query()->where('member_id', 'm-null')->first();

        self::assertSame(10, $app->user_id);
        self::assertSame('m-null/user-10-access', $app->access_token);
    }

    // --- опции прогона ---

    public function test_member_option_narrows_the_run_to_one_portal(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-a', null);
        $this->user('m-a', 10, isAdmin: true, expires: time() + 3600);
        $this->portal('m-b', null);
        $this->user('m-b', 20, isAdmin: true, expires: time() + 3600);

        $untouched = $this->snapshot('m-b');

        [$exit, $out] = $this->runCommand(['--member' => 'm-a']);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame(10, B24App::query()->where('member_id', 'm-a')->value('user_id'));
        self::assertSame($untouched, $this->snapshot('m-b'), '--member не ограничил прогон одним порталом');
    }

    public function test_limit_bounds_the_number_of_portals_touched(): void
    {
        $this->useCommand(verdict: true);

        $this->portal('m-a', null);
        $this->user('m-a', 10, isAdmin: true, expires: time() + 3600);
        $this->portal('m-b', null);
        $this->user('m-b', 20, isAdmin: true, expires: time() + 3600);

        $untouched = $this->snapshot('m-b');

        [$exit, $out] = $this->runCommand(['--limit' => 1]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame(10, B24App::query()->where('member_id', 'm-a')->value('user_id'));
        self::assertSame($untouched, $this->snapshot('m-b'), '--limit не ограничил число обработанных порталов');
    }

    /**
     * --limit нельзя тратить на строки, которые починить нельзя.
     *
     * Непочиняемый портал не исчезает из БД и не меняется от прогона к прогону, а порядок
     * стабилен по id. Попади он в набор кандидатов — и на каждом запуске бюджет уходил бы
     * ему, а стоящий следом починяемый портал не чинился бы НИКОГДА: сколько раз команду
     * ни запусти, она печатала бы один и тот же список «на переустановку».
     */
    public function test_limit_is_not_spent_on_portals_that_cannot_be_repaired(): void
    {
        $this->useCommand(verdict: true);

        // Непочиняемый и идёт ПЕРВЫМ по id.
        $this->portal('m-stuck', 40);
        $this->user('m-stuck', 40, isAdmin: false, expires: time() + 3600);

        $this->portal('m-fix', null);
        $this->user('m-fix', 50, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand(['--limit' => 1]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame(
            50,
            B24App::query()->where('member_id', 'm-fix')->value('user_id'),
            'бюджет прогона ушёл на портал, который починить нельзя: повторные запуски не сдвинутся с места',
        );
    }

    /**
     * --skip-verify выключает единственную защиту от записи мёртвого токена, и отменить
     * такой прогон нечем. На один осознанно выбранный портал — пожалуйста, на весь флот —
     * нет.
     */
    public function test_skip_verify_is_refused_without_member(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        $before = $this->snapshot('m-null');

        [$exit, $out] = $this->runCommand(['--skip-verify' => true]);
        self::assertSame(1, $exit, 'код возврата прогона');

        self::assertSame($before, $this->snapshot('m-null'), 'прогон без живой проверки на весь флот всё-таки прошёл');
    }

    public function test_skip_verify_is_allowed_for_a_single_portal(): void
    {
        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand(['--member' => 'm-null', '--skip-verify' => true]);
        self::assertSame(0, $exit, 'код возврата прогона');

        self::assertSame('m-null/user-10-access', B24App::query()->where('member_id', 'm-null')->value('access_token'));
    }

    /**
     * Прогон, из которого ни один портал не вышел починенным, обязан быть виден скрипту:
     * молчаливый нулевой код заставил бы считать, что ремонт прошёл.
     */
    public function test_a_run_where_nothing_survived_exits_with_a_failure_code(): void
    {
        $this->useCommand(verdict: false);

        $this->portal('m-null', null);
        $this->user('m-null', 10, isAdmin: true, expires: time() + 3600);

        [$exit, $out] = $this->runCommand();
        self::assertSame(1, $exit, 'код возврата прогона');
    }

    // --- инфраструктура теста ---

    /**
     * Прогон команды с честным захватом вывода.
     *
     * Намеренно не через $this->artisan(): его expectsOutputToContain разбирает Mockery в
     * порядке регистрации, и одна написанная строка гасит РОВНО ОДНО ожидание — первое
     * подошедшее. Из-за этого doesntExpectOutputToContain молча не срабатывает, если ту же
     * строку уже забрало положительное ожидание: мутант, переименовавший «ждут входа
     * админа» обратно в «нужна переустановка», ровно так и выжил, хотя запрет на это слово
     * в тесте стоял. Здесь вывод берётся целиком и проверяется обычными ассертами.
     *
     * @return array{int, string}
     */
    private function runCommand(array $parameters = []): array
    {
        $exit = Artisan::call('bitrix24:reanchor-app-token', $parameters);

        return [$exit, Artisan::output()];
    }

    /**
     * Подменяет команду в контейнере: живой вызов user.admin из теста недоступен, а
     * подменять его надо ДО того, как консольное приложение соберёт список команд.
     */
    private function useCommand(bool|\Throwable $verdict, ?\Closure $onVerify = null): RecordingReanchorCommand
    {
        $command = new RecordingReanchorCommand($verdict, $onVerify);

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

    /**
     * Токены именованы через member_id намеренно: одинаковый user_id на разных порталах —
     * это норма флота, и без префикса запись ЧУЖОГО токена в портал была бы в тесте
     * неотличима от правильной.
     */
    private function user(string $memberId, int $userId, bool $isAdmin, int $expires): void
    {
        B24User::query()->create([
            'member_id' => $memberId,
            'user_id' => $userId,
            'domain' => $memberId . '.bitrix24.ru',
            'access_token' => "{$memberId}/user-{$userId}-access",
            'refresh_token' => "{$memberId}/user-{$userId}-refresh",
            'expires' => $expires,
            'expires_in' => 3600,
            'is_admin' => $isAdmin,
        ]);
    }
}
