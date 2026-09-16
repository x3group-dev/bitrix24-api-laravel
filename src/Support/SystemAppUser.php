<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\Log;
use X3Group\Bitrix24\Models\B24App;

/**
 * Системный пользователь приложения (событие ONAPPUSERREADY).
 *
 * Портал считается переведённым на него, когда у строки b24_apps стоит якорь
 * is_system_user и заполнен user_id. С этого момента app-токен не меняется.
 */
final class SystemAppUser
{
    /** ID системного пользователя портала или null, если портал на него не переведён. */
    public static function idFor(string $memberId): ?int
    {
        // Хелпер зовут из горячего пути установки сущностей, куда попадают и окружения
        // без таблицы b24_apps: в приложении «База знаний» так устроен NoteControllerTest —
        // он работает на одних фейках, без базы вообще. Отсутствие таблицы означает
        // «якоря нет».
        //
        // Отказ логируется: «якоря нет» разрешает перезаписать app-токен, поэтому
        // нечитаемая строка портала не должна проходить молча.
        try {
            $app = B24App::query()->where('member_id', $memberId)->first();
        } catch (\Throwable $exception) {
            Log::warning('SystemAppUser: не удалось прочитать якорь системного пользователя', [
                'member_id' => $memberId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($app === null || !(bool) $app->is_system_user) {
            return null;
        }

        $userId = (int) $app->user_id;

        return $userId > 0 ? $userId : null;
    }

    public static function isAnchored(string $memberId): bool
    {
        return self::idFor($memberId) !== null;
    }
}
