<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Support\OAuthErrorInspector;

/**
 * Вычищает токены порталов, которые уже не оживут:
 *  - приложение удалено с портала (OAuth NOT_INSTALLED);
 *  - подписка портала кончилась больше --expired-days назад (OAuth PAYMENT_REQUIRED).
 *
 * renewTokens() удаляет NOT_INSTALLED автоматически, но только пока error_update < 10;
 * порталы, на которых обновление токена уже упало 10 раз, из ротации исключены и
 * остаются в БД навсегда. Здесь фильтра по error_update нет.
 *
 * Срок подписки считаем по b24_apps.expires: оно обновляется только при успешном
 * продлении, поэтому у портала с кончившейся подпиской замирает на дате последнего
 * удачного обновления. Отдельной колонки под дату отказа нет намеренно.
 *
 * Сетевые ошибки и таймауты не трогаем — такие порталы могут ожить.
 */
class RemoveUninstalledPortals extends Command
{
    protected $signature = 'bitrix24:remove-uninstalled
        {--limit=200 : Сколько порталов-кандидатов проверить за запуск}
        {--threshold=1 : Минимальный error_update для кандидата}
        {--expired-days=30 : Через сколько дней после последнего успешного продления удалять портал с истёкшей подпиской}
        {--dry-run : Только показать, что было бы удалено, без удаления}';

    protected $description = 'Удалить токены порталов, с которых приложение удалено (NOT_INSTALLED) или у которых давно кончилась подписка (PAYMENT_REQUIRED)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $expiredBefore = time() - ((int) $this->option('expired-days')) * 86400;

        $candidates = B24App::query()
            ->where('error_update', '>=', (int) $this->option('threshold'))
            ->limit((int) $this->option('limit'))
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('Кандидатов нет.');

            return self::SUCCESS;
        }

        $this->info(($dryRun ? 'DRY-RUN. ' : '')
            . "Проверяю {$candidates->count()} порталов — по одному OAuth-вызову на каждый, может быть небыстро...");

        $removed = [];
        $revived = 0;

        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $b24app) {
            try {
                $this->probe($b24app->member_id);
                $revived++;
            } catch (\Throwable $e) {
                $expires = (int) $b24app->expires;

                if (OAuthErrorInspector::isApplicationNotInstalled($e)) {
                    $reason = 'не установлен';
                } elseif (OAuthErrorInspector::isSubscriptionExpired($e) && $expires < $expiredBefore) {
                    // Строго старше порога: ровно на границе портал оставляем.
                    $reason = sprintf('подписка истекла %d дн. назад', intdiv(time() - $expires, 86400));
                } else {
                    // PAYMENT_REQUIRED в пределах срока (портал может оплатить),
                    // сеть, таймауты — оставляем.
                    $bar->advance();

                    continue;
                }

                $removed[] = [$reason, $b24app->member_id, $b24app->domain];

                if (!$dryRun) {
                    B24User::query()->where('member_id', $b24app->member_id)->delete();
                    $b24app->delete();

                    logger()->info('removed dead portal', [
                        'member_id' => $b24app->member_id,
                        'domain' => $b24app->domain,
                        'reason' => $reason,
                        'expires' => $expires,
                    ]);
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($removed as [$reason, $memberId, $domain]) {
            $this->line(($dryRun ? '[dry-run] удалил бы' : 'удалён') . " ({$reason}): {$memberId}  {$domain}");
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$prefix}Проверено: {$candidates->count()}, удалено: " . count($removed) . ", живых: {$revived}");

        return self::SUCCESS;
    }

    /**
     * Пробит портал OAuth-обновлением токена — именно оно даёт чистый ответ NOT_INSTALLED
     * для удалённых приложений. Живому порталу токен ротируется на стороне Bitrix, поэтому
     * ОБЯЗАТЕЛЬНО сохраняем новый. Выделено в метод для переопределения в тестах.
     */
    protected function probe(string $memberId): void
    {
        $renewed = (new Bitrix24App($memberId))->api->core->getApiClient()->getNewAuthToken();

        B24App::query()->where('member_id', $memberId)->update([
            'access_token' => $renewed->authToken->accessToken,
            'refresh_token' => $renewed->authToken->refreshToken,
            'expires' => $renewed->authToken->expires,
            'expires_in' => $renewed->authToken->expiresIn ?? 3600,
            'error_update' => 0,
        ]);
    }
}
