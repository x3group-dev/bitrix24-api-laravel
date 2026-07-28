<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Illuminate\Database\QueryException;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\UserAuthDatabaseStorage;
use X3Group\Bitrix24\Http\Middleware\B24AppUserMiddleware;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingLogger;
use X3Group\Bitrix24\Tests\Support\MethodSource;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Исполнители правил записи в b24_apps: перенос обновлённого токена владельца
 * (propagateFromUser) и запись владельца при установке (saveIfAllowed).
 *
 * Ключевой инвариант: владельца берём из колонки b24_apps.user_id того же портала,
 * а НЕ вычисляем из самого записываемого токена. Токен, сверенный сам с собой,
 * всегда «свой» — проверка выродилась бы в тавтологию и правило 2 молча исчезло бы.
 */
class AppTokenOwnershipTest extends TestCase
{
    private const MEMBER = 'member-1';

    /** Второй портал: нужен там, где member_id хранилища и member_id токена расходятся. */
    private const OTHER_MEMBER = 'member-2';

    private const INSTALLER = 221;

    private const OTHER_USER = 162;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->app->bind(AppTokenWriter::class, fn() => new AppTokenWriter($this->logger));

        $this->b24app(self::MEMBER, self::INSTALLER, 'app-access');
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

    /**
     * Ловушка №1: владелец вычислен из ВХОДЯЩЕГО токена.
     *
     * Токен рядового сотрудника — настоящего формата Bitrix24, внутри зашит его же ID.
     * Реализация, которая берёт владельца через TokenOwner::fromAccessToken($token),
     * получит 162, сравнит со 162 и разрешит запись: токен подтверждает сам себя.
     * Правильная реализация смотрит в b24_apps.user_id (221) и отказывает.
     *
     * Тест из плана (test_other_user_token_is_ignored) эту ошибку НЕ ловит: там токен
     * 'foreign' не разбирается, TokenOwner вернёт null, и тавтологическая реализация
     * тоже откажет — по случайности, а не по правилу.
     */
    public function test_foreign_token_that_vouches_for_itself_is_still_ignored(): void
    {
        $this->writer()->propagateFromUser(
            self::MEMBER,
            self::OTHER_USER,
            $this->token(self::bitrixTokenFor(self::OTHER_USER)),
        );

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'владельца взяли из записываемого токена — проверка выродилась в тавтологию',
        );
    }

    /**
     * Ловушка №2: владелец вычислен из УЖЕ ЛЕЖАЩЕГО в b24_apps токена.
     *
     * Так выглядит портал, починенный через reanchor: в колонке владельцем осознанно
     * назначен админ (8), а в самом app-токене всё ещё зашит сотрудник (154), из-за
     * которого всё и сломалось. Источник истины — колонка, а не токен.
     */
    public function test_owner_column_wins_over_the_owner_encoded_in_the_stored_token(): void
    {
        B24App::query()->where('member_id', self::MEMBER)->update([
            'access_token' => self::bitrixTokenFor(154),
            'user_id' => 8,
        ]);

        // Тот, кто зашит в старом токене, владельцем уже не считается.
        $this->writer()->propagateFromUser(self::MEMBER, 154, $this->token('impostor'));

        self::assertSame(
            self::bitrixTokenFor(154),
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'владельца взяли из старого токена вместо колонки user_id',
        );

        // …а записанный в колонке — считается.
        $this->writer()->propagateFromUser(self::MEMBER, 8, $this->token('rightful'));

        self::assertSame(
            'rightful',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'владелец из колонки не смог обновить токен портала',
        );
    }

    /**
     * Отказ обязан быть виден в логе. Инцидент, ради которого всё это писалось, был
     * невидим именно потому, что подмена app-токена нигде не отмечалась: о поломке
     * узнавали от клиента. Молчаливое правило 2 повторило бы ту же ошибку — только
     * теперь молчали бы и о законном рефреше, заблокированном протухшим user_id.
     */
    public function test_refresh_by_non_owner_is_recorded(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, $this->token('foreign'));

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(1, $notices, 'блокировка чужого рефреша прошла молча');
        self::assertSame(self::MEMBER, $notices[0]['context']['member_id'] ?? null);
        self::assertSame(self::INSTALLER, $notices[0]['context']['owner_user_id'] ?? null, 'в логе нет владельца портала');
        self::assertSame(self::OTHER_USER, $notices[0]['context']['user_id'] ?? null, 'в логе нет того, кто пытался обновить');
    }

    /**
     * Отказ из-за неизвестного владельца — debug, а не notice.
     *
     * Это безопасный fail-closed отказ, и он полностью читается одним запросом к колонке
     * (where user_id is null). Громким его делать нельзя из-за объёма: у порталов, где
     * никто не открывал фронтенд, b24_users пуст, поэтому user_id остаётся NULL — на
     * ~25 тысячах порталов notice здесь стал бы не строчкой лога, а самим логом, и утопил
     * бы в себе тот единственный сигнал, ради которого всё писалось.
     */
    public function test_propagation_without_a_known_owner_is_recorded_at_debug(): void
    {
        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);

        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        self::assertSame([], $this->logger->ofLevel('notice'), 'штатный fail-closed отказ шумит на уровне инцидента');

        $debug = $this->logger->ofLevel('debug');

        self::assertCount(1, $debug, 'отказ из-за неизвестного владельца не оставил следа вообще');
        self::assertSame(self::MEMBER, $debug[0]['context']['member_id'] ?? null);
        self::assertSame(self::INSTALLER, $debug[0]['context']['user_id'] ?? null);
        self::assertArrayHasKey('owner_user_id', $debug[0]['context']);
        self::assertNull($debug[0]['context']['owner_user_id'], 'неизвестный владелец должен быть виден как NULL');
    }

    /**
     * Две причины отказа обязаны различаться в логе: «портал захватывает чужой» —
     * это инцидент, а «владелец не установлен» — ожидаемое состояние части флота
     * после бэкофилла. Сложить их в одно событие значит утопить первое во втором,
     * поэтому они разведены и по уровню, и по сообщению. Точные формулировки не
     * фиксируем — важно, что они не совпадают.
     */
    public function test_the_two_denial_reasons_are_not_logged_as_the_same_event(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, $this->token('foreign'));

        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);
        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        $notices = $this->logger->ofLevel('notice');
        $debug = $this->logger->ofLevel('debug');

        self::assertCount(1, $notices, 'захват портала обязан остаться на уровне notice');
        self::assertCount(1, $debug, 'штатный отказ обязан уехать на debug');
        self::assertNotSame(
            $notices[0]['message'],
            $debug[0]['message'],
            'обе причины отказа пишутся одним и тем же сообщением — в логе их не разделить',
        );
    }

    public function test_successful_propagation_is_not_reported_as_a_denial(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        self::assertSame([], $this->logger->ofLevel('notice'), 'успешный перенос токена записан как отказ');
        self::assertCount(1, $this->logger->ofLevel('info'));
    }

    public function test_missing_portal_row_is_logged_as_nothing(): void
    {
        // Портала нет — это не отказ, а обычный no-op (например, приложение уже
        // удалено). Шуметь тут значит приучить всех игнорировать этот notice.
        $this->writer()->propagateFromUser('member-absent', self::INSTALLER, $this->token('fresh'));

        self::assertSame([], $this->logger->records);
    }

    public function test_install_by_admin_records_the_owner(): void
    {
        $this->writer()->saveIfAllowed(
            self::localAppAuth('installed'),
            self::MEMBER,
            isAdmin: true,
            userId: self::OTHER_USER,
        );

        $app = B24App::query()->where('member_id', self::MEMBER)->first();

        self::assertSame('installed', $app->access_token);
        self::assertSame(self::OTHER_USER, $app->user_id, 'установка админом переназначает владельца');
    }

    public function test_install_without_user_id_keeps_the_recorded_owner(): void
    {
        // Совместимость: старый вызов из трёх аргументов не должен обнулять владельца.
        $this->writer()->saveIfAllowed(self::localAppAuth('installed'), self::MEMBER, isAdmin: true);

        $app = B24App::query()->where('member_id', self::MEMBER)->first();

        self::assertSame('installed', $app->access_token);
        self::assertSame(self::INSTALLER, $app->user_id);
    }

    public function test_non_admin_install_writes_neither_token_nor_owner(): void
    {
        $this->writer()->saveIfAllowed(
            self::localAppAuth('employee'),
            self::MEMBER,
            isAdmin: false,
            userId: self::OTHER_USER,
        );

        $app = B24App::query()->where('member_id', self::MEMBER)->first();

        self::assertSame('app-access', $app->access_token);
        self::assertSame(self::INSTALLER, $app->user_id, 'не-админ переписал владельца портала');
    }

    // --- Правило 2 на настоящем пути рефреша: UserAuthDatabaseStorage::saveRenewedToken ---

    /**
     * Ради чего всё правило и писалось: рефреш владельца обязан обновлять ОБЕ строки,
     * иначе app-токен портала протухнет сам по себе и клиент придёт с поломкой.
     */
    public function test_owner_refresh_updates_both_the_user_row_and_the_portal(): void
    {
        $this->b24user(self::MEMBER, self::INSTALLER);

        $this->storage(self::MEMBER, self::INSTALLER)->saveRenewedToken($this->renewed(self::MEMBER, 'fresh'));

        self::assertSame('fresh', $this->userAccessToken(self::MEMBER, self::INSTALLER));
        self::assertSame(
            'fresh',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен владельца обновился только у него — app-токен портала протухнет',
        );
    }

    /**
     * Зеркало предыдущего: рядовой сотрудник обновляет ТОЛЬКО себя. Проверяем именно
     * содержимое колонки портала, а не отсутствие исключения: исходная авария была
     * тихой записью, она бы ничего не выбросила.
     *
     * Заодно это единственный сценарий, где видно, что обновляющегося пользователя берут
     * из хранилища, а не из b24_apps.user_id: владелец, сверенный сам с собой, — тавтология,
     * которая пропускала бы кого угодно. Здесь портал принадлежит 221, рефреш идёт от 162,
     * и такая реализация увидела бы 221 === 221 и записала бы 'employee'.
     *
     * И отказ обязан быть виден в логе — по той же причине, по которой был невиден исходный
     * инцидент.
     */
    public function test_non_owner_refresh_leaves_the_portal_token_alone(): void
    {
        $this->b24user(self::MEMBER, self::OTHER_USER);

        $this->storage(self::MEMBER, self::OTHER_USER)->saveRenewedToken($this->renewed(self::MEMBER, 'employee'));

        self::assertSame('employee', $this->userAccessToken(self::MEMBER, self::OTHER_USER), 'сотрудник не обновил себя');
        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен рядового сотрудника затёр app-токен портала на рефреше',
        );
        self::assertSame(
            self::INSTALLER,
            B24App::query()->where('member_id', self::MEMBER)->value('user_id'),
            'рефреш сотрудника переписал владельца портала',
        );

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(1, $notices, 'чужой рефреш через настоящий путь прошёл молча');
        self::assertSame(self::INSTALLER, $notices[0]['context']['owner_user_id'] ?? null);
        self::assertSame(self::OTHER_USER, $notices[0]['context']['user_id'] ?? null);
    }

    public function test_refresh_on_a_portal_without_a_known_owner_updates_only_the_user(): void
    {
        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);
        $this->b24user(self::MEMBER, self::INSTALLER);

        $this->storage(self::MEMBER, self::INSTALLER)->saveRenewedToken($this->renewed(self::MEMBER, 'fresh'));

        self::assertSame('fresh', $this->userAccessToken(self::MEMBER, self::INSTALLER));
        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'владелец неизвестен, а в b24_apps всё равно записали — правило открыто настежь',
        );
    }

    /**
     * Пара (member_id, user_id) должна собираться из тех же источников, по которым
     * ищется строка пользователя: member_id — из ОБНОВЛЁННОГО ТОКЕНА, user_id — из
     * хранилища. В saveRenewedToken рядом лежат и $this->memberId, и
     * $renewedAuthToken->memberId, и подменить один другим — не опечатка, а совершенно
     * естественно выглядящая правка.
     *
     * В боевой проводке ('userEvents' в Bitrix24ServiceProvider) эти два member_id
     * сегодня всегда совпадают, так что тест защищает не наблюдаемый в проде случай, а
     * сам инвариант: обновляется портал, чей токен реально обновился. Связь держится на
     * одной строке в провайдере, а цена ошибки — тихая запись в чужой портал.
     *
     * Здесь хранилище создано под member-1, а рефреш пришёл по member-2.
     */
    public function test_the_portal_updated_is_the_one_the_renewed_token_belongs_to(): void
    {
        $this->b24app(self::OTHER_MEMBER, self::INSTALLER, 'app-access-2');
        $this->b24user(self::OTHER_MEMBER, self::INSTALLER);

        $storage = $this->storage(self::MEMBER, self::INSTALLER);

        $storage->saveRenewedToken($this->renewed(self::OTHER_MEMBER, 'fresh'));

        self::assertSame(
            'fresh',
            B24App::query()->where('member_id', self::OTHER_MEMBER)->value('access_token'),
            'обновили не тот портал: member_id взят из хранилища вместо обновлённого токена',
        );
        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'рефреш по одному порталу переписал app-токен другого',
        );
    }

    // --- Правило 2 на пути размещения: B24AppUserMiddleware ---

    /**
     * Что здесь исполняется по-настоящему, а что нет.
     *
     * Поведенчески прогнать B24AppUserMiddleware::handle() нельзя: он строит клиента
     * статической фабрикой SDK и спрашивает getCurrentUserProfile() у живого Битрикса —
     * подменить HTTP-клиент негде. Поэтому граница проведена так же, как в
     * {@see \X3Group\Bitrix24\Tests\Feature\InstallAdminGuardTest} для InstallService:
     *
     *   - исполняется по-настоящему: исполнитель (propagateFromUser) на токене ровно того
     *     вида, какой подаёт middleware, вместе с записанной тем же значением строкой
     *     b24_users — тест ниже про совпадение сроков;
     *   - структурно (чтение исходника, {@see MethodSource}): что middleware вообще зовёт
     *     перенос, что пользователя берёт из профиля Битрикса, и что срок жизни считает
     *     один раз на обе строки.
     *
     * Структурные проверки — не поведенческое покрытие. Они ловят ровно то, ради чего
     * поставлены: удаление вызова и подмену его аргументов.
     */
    public function test_opening_the_app_propagates_the_placement_token_to_the_portal(): void
    {
        self::assertStringContainsString(
            'propagateFromUser(',
            MethodSource::of(B24AppUserMiddleware::class, 'handle'),
            'заход владельца в приложение не обновляет app-токен портала: остаётся только путь '
            . 'протухания токена посреди запроса, а это редкий случай, а не основной',
        );
    }

    /**
     * Пользователя для правила 2 обязан давать профиль, за которым middleware только что
     * сходил в Битрикс. Тело запроса на эту роль не годится: оно приходит из браузера
     * клиента, и user_id оттуда — это заявление, а не факт.
     */
    public function test_the_propagated_user_comes_from_the_bitrix_profile(): void
    {
        self::assertMatchesRegularExpression(
            '/propagateFromUser\(\s*\$memberId,\s*\(int\)\$profile->ID,/',
            MethodSource::of(B24AppUserMiddleware::class, 'handle'),
            'владельца определяют не по профилю из Битрикса — правило 2 можно обмануть телом запроса',
        );
    }

    /**
     * Один и тот же токен лежит в двух строках, и протухать они обязаны одновременно.
     * Проверяется не совпадение двух выражений (одинаковые копии разъезжаются молча), а
     * то, что выражение ровно одно: срок считается один раз и раздаётся обеим строкам.
     */
    public function test_both_rows_get_one_and_the_same_expiry(): void
    {
        $source = MethodSource::of(B24AppUserMiddleware::class, 'handle');

        self::assertSame(
            1,
            substr_count($source, "post('AUTH_EXPIRES')"),
            'срок жизни токена считается больше одного раза — копии разъедутся, и строки протухнут в разное время',
        );
        self::assertSame(
            2,
            substr_count($source, "'expires' => \$expires,"),
            'строка b24_users получает срок не из общего значения',
        );
        self::assertStringContainsString(
            'expires: $expires,',
            $source,
            'в b24_apps уезжает срок, посчитанный отдельно от строки пользователя',
        );
    }

    /**
     * Исполнитель на данных ровно того вида, какой подаёт middleware: строка b24_users и
     * токен для переноса собраны из одного значения $expires. Сам middleware при этом не
     * выполняется — что он действительно раздаёт одно значение, держит тест выше.
     */
    public function test_the_placement_token_leaves_both_rows_expiring_together(): void
    {
        $expires = time() + 3600 - 600;

        $this->b24user(self::MEMBER, self::INSTALLER, $expires);

        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, new AuthToken(
            accessToken: 'placement',
            refreshToken: 'placement-refresh',
            expires: $expires,
            expiresIn: 3600,
        ));

        $app = B24App::query()->where('member_id', self::MEMBER)->first();

        self::assertSame('placement', $app->access_token, 'заход владельца не обновил app-токен портала');
        self::assertSame(
            (int) B24User::query()
                ->where('member_id', self::MEMBER)
                ->where('user_id', self::INSTALLER)
                ->value('expires'),
            (int) $app->expires,
            'строки разошлись по сроку: один и тот же токен протухает в них в разное время',
        );
        self::assertSame(3600, (int) $app->expires_in);
    }

    /**
     * Заход рядового сотрудника: своя строка обновляется, портал — нет. Ровно эта запись
     * и ломала клиентов, и приходит она чаще всего именно отсюда — с каждого открытия
     * приложения, а не только с рефреша по истечении срока.
     */
    public function test_a_non_owner_opening_the_app_leaves_the_portal_token_alone(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, new AuthToken(
            accessToken: 'employee-placement',
            refreshToken: 'employee-placement-refresh',
            expires: time() + 3600 - 600,
            expiresIn: 3600,
        ));

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен сотрудника затёр app-токен портала на заходе в приложение',
        );
        self::assertCount(1, $this->logger->ofLevel('notice'), 'подмена app-токена с размещения прошла молча');
    }

    /**
     * Характеризующий тест: показывает, ПОЧЕМУ неопознанного пользователя обязан
     * отсекать сам middleware, а не исполнитель.
     *
     * Исполнитель принимает int и другого языка не знает: пользователь 0 для него —
     * обычный не-владелец, то есть notice «обновляет не владелец». А это единственный
     * громкий сигнал всей фичи, и означать он должен попытку захвата портала, а не
     * «профиль пришёл без ID». Отсюда гейт выше по течению — и он же есть в установке
     * (InstallerCannotOwnPortalException::notIdentified).
     *
     * Если исполнитель когда-нибудь научится различать этот случай сам, тест покраснеет
     * и заставит перечитать гейт, а не оставить две проверки, живущие своей жизнью.
     */
    public function test_the_writer_alone_would_call_an_unidentified_user_a_takeover_attempt(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, 0, $this->token('fresh'));

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(1, $notices);
        self::assertSame(0, $notices[0]['context']['user_id'] ?? null);
    }

    /**
     * Характеризующий тест: показывает цену переноса токена без refresh'а.
     *
     * b24_apps.refresh_token — NOT NULL, поэтому такой перенос не «запишет что-то не то»,
     * а свалится QueryException'ом. В middleware он прилетел бы в общий catch и отдал бы
     * 401 на всё размещение — причём владельцу, единственному, ради кого правило 2 и
     * существует. Отсюда гейт выше по течению, а не нормализация пустой строки в null:
     * нормализация ровно этот случай и приводила к падению.
     *
     * (На схеме без NOT NULL было бы тише и хуже: портал остался бы с пустым
     * refresh_token и потерял бы способность обновляться вообще.)
     */
    public function test_a_refreshless_token_cannot_be_written_to_the_portal_row_at_all(): void
    {
        $refreshless = new AuthToken(
            accessToken: 'placement',
            refreshToken: null,
            expires: time() + 3000,
            expiresIn: 3600,
        );

        try {
            $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $refreshless);
            self::fail('перенос токена без refresh\'а прошёл — гейт в middleware больше не нужен, перечитать');
        } catch (QueryException) {
            // Ожидаемо: колонка NOT NULL.
        }

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'падение переноса оставило портал в половинчатом состоянии',
        );
    }

    /**
     * Размещение без refresh-токена до правила 2 не доходит, и пропуск виден на debug.
     *
     * Структурно — по той же причине, что и остальные проверки middleware. Поведенческая
     * половина инварианта — тест выше: там видно, чем кончается перенос такого токена.
     */
    public function test_a_placement_without_a_refresh_token_never_reaches_the_rule(): void
    {
        $source = MethodSource::of(B24AppUserMiddleware::class, 'handle');

        $guard = strpos($source, "} elseif (\$refreshId === null || \$refreshId === '') {");
        $propagate = strpos($source, 'propagateFromUser(');

        self::assertNotFalse($guard, 'токен без refresh\'а уедет в b24_apps и уронит всё размещение на NOT NULL');
        self::assertLessThan($propagate, $guard, 'проверка refresh-токена стоит после переноса');
        self::assertStringContainsString(
            "logger()->debug('b24 app token: propagation skipped (placement carries no refresh token)'",
            $source,
            'пропуск размещения без refresh-токена не оставляет следа',
        );
        self::assertStringContainsString(
            'refreshToken: $refreshId,',
            $source,
            'в b24_apps уезжает не то же значение, по которому принято решение пропускать',
        );
    }

    /**
     * Профиль без ID до правила 2 не доходит, а сам пропуск виден на debug.
     *
     * Структурно по той же причине, что и остальные проверки middleware: handle()
     * поведенчески не прогоняется. Поведенческая половина этого инварианта — тест выше:
     * там видно, во что превратился бы (int)null, если бы гейта не было.
     */
    public function test_an_unidentified_placement_user_never_reaches_the_rule(): void
    {
        $source = MethodSource::of(B24AppUserMiddleware::class, 'handle');

        $guard = strpos($source, 'if ($profile->ID === null)');
        $propagate = strpos($source, 'propagateFromUser(');

        self::assertNotFalse($guard, 'профиль без ID уедет в правило 2 пользователем 0');
        self::assertNotFalse($propagate, 'перенос токена из middleware исчез');
        self::assertLessThan($propagate, $guard, 'проверка на неопознанного пользователя стоит после переноса');
        self::assertSame(1, substr_count($source, 'propagateFromUser('), 'перенос зовут ещё и мимо гейта');
        self::assertStringContainsString(
            "logger()->debug('b24 app token: propagation skipped (placement user not identified)'",
            $source,
            'пропуск неопознанного пользователя не оставляет следа — диагностировать его будет нечем',
        );
        self::assertStringNotContainsString(
            '->notice(',
            $source,
            'неопознанный пользователь шумит на уровне захвата портала и заглушает настоящий сигнал',
        );
    }

    private function storage(string $memberId, int $userId): UserAuthDatabaseStorage
    {
        return new UserAuthDatabaseStorage($memberId, $userId);
    }

    /** Обновлённый токен ровно в том виде, в каком его отдаёт рефреш SDK. */
    private function renewed(string $memberId, string $accessToken): RenewedAuthToken
    {
        return RenewedAuthToken::initFromArray([
            'access_token' => $accessToken,
            'refresh_token' => $accessToken . '-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'member_id' => $memberId,
            'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
            'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
            'status' => 'S',
            'domain' => 'portal.bitrix24.ru',
        ]);
    }

    private function b24app(string $memberId, ?int $ownerId, string $accessToken): void
    {
        B24App::query()->create([
            'member_id' => $memberId,
            'domain' => 'portal.bitrix24.ru',
            'access_token' => $accessToken,
            'refresh_token' => $accessToken . '-refresh',
            'expires' => time() + 3600,
            'expires_in' => 3600,
            'application_token' => 'app-token',
            'user_id' => $ownerId,
            'error_update' => 3,
        ]);
    }

    private function b24user(string $memberId, int $userId, ?int $expires = null): void
    {
        B24User::query()->create([
            'member_id' => $memberId,
            'user_id' => $userId,
            'access_token' => 'user-access',
            'refresh_token' => 'user-refresh',
            'expires' => $expires ?? time() + 3600,
            'expires_in' => 3600,
            'domain' => 'https://portal.bitrix24.ru',
            // Правило 2 опирается на колонку b24_apps.user_id, а не на этот флаг:
            // администратора выбирают при установке, а не на каждом рефреше.
            'is_admin' => $userId === self::INSTALLER,
        ]);
    }

    private function userAccessToken(string $memberId, int $userId): ?string
    {
        return B24User::query()
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->value('access_token');
    }

    private static function localAppAuth(string $value): LocalAppAuth
    {
        return LocalAppAuth::initFromArray([
            'auth_token' => [
                'access_token' => $value,
                'refresh_token' => $value . '-refresh',
                'expires' => time() + 7200,
            ],
            'domain_url' => 'https://portal.bitrix24.ru',
            'application_token' => 'app-token',
            'oauth_server_url' => 'https://oauth.bitrix.info',
        ]);
    }

    /**
     * Токен формата Bitrix24: 24 hex-символа префикса, затем 8 hex-цифр ID владельца.
     */
    private static function bitrixTokenFor(int $ownerId): string
    {
        return 'deadbeefdeadbeefdeadbeef'
            . str_pad(dechex($ownerId), 8, '0', STR_PAD_LEFT)
            . 'cafebabecafebabe';
    }
}
