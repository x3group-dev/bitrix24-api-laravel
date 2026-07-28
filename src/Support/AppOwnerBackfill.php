<?php

namespace X3Group\Bitrix24\Support;

use Illuminate\Support\Facades\DB;

/**
 * Заполняет b24_apps.user_id для порталов, установленных до появления этой колонки.
 *
 * Владельца берём из самого токена и принимаем ТОЛЬКО если это администратор: на
 * повреждённых порталах в токене лежит рядовой сотрудник, и записать его владельцем
 * значило бы узаконить подмену. Такие строки остаются с NULL и попадают в отчёт как
 * кандидаты на ремонт (bitrix24:reanchor-app-token).
 */
class AppOwnerBackfill
{
    /**
     * @return array{filled: int, unresolved: list<string>}
     */
    public function run(): array
    {
        $filled = 0;
        $unresolved = [];

        DB::table('b24_apps')->orderBy('id')->chunkById(200, function ($rows) use (&$filled, &$unresolved) {
            foreach ($rows as $row) {
                $owner = TokenOwner::fromAccessToken($row->access_token);

                if ($owner === null || !$this->isAdmin($row->member_id, $owner)) {
                    $unresolved[] = $row->domain;

                    continue;
                }

                DB::table('b24_apps')->where('id', $row->id)->update(['user_id' => $owner]);
                $filled++;
            }
        });

        return ['filled' => $filled, 'unresolved' => $unresolved];
    }

    private function isAdmin(string $memberId, int $userId): bool
    {
        return (bool) DB::table('b24_users')
            ->where('member_id', $memberId)
            ->where('user_id', $userId)
            ->value('is_admin');
    }
}
