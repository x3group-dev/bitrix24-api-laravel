<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\DB;

/**
 * Заполняет b24_apps.user_id для порталов, установленных до появления этой колонки.
 *
 * Владельца берём из самого токена и принимаем ТОЛЬКО если это администратор: на
 * повреждённых порталах в токене лежит рядовой сотрудник, и записать его владельцем
 * значило бы узаконить подмену. Строка в этом случае остаётся с NULL (fail-closed).
 *
 * Незаполненные строки разводим по двум причинам, потому что они требуют
 * противоположной реакции оператора:
 *
 * - unparseable — владельца не удалось вытащить из токена. Единичные случаи бывают
 *   (пустой или обрезанный токен), но большое число означает не «много сломанных
 *   порталов», а ошибку интеграции: читаем не ту колонку, сменился формат токена,
 *   значения зашифрованы. Это повод разобраться, а не чинить порталы по одному.
 * - notAdmin — владелец из токена разобран, но он не администратор. Вот это и есть
 *   искомая находка: app-токен портала принадлежит не тому человеку. Такие порталы
 *   идут в ремонт через bitrix24:reanchor-app-token.
 *
 * Общий счётчик двух причин смешивал бы диагноз с симптомом, поэтому их два.
 */
class AppOwnerBackfill
{
    /**
     * @return array{filled: int, unparseable: list<string>, notAdmin: list<string>}
     */
    public function run(): array
    {
        $filled = 0;
        $unparseable = [];
        $notAdmin = [];

        DB::table('b24_apps')->orderBy('id')->chunkById(200, function ($rows) use (&$filled, &$unparseable, &$notAdmin) {
            foreach ($rows as $row) {
                $owner = TokenOwner::fromAccessToken($row->access_token);

                if ($owner === null) {
                    $unparseable[] = $row->domain;

                    continue;
                }

                if (!$this->isAdmin($row->member_id, $owner)) {
                    $notAdmin[] = $row->domain;

                    continue;
                }

                DB::table('b24_apps')->where('id', $row->id)->update(['user_id' => $owner]);
                $filled++;
            }
        });

        return ['filled' => $filled, 'unparseable' => $unparseable, 'notAdmin' => $notAdmin];
    }

    private function isAdmin(string $memberId, int $userId): bool
    {
        return (bool) DB::table('b24_users')
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->value('is_admin');
    }
}
