<?php

namespace X3Group\Bitrix24\Jobs;

use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Credentials\AuthToken;
use Bitrix24\SDK\Core\Credentials\Scope;
use Bitrix24\SDK\Services\Entity\EntityServiceBuilder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;
use X3Group\Bitrix24\Adapters\EventDispatcherAdapter;
use X3Group\Bitrix24\Application\Local\OauthServerUrlResolver;
use X3Group\Bitrix24\Core\B24ServiceBuilderFactory;
use X3Group\Bitrix24\Support\SystemAppUser;

/**
 * Выдаёт системному пользователю приложения право X на все сущности портала (entity.*).
 *
 * Без прав на существующие сущности перевод app-токена на системного пользователя даёт
 * ACCESS_DENIED на любой записи. Задача отложенная: событие приходит сразу после установки,
 * когда установщик приложения ещё может создавать сущности прежним токеном.
 *
 * Ходит в портал прежним токеном владельца-администратора, снятым со строки b24_apps до её
 * перезаписи: менять ACCESS сущности вправе её владелец или администратор портала, а
 * системный пользователь не обязан быть ни тем, ни другим.
 *
 * Включается ключом конфига bitrix24.system_user.grant_entity_rights — приложениям без
 * entity.* она не нужна.
 */
class GrantSystemUserEntityRightsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Каждый вызов портала ограничен только таймаутом HTTP-клиента SDK, а сущностей десятки.
     *  Окно уникальности равно таймауту: после SIGKILL воркера блокировку снять некому. */
    public int $uniqueFor = 900;

    public int $timeout = 900;

    public int $tries = 3;

    /** Повтор имеет смысл: отказ портала на части сущностей обычно временный. */
    public array $backoff = [60, 300];

    /** SDK запрещает пустой refresh_token, а токен администратора мы не обновляем. */
    private const NO_REFRESH = 'no-refresh';

    /**
     * @param  string  $grantAccessToken  access_token администратора, владевшего строкой до перехода
     */
    public function __construct(
        public string $memberId,
        public string $grantAccessToken,
        public string $domain,
        public ?string $oauthServerUrl = null,
    ) {
    }

    public function uniqueId(): string
    {
        return "system-user-rights:{$this->memberId}";
    }

    public function handle(): void
    {
        $userId = SystemAppUser::idFor($this->memberId);

        if ($userId === null) {
            logger()->warning('system user rights: portal is not anchored', [
                'member_id' => $this->memberId,
            ]);

            return;
        }

        if ($this->grantAccessToken === '' || $this->domain === '') {
            logger()->warning('system user rights: administrator grant is missing', [
                'member_id' => $this->memberId,
                'has_access_token' => $this->grantAccessToken !== '',
                'domain' => $this->domain,
            ]);

            return;
        }

        try {
            $entityScope = $this->entityScope();
            $entities = $entityScope->entity()->get()->getEntities();
        } catch (Throwable $exception) {
            // Первый же вызов упал: токен администратора, снятый при переходе, к запуску
            // задачи протух. Повтор не поможет.
            logger()->warning('system user rights: administrator grant is no longer valid', [
                'member_id' => $this->memberId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return;
        }

        $granted = [];
        $skipped = [];
        $failed = [];

        // Общего try/catch вокруг цикла быть не должно: отказ портала на одной сущности
        // не повод оставить без прав остальные.
        foreach ($entities as $entity) {
            $entityCode = (string) $entity->ENTITY;

            try {
                $rights = $entityScope->entity()->rights($entityCode)->getRights();

                if (($rights['U' . $userId] ?? null) === 'X') {
                    $skipped[] = $entityCode;
                    continue;
                }

                $result = $entityScope->entity()
                    ->rights($entityCode, array_merge($rights, ['U' . $userId => 'X']))
                    ->getRights();

                // Право считается проставленным по ответу портала, а не по факту вызова.
                if (($result['U' . $userId] ?? null) !== 'X') {
                    $failed[] = $entityCode;
                    logger()->error('system user rights: not applied', [
                        'member_id' => $this->memberId,
                        'entity_id' => $entityCode,
                        'result_right' => $result['U' . $userId] ?? null,
                    ]);
                    continue;
                }

                $granted[] = $entityCode;
            } catch (Throwable $exception) {
                $failed[] = $entityCode;
                logger()->error('system user rights: call failed', [
                    'member_id' => $this->memberId,
                    'entity_id' => $entityCode,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        }

        logger()->info('system user rights: pass finished', [
            'member_id' => $this->memberId,
            'system_user_id' => $userId,
            'granted' => $granted,
            'already_had' => $skipped,
            'failed' => $failed,
        ]);

        if ($failed !== []) {
            // Задача идемпотентна: повтор пройдёт по уже выданным правам вхолостую.
            throw new RuntimeException(
                'Права системному пользователю выданы не на всех сущностях: ' . implode(', ', $failed)
            );
        }
    }

    /**
     * Клиент на прежнем токене администратора. Диспетчер событий без слушателей: рефреш
     * этого токена сохранять некуда — в b24_apps уже токен системного пользователя, запись
     * затёрла бы его. Рефреш и не состоится: refresh_token в клиент не передаётся, а
     * протухший токен даёт отказ портала в логе.
     */
    private function entityScope(): EntityServiceBuilder
    {
        $applicationProfile = new ApplicationProfile(
            clientId: config('bitrix24.client_id'),
            clientSecret: config('bitrix24.client_secret'),
            scope: Scope::initFromString(config('bitrix24.scope')),
        );

        $api = (new B24ServiceBuilderFactory(
            eventDispatcher: new EventDispatcherAdapter(),
            log: resolve('b24log', ['memberId' => $this->memberId, 'domain' => $this->domain]),
        ))->init(
            applicationProfile: $applicationProfile,
            authToken: new AuthToken(
                accessToken: $this->grantAccessToken,
                refreshToken: self::NO_REFRESH,
                expires: 0,
            ),
            bitrix24DomainUrl: "https://{$this->domain}",
            oauthServerUrl: OauthServerUrlResolver::orDefault($this->oauthServerUrl),
        );

        return $api->getEntityScope();
    }
}
