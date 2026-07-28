<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Core\Response\DTO\RenewedAuthToken;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Install\InstallService;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\Support\MethodSource;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Раскладка диспетчеров событий по фазам установки: проба — изолированно,
 * 'appEvents' — только после гейта.
 *
 * Пробный вызов идёт ДО проверки админства. На диспетчере 'appEvents' зарегистрирован
 * слушатель «записать обновлённый токен в b24_apps», поэтому дать его пробному клиенту
 * значит пустить токен в b24_apps мимо AppTokenWriter — мимо и гейта, и записи владельца.
 *
 * Обратная сторона: после гейта рефреш обязан сохраняться, иначе десятки REST-вызовов
 * установщиков оставят в b24_apps отозванный refresh_token. Поэтому 'appEvents' не
 * убран, а сдвинут за гейт.
 *
 * Поведенчески здесь проверены сами диспетчеры (кто пишет в b24_apps, а кто нет); то, что
 * InstallService раздаёт их по фазам именно так, удерживается структурно.
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

    /** Собирается так же, как это делает сам SDK на рефреше: expiresIn остаётся пуст. */
    private function renewed(string $accessToken): AuthTokenRenewedEvent
    {
        return new AuthTokenRenewedEvent(RenewedAuthToken::initFromArray([
            'access_token' => $accessToken,
            'refresh_token' => $accessToken . '-refresh',
            'expires' => time() + 3600,
            'member_id' => self::MEMBER,
            'client_endpoint' => 'https://portal.bitrix24.ru/rest/',
            'server_endpoint' => 'https://oauth.bitrix24.tech/rest/',
            'status' => 'S',
            'domain' => 'portal.bitrix24.ru',
        ]));
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
            'диспетчер appEvents перестал писать обновлённый токен — контроль недействителен',
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
     * @return array<string, array{string}>
     */
    public static function installPaths(): array
    {
        return [
            'install-страница (PLACEMENT)' => ['handleInstallPage'],
            'серверное событие ONAPPINSTALL' => ['handleOnAppInstall'],
        ];
    }

    /**
     * Структурная страховка: поведенчески раскладку не воспроизвести — оба клиента
     * упираются в живой REST Битрикса. Проверяется ровно порядок:
     *
     *   проба на изолированном диспетчере -> «кто ставит?» -> гейт -> запись -> диспетчер 'appEvents'
     *
     * Каждое звено осмысленно: проба после гейта бессмысленна, гейт после записи ничего
     * не спасает, а 'appEvents' до гейта — та самая дыра.
     */
    #[DataProvider('installPaths')]
    public function test_the_probe_runs_isolated_and_the_app_events_bus_only_after_the_gate(string $method): void
    {
        $source = MethodSource::of(InstallService::class, $method);

        $steps = [
            'проба на изолированном диспетчере' => 'new InstallTokenProbe()',
            'вопрос «кто ставит?»' => 'getCurrentUserProfile()',
            'гейт' => 'throw InstallerCannotOwnPortalException::',
            'запись app-токена' => 'saveIfAllowed(',
            "диспетчер 'appEvents'" => "resolve('appEvents')",
        ];

        $positions = [];
        foreach ($steps as $label => $needle) {
            $position = strpos($source, $needle);
            self::assertNotFalse($position, "в {$method} нет шага «{$label}» ({$needle})");
            $positions[$label] = $position;
        }

        $labels = array_keys($steps);
        for ($i = 1; $i < count($labels); $i++) {
            self::assertLessThan(
                $positions[$labels[$i]],
                $positions[$labels[$i - 1]],
                "в {$method} шаг «{$labels[$i - 1]}» идёт после «{$labels[$i]}»",
            );
        }
    }

    /**
     * Пробный клиент не должен строиться на диспетчере, который пишет в БД. Отдельно от порядка:
     * порядок ловит «'appEvents' раньше гейта», а это — «'appEvents' ещё и у пробы».
     */
    #[DataProvider('installPaths')]
    public function test_the_probe_client_is_not_built_on_the_app_events_bus(string $method): void
    {
        $source = MethodSource::of(InstallService::class, $method);

        self::assertSame(
            1,
            substr_count($source, "resolve('appEvents')"),
            "в {$method} диспетчер 'appEvents' используется дважды — вероятно, его отдали и пробе",
        );
        self::assertStringContainsString(
            'eventDispatcher: $probe->eventDispatcher()',
            $source,
            "в {$method} пробный клиент строится не на изолированном диспетчере пробы",
        );
    }
}
