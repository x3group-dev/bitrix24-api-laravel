<?php

namespace X3Group\Bitrix24\Support;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use X3Group\Bitrix24\Models\B24App;
use X3Group\Bitrix24\Models\B24User;

/**
 * Подтверждает смену адреса портала и записывает новый домен в b24_apps и b24_users.
 *
 * Смена подтверждается, только если REST старого домена отвечает 302 на домен из запроса.
 */
final class PortalDomainRename
{
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function make(LoggerInterface $logger): self
    {
        return new self(
            HttpClient::create(['max_redirects' => 0, 'timeout' => 5.0, 'max_duration' => 10.0]),
            $logger,
        );
    }

    /** Возвращает true, если домен портала переименован в $requestDomain и записан в базу. */
    public function confirm(B24App $b24app, ?string $requestDomain): bool
    {
        $oldHost = PortalDomain::normalize($b24app->domain);
        $newHost = PortalDomain::normalize($requestDomain);

        if ($oldHost === '' || $newHost === '' || $oldHost === $newHost) {
            return false;
        }

        $redirectHost = $this->redirectHost($oldHost);
        if ($redirectHost !== $newHost) {
            $this->logger->notice('portal domain rename not confirmed', [
                'member_id' => $b24app->member_id,
                'stored_domain' => $oldHost,
                'request_domain' => $newHost,
                'redirect_domain' => $redirectHost,
            ]);

            return false;
        }

        B24App::query()->where('member_id', $b24app->member_id)->update(['domain' => $newHost]);
        B24User::query()->where('member_id', $b24app->member_id)->update(['domain' => $newHost]);
        $b24app->domain = $newHost;
        $b24app->syncOriginalAttribute('domain');

        $this->logger->info('portal domain renamed', [
            'member_id' => $b24app->member_id,
            'old_domain' => $oldHost,
            'new_domain' => $newHost,
        ]);

        return true;
    }

    /** Хост из Location ответа 302 на REST старого домена; null — редиректа нет или запрос не удался. */
    private function redirectHost(string $oldHost): ?string
    {
        try {
            $response = $this->http->request('POST', "https://{$oldHost}/rest/app.info");
            if ($response->getStatusCode() !== 302) {
                return null;
            }

            $location = $response->getHeaders(false)['location'][0] ?? '';
        } catch (\Throwable $e) {
            $this->logger->warning('portal domain rename check failed', [
                'stored_domain' => $oldHost,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $host = parse_url($location, PHP_URL_HOST);

        return is_string($host) ? PortalDomain::normalize($host) : null;
    }
}
