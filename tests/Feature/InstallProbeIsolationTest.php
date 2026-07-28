<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Application\ApplicationStatus;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Install\InstallService;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\Support\MethodSource;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Пробный REST-вызов на install-странице не должен уметь писать в b24_apps.
 *
 * handleInstallPage() строит клиента из PLACEMENT-запроса — то есть с токеном ОТКРЫВШЕГО
 * страницу, а не портала — и первым делом дёргает getCurrentUserProfile(). Если этому
 * клиенту дать шину 'appEvents', на нём взведён слушатель «записать обновлённый токен в
 * b24_apps»: протухший токен открывшего обновляется прямо на пробном вызове и уезжает в
 * b24_apps мимо AppTokenWriter — мимо admin-gate'а и мимо записи владельца. Изоляция шин
 * из 3.3.1 тут не помогает: слушатель и клиент — один и тот же экземпляр.
 */
class InstallProbeIsolationTest extends TestCase
{
    private const MEMBER = 'member-1';

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
            'user_id' => 8,
        ]);
    }

    private function renewed(string $accessToken): AuthTokenRenewedEvent
    {
        return new AuthTokenRenewedEvent(new RenewedAuthToken(
            authToken: new AuthToken(
                accessToken: $accessToken,
                refreshToken: $accessToken . '-refresh',
                expires: time() + 3600,
                expiresIn: 3600,
            ),
            memberId: self::MEMBER,
            clientEndpoint: 'https://portal.bitrix24.ru/rest/',
            serverEndpoint: 'https://oauth.bitrix24.tech/rest/',
            applicationStatus: ApplicationStatus::subscription(),
            domain: 'portal.bitrix24.ru',
        ));
    }

    /**
     * Контроль: без него тест ниже выродится в «ничего не произошло» и будет зелёным
     * даже если запись в b24_apps сломана вообще везде.
     */
    public function test_the_app_events_bus_does_write_a_renewed_token_into_b24_apps(): void
    {
        resolve('appEvents')->dispatch($this->renewed('renewed-by-app-bus'));

        self::assertSame(
            'renewed-by-app-bus',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'шина appEvents перестала писать обновлённый токен — контроль недействителен',
        );
    }

    public function test_a_listener_free_dispatcher_leaves_b24_apps_untouched(): void
    {
        (new EventDispatcherAdapter())->dispatch($this->renewed('opener-token'));

        self::assertSame(
            'app-access',
            B24App::query()->where('member_id', self::MEMBER)->value('access_token'),
            'токен открывшего install-страницу затёр app-токен портала мимо AppTokenWriter',
        );
    }

    /**
     * Структурная страховка: поведенчески пробный вызов не воспроизвести — он живой REST.
     */
    public function test_the_install_page_builds_its_client_on_a_listener_free_bus(): void
    {
        $source = MethodSource::of(InstallService::class, 'handleInstallPage');

        self::assertStringContainsString('new EventDispatcherAdapter()', $source);
        self::assertStringNotContainsString(
            "resolve('appEvents')",
            $source,
            'пробный вызов на install-странице снова умеет писать в b24_apps',
        );
        self::assertStringNotContainsString(
            'AuthTokenRenewedEvent',
            $source,
            'слушатель токен-события вернулся в handleInstallPage — он и был дырой',
        );
    }
}
