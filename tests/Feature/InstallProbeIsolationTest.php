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
 * Раскладка шин по фазам установки: проба — изолированно, 'appEvents' — только после гейта.
 *
 * Пробный вызов идёт ДО проверки админства, то есть тогда, когда ещё неизвестно, имеет ли
 * ставящий право на портал. Если дать пробному клиенту шину 'appEvents', на нём взведён
 * слушатель «записать обновлённый токен в b24_apps»: протухший токен обновляется прямо на
 * пробном вызове и уезжает в b24_apps мимо AppTokenWriter — мимо и гейта, и записи
 * владельца. На install-странице это ещё и токен ЧУЖОГО пользователя: клиент там строится
 * из PLACEMENT-запроса, то есть с токеном открывшего страницу, а не портала. Изоляция шин
 * из 3.3.1 не спасает: слушатель и клиент — один и тот же экземпляр.
 *
 * Обратная сторона: после гейта рефреш обязан сохраняться. Установщики — это десятки
 * REST-вызовов, и потерянный там рефреш оставит в b24_apps отозванный refresh_token.
 * Поэтому 'appEvents' не убрана, а сдвинута за гейт.
 *
 * Поведенчески здесь проверены сами шины (кто пишет в b24_apps, а кто нет); то, что
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
     *   проба на изолированной шине -> «кто ставит?» -> гейт -> запись -> шина 'appEvents'
     *
     * Каждое звено осмысленно. Проба после гейта бессмысленна; гейт после записи ничего
     * не спасает; 'appEvents' до гейта — это и есть та дыра, ради которой всё затевалось.
     */
    #[DataProvider('installPaths')]
    public function test_the_probe_runs_isolated_and_the_app_events_bus_only_after_the_gate(string $method): void
    {
        $source = MethodSource::of(InstallService::class, $method);

        $steps = [
            'проба на изолированной шине' => 'new InstallTokenProbe()',
            'вопрос «кто ставит?»' => 'getCurrentUserProfile()',
            'гейт' => 'throw InstallerCannotOwnPortalException::',
            'запись app-токена' => 'saveIfAllowed(',
            "шина 'appEvents'" => "resolve('appEvents')",
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
     * Пробный клиент не должен строиться на шине, которая пишет в БД. Отдельно от порядка:
     * порядок ловит «'appEvents' раньше гейта», а это — «'appEvents' ещё и у пробы».
     */
    #[DataProvider('installPaths')]
    public function test_the_probe_client_is_not_built_on_the_app_events_bus(string $method): void
    {
        $source = MethodSource::of(InstallService::class, $method);

        self::assertSame(
            1,
            substr_count($source, "resolve('appEvents')"),
            "в {$method} шина 'appEvents' используется дважды — вероятно, её отдали и пробе",
        );
        self::assertStringContainsString(
            'eventDispatcher: $probe->eventDispatcher()',
            $source,
            "в {$method} пробный клиент строится не на изолированной шине пробы",
        );
    }
}
