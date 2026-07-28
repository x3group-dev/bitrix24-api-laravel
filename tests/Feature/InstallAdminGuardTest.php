<?php

namespace X3Group\Bitrix24\Tests\Feature;

use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use PHPUnit\Framework\Attributes\DataProvider;
use X3Group\Bitrix24\Application\Install\InstallerCannotOwnPortalException;
use X3Group\Bitrix24\Application\Install\InstallService;
use X3Group\Bitrix24\Application\Local\Infrastructure\Database\AppTokenWriter;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Tests\Support\Fakes\RecordingLogger;
use X3Group\Bitrix24\Tests\Support\MethodSource;
use X3Group\Bitrix24\Tests\TestCase;

/**
 * Владельцем портала (b24_apps.user_id) может стать только администратор.
 *
 * Не-админ во владельцах — это ровно тот инцидент, ради которого всё писалось: портал
 * получает не-админский app-токен, админ-методы (userfieldconfig.*) падают «нет прав»,
 * а переустановка админом помогает лишь до следующей установки сотрудником. Бэкофилл
 * такого владельца назначать осознанно отказывается — значит и установка не должна.
 *
 * Гейт стоит в {@see InstallService}, а не в {@see AppTokenWriter::shouldWrite}: у
 * storage-правила есть другие вызывающие, и первую установку портала оно не-админу
 * разрешает (см. test_storage_rule_alone_lets_a_non_admin_claim_a_fresh_portal).
 *
 * Границы этого файла: методы {@see InstallService} целиком не прогоняются — они сами
 * строят ServiceBuilder и упираются в живой REST Битрикса. Поведенчески проверен
 * исполнитель ({@see AppTokenWriter::saveIfAllowed}) и сам класс исключения; то, что
 * ОБА пути установки действительно проходят через гейт и пишут владельца, удерживается
 * структурными проверками через {@see MethodSource}.
 */
class InstallAdminGuardTest extends TestCase
{
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new RecordingLogger();
        $this->app->bind(AppTokenWriter::class, fn() => new AppTokenWriter($this->logger));
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

    #[DataProvider('installPaths')]
    public function test_every_install_path_asks_who_is_installing(string $method): void
    {
        self::assertStringContainsString(
            'getCurrentUserProfile()',
            MethodSource::of(InstallService::class, $method),
            "путь установки {$method} не запрашивает профиль ставящего — проверять админство нечем",
        );
    }

    #[DataProvider('installPaths')]
    public function test_every_install_path_aborts_when_the_installer_is_not_an_admin(string $method): void
    {
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$isAdmin\s*\)\s*\{\s*throw\s+InstallerCannotOwnPortalException::notAnAdmin/',
            MethodSource::of(InstallService::class, $method),
            "путь установки {$method} пускает не-админа во владельцы портала",
        );
    }

    /**
     * Профиль без ID — тоже отказ. «Успешная» установка с user_id = NULL это ровно то
     * молчаливое мёртвое состояние, ради устранения которого владелец и вводится.
     */
    #[DataProvider('installPaths')]
    public function test_every_install_path_aborts_when_the_installer_has_no_id(string $method): void
    {
        self::assertMatchesRegularExpression(
            '/if\s*\(\s*\$userId\s*===\s*null\s*\)\s*\{\s*throw\s+InstallerCannotOwnPortalException::notIdentified/',
            MethodSource::of(InstallService::class, $method),
            "путь установки {$method} запишет владельцем NULL и молча оставит правило 2 мёртвым",
        );
    }

    #[DataProvider('installPaths')]
    public function test_every_install_path_records_the_owner(string $method): void
    {
        self::assertMatchesRegularExpression(
            '/saveIfAllowed\([^;]*\$userId\s*\)/',
            MethodSource::of(InstallService::class, $method),
            "путь установки {$method} не пишет владельца: user_id останется NULL, "
            . 'и правило 2 для таких порталов молча никогда не сработает',
        );
    }

    /**
     * Порядок важен сам по себе: гейт после записи — это гейт, который уже ничего не спас.
     */
    #[DataProvider('installPaths')]
    public function test_the_admin_gate_comes_before_the_token_write(string $method): void
    {
        $source = MethodSource::of(InstallService::class, $method);

        $gate = strpos($source, 'throw InstallerCannotOwnPortalException::');
        $write = strpos($source, 'saveIfAllowed(');

        self::assertNotFalse($gate, "в {$method} нет проверки админства");
        self::assertNotFalse($write, "в {$method} нет записи app-токена");
        self::assertLessThan($write, $gate, "в {$method} токен пишется раньше проверки админства");
    }

    public function test_the_non_admin_rejection_is_catchable_and_names_the_reason(): void
    {
        $exception = InstallerCannotOwnPortalException::notAnAdmin('member-1', 154);

        self::assertSame('member-1', $exception->memberId);
        self::assertSame(154, $exception->userId);
        self::assertStringContainsString('administrator', $exception->getMessage());
        self::assertStringContainsString('154', $exception->getMessage(), 'в сообщении нет того, кто пытался поставить');
        self::assertStringContainsString('member-1', $exception->getMessage(), 'в сообщении нет портала');
    }

    public function test_the_unidentified_installer_rejection_names_its_own_reason(): void
    {
        $exception = InstallerCannotOwnPortalException::notIdentified('member-1');

        self::assertSame('member-1', $exception->memberId);
        self::assertNull($exception->userId);
        self::assertStringContainsString('member-1', $exception->getMessage());
        self::assertNotSame(
            InstallerCannotOwnPortalException::notAnAdmin('member-1', null)->getMessage(),
            $exception->getMessage(),
            'обе причины отказа пишутся одним сообщением — в логе их не разделить',
        );
    }

    public function test_admin_install_of_a_fresh_portal_creates_the_row_with_its_owner(): void
    {
        app(AppTokenWriter::class)->saveIfAllowed(
            self::localAppAuth('admin-token'),
            'fresh-portal',
            isAdmin: true,
            userId: 8,
        );

        $b24App = B24App::query()->where('member_id', 'fresh-portal')->first();

        self::assertNotNull($b24App, 'первая установка админом не создала портал');
        self::assertSame('admin-token', $b24App->access_token);
        self::assertSame(8, $b24App->user_id, 'владелец не записан при первой установке — правило 2 для портала мертво');
    }

    /**
     * Характеризующий тест: показывает, ПОЧЕМУ гейт обязан стоять в InstallService.
     *
     * Storage-правило разрешает первую установку кем угодно (строки ещё нет — писать
     * некому и затирать нечего). Само по себе это неплохо, но именно поэтому оно НЕ
     * является защитой от не-админского владельца: без гейта выше сотрудник спокойно
     * становится владельцем свежего портала.
     *
     * Если это правило когда-нибудь поменяют — тест покраснеет и заставит перечитать
     * гейт, а не тихо оставить две проверки, живущие каждая своей жизнью.
     */
    public function test_storage_rule_alone_lets_a_non_admin_claim_a_fresh_portal(): void
    {
        self::assertTrue(AppTokenWriter::shouldWrite(appExists: false, isAdmin: false));

        app(AppTokenWriter::class)->saveIfAllowed(
            self::localAppAuth('employee-token'),
            'unguarded-portal',
            isAdmin: false,
            userId: 154,
        );

        self::assertSame(
            154,
            B24App::query()->where('member_id', 'unguarded-portal')->value('user_id'),
        );
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
}
