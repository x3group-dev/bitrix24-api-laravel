<?php

namespace X3Group\Bitrix24\Console\Commands;

use Illuminate\Console\Command;
use X3Group\Bitrix24\Bitrix24App;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;
use X3Group\Bitrix24\Support\OAuthErrorInspector;

/**
 * Разово вычищает уже накопившиеся токены порталов, с которых приложение удалено
 * (OAuth NOT_INSTALLED).
 *
 * renewTokens() удаляет такие записи автоматически, но только пока error_update < 10;
 * порталы, на которых обновление токена уже упало 10 раз, из ротации исключены и
 * остаются в БД навсегда. Команда пробит их OAuth-обновлением токена (единственный
 * источник чистого NOT_INSTALLED) и удаляет ТОЛЬКО NOT_INSTALLED. PAYMENT_REQUIRED и
 * сетевые ошибки не трогает — такие порталы могут ожить.
 */
class RemoveUninstalledPortals extends Command
{
    protected $signature = 'bitrix24:remove-uninstalled
        {--limit=200 : Сколько порталов-кандидатов проверить за запуск}
        {--threshold=1 : Минимальный error_update для кандидата}
        {--dry-run : Только показать, что было бы удалено, без удаления}';

    protected $description = 'Удалить токены порталов, с которых приложение удалено (NOT_INSTALLED)';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

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

        $toRemove = [];
        $revived = 0;

        $bar = $this->output->createProgressBar($candidates->count());
        $bar->start();

        foreach ($candidates as $b24app) {
            try {
                $this->probe($b24app->member_id);
                $revived++;
            } catch (\Throwable $e) {
                if (OAuthErrorInspector::isApplicationNotInstalled($e)) {
                    $toRemove[] = [$b24app->member_id, $b24app->domain];

                    if (!$dryRun) {
                        B24User::query()->where('member_id', $b24app->member_id)->delete();
                        $b24app->delete();

                        logger()->info('removed uninstalled portal', [
                            'member_id' => $b24app->member_id,
                            'domain' => $b24app->domain,
                        ]);
                    }
                }
                // PAYMENT_REQUIRED, сеть, таймауты — оставляем.
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        foreach ($toRemove as [$memberId, $domain]) {
            $this->line(($dryRun ? '[dry-run] удалил бы' : 'удалён') . ": {$memberId}  {$domain}");
        }

        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$prefix}Проверено: {$candidates->count()}, NOT_INSTALLED: " . count($toRemove) . ", живых: {$revived}");

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
