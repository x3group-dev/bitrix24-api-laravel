<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingLogger;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Исполнители правил записи в b24_apps: перенос обновлённого токена установщика
 * (propagateFromUser) и запись владельца при установке (saveIfAllowed).
 *
 * Ключевой инвариант: владельца берём из колонки b24_apps.user_id того же портала,
 * а НЕ вычисляем из самого записываемого токена. Токен, сверенный сам с собой,
 * всегда «свой» — проверка выродилась бы в тавтологию и правило 2 молча исчезло бы.
 */
class AppTokenOwnershipTest extends TestCase
{
    private const MEMBER = 'member-1';

    private const INSTALLER = 221;

    private const OTHER_USER = 162;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->app->bind(AppTokenWriter::class, fn() => new AppTokenWriter($this->logger));

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
    public function test_refresh_by_non_installer_is_recorded(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, $this->token('foreign'));

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(1, $notices, 'блокировка чужого рефреша прошла молча');
        self::assertSame(self::MEMBER, $notices[0]['context']['member_id'] ?? null);
        self::assertSame(self::INSTALLER, $notices[0]['context']['installer_user_id'] ?? null, 'в логе нет владельца портала');
        self::assertSame(self::OTHER_USER, $notices[0]['context']['user_id'] ?? null, 'в логе нет того, кто пытался обновить');
    }

    public function test_propagation_without_a_known_owner_is_recorded_distinguishably(): void
    {
        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);

        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(1, $notices, 'блокировка из-за неизвестного владельца прошла молча');
        self::assertSame(self::MEMBER, $notices[0]['context']['member_id'] ?? null);
        self::assertSame(self::INSTALLER, $notices[0]['context']['user_id'] ?? null);
        self::assertArrayHasKey('installer_user_id', $notices[0]['context']);
        self::assertNull($notices[0]['context']['installer_user_id'], 'неизвестный владелец должен быть виден как NULL');
    }

    /**
     * Две причины отказа обязаны различаться в логе: «портал захватывает чужой» —
     * это инцидент, а «владелец не установлен» — ожидаемое состояние части флота
     * после бэкофилла. Сложить их в одно сообщение значит утопить первое во втором.
     * Точные формулировки не фиксируем — важно, что они не совпадают.
     */
    public function test_the_two_denial_reasons_are_not_logged_as_the_same_event(): void
    {
        $this->writer()->propagateFromUser(self::MEMBER, self::OTHER_USER, $this->token('foreign'));

        B24App::query()->where('member_id', self::MEMBER)->update(['user_id' => null]);
        $this->writer()->propagateFromUser(self::MEMBER, self::INSTALLER, $this->token('fresh'));

        $notices = $this->logger->ofLevel('notice');

        self::assertCount(2, $notices);
        self::assertNotSame(
            $notices[0]['message'],
            $notices[1]['message'],
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
