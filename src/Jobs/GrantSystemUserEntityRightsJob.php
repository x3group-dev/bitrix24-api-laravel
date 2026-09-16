<?php

namespace X3Group\Bitrix24\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Support\SystemAppUser;

/**
 * Выдаёт системному пользователю приложения право X на все сущности портала (entity.*).
 *
 * Без прав на существующие сущности переезд app-токена на системного пользователя даёт
 * ACCESS_DENIED на любой записи. Задача отложенная: событие приходит сразу после установки,
 * когда установщик приложения ещё может создавать сущности прежним токеном.
 *
 * Включается ключом конфига bitrix24.system_user.grant_entity_rights — приложениям без
 * entity.* она не нужна.
 */
class GrantSystemUserEntityRightsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Каждый вызов портала ограничен только таймаутом HTTP-клиента SDK, а сущностей десятки.
     *  Окно уникальности равно таймауту: снять лок после SIGKILL некому. */
    public int $uniqueFor = 900;

    public int $timeout = 900;

    public int $tries = 3;

    /** Повтор имеет смысл: отказ портала на части сущностей обычно временный. */
    public array $backoff = [60, 300];

    public function __construct(public string $memberId)
    {
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

        $entityScope = (new Bitrix24App($this->memberId))->api->getEntityScope();

        $granted = [];
        $skipped = [];
        $failed = [];

        // Общего try/catch вокруг цикла быть не должно: отказ портала на одной сущности
        // не повод оставить без прав остальные.
        foreach ($entityScope->entity()->get()->getEntities() as $entity) {
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
}
