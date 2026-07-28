<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use X3Group\Bitrix24\Support\AppOwnerBackfill;

/**
 * Заполняет b24_apps.user_id у порталов, установленных до появления этой колонки.
 *
 * Владельцем становится только подтверждённый по b24_users администратор того же портала;
 * всё остальное остаётся NULL и раскладывается по трём диагностическим корзинам — почему
 * именно так и что значит каждая из них, описано на {@see AppOwnerBackfill}.
 *
 * Прогон безопасно повторять: берутся только строки с user_id IS NULL, поэтому повторный
 * запуск дозаполняет то, про что знание о правах появилось позже, и не пересматривает уже
 * проставленное — в том числе назначенное вручную через bitrix24:reanchor-app-token.
 *
 * Это НЕ ремонт: команда только записывает уже сложившееся владение. Порталы из корзины
 * «на ремонт» чинит bitrix24:reanchor-app-token.
 */
class BackfillAppOwnerCommand extends Command
{
    protected $signature = 'bitrix24:backfill-app-owner
        {--dry-run : Только показать, что было бы заполнено, не записывая ничего}';

    protected $description = 'Заполнить b24_apps.user_id (владельца app-токена) у ранее установленных порталов';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $summary = $dryRun ? $this->plan() : (new AppOwnerBackfill())->run();

        $notAdmin = count($summary['notAdmin']);
        $unknownOwner = count($summary['unknownOwner']);
        $unparseable = count($summary['unparseable']);

        $this->info(sprintf(
            '%sb24_apps.user_id: заполнено %d, осталось NULL %d'
                . ' (владелец не админ %d, владелец неизвестен %d, токен не разобран %d)',
            $dryRun ? '[DRY-RUN] ' : '',
            $summary['filled'],
            $notAdmin + $unknownOwner + $unparseable,
            $notAdmin,
            $unknownOwner,
            $unparseable,
        ));

        if ($notAdmin > 0) {
            $this->line('  порталы на ремонт (bitrix24:reanchor-app-token): '
                . $this->portals($summary['notAdmin']));
        }

        if ($unknownOwner > 0) {
            $this->line("  владелец не найден в b24_users, таких порталов: {$unknownOwner}."
                . ' Само по себе это не поломка: is_admin заполняется только когда'
                . ' пользователь открывает фронтенд приложения, так что у чисто'
                . ' API-приложений сюда закономерно попадает весь флот.');
        }

        if ($unparseable > 0) {
            $this->warn("  ВНИМАНИЕ: владелец не читается из access_token, таких порталов: {$unparseable}."
                . ' Единичные случаи нормальны, массовые означают ошибку интеграции'
                . ' (не та колонка, сменился формат токена, значения зашифрованы) —'
                . ' разберитесь до того, как чинить порталы поштучно: '
                . $this->portals($summary['unparseable']));
        }

        return self::SUCCESS;
    }

    /**
     * Прогон без записи: то же самое заполнение внутри заведомо откатываемой транзакции.
     *
     * Так цифры отчёта считает ровно тот код, который потом и будет писать. Отдельная
     * «только читающая» копия классификации была бы третьим местом с теми же правилами и
     * со временем разошлась бы с настоящим бэкофиллом — то есть врала бы именно в том
     * прогоне, ради которого её и запускают.
     *
     * @return array{filled: int, notAdmin: list<string>, unknownOwner: list<string>, unparseable: list<string>}
     */
    private function plan(): array
    {
        DB::beginTransaction();

        try {
            return (new AppOwnerBackfill())->run();
        } finally {
            DB::rollBack();
        }
    }

    /**
     * @param  list<string>  $domains
     */
    private function portals(array $domains): string
    {
        return implode(', ', array_slice($domains, 0, 50))
            . (count($domains) > 50 ? ' …' : '');
    }
}
