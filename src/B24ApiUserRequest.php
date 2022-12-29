<?php

namespace X3Group\B24Api;

use Bitrix24Api\Config\Credential;
use Illuminate\Support\Facades\Log;

/**
 * Класс для работы в контексте пользователя, когда запрос приходит непосредственно на приложение и есть авторизационные данные в запросе,
 * Используется при первичном открытии приложения
 *
 */
class B24ApiUserRequest extends B24Api
{
    protected string $access_token;
    protected string $refresh_token;
    protected string $application_token;

    public function __construct($memberId, $access_token, $refresh_token, $application_token)
    {
        $this->access_token = $access_token;
        $this->refresh_token = $refresh_token;
        $this->application_token = $application_token;

        parent::__construct($memberId);
        $this->api->onAccessTokenRefresh(function (\Bitrix24Api\Config\Credential $credential) {

        });
    }

    protected function getSettings(): array
    {
        $data = parent::getSettings();
        if (!empty($data)) {
            $data['access_token'] = $this->access_token;
            $data['refresh_token'] = $this->refresh_token;
            $data['application_token'] = $this->application_token;
            return $data;
        }
        return [];
    }

    public function getProfile(): bool|array
    {
        try {
            return $this->api->profile()->call()->toArray();
        } catch (\Exception $exception) {
            Log::error($exception);
        }

        return false;
    }
}
