<?php

namespace X3Group\B24Api;

use Bitrix24Api\Config\Credential;
use Illuminate\Support\Facades\Log;

/**
 * Класс для работы с б24 в контексте пользователя
 */
class B24ApiUser extends B24Api
{
    protected int $b24UserId;

    public function __construct($memberId, $userId)
    {
        $this->b24UserId = $userId;
        parent::__construct($memberId);
        $this->api->onAccessTokenRefresh(function (\Bitrix24Api\Config\Credential $credential) {
            $this->saveMemberData($credential->toArray());
        });
    }

    protected function getSettings(): array
    {
        $dataBitrix24 = \X3Group\B24Api\Models\B24Api::where('member_id', $this->memberId)->first();
        if ($dataBitrix24) {
            $data = \X3Group\B24Api\Models\B24User::where('member_id', $this->memberId)->where('user_id', $this->b24UserId)->first();
            if ($data) {
                return array_merge($dataBitrix24->toArray(), $data->toArray());
            }
        }
        return [];
    }

    /**
     * artisan schedule:work
     * продляет токены через 20 дней, если не было активности от портала
     * @throws \Exception
     */
    public static function renewTokens(): void
    {
        if (env('APP_DEBUG'))
            return;

        $dataApiB24 = \X3Group\B24Api\Models\B24User::where('expires', '<=', time() - (20 * 3600 * 24))->orWhere('expires', null)->get();
        foreach ($dataApiB24 as $b24) {
            $api = (new self($b24->member_id, $b24->user_id));
            $b24Api = $api->getApi();
            $b24Api->onAccessTokenRefresh(function (Credential $credential) use ($api) {
                $api->saveMemberData($credential->toArray());
            });
            $b24Api->getNewAccessToken();
        }
    }

    protected function saveMemberData($settings): bool
    {
        if (is_array($settings)) {
            $oldData = $this->getSettings();

            if (!empty($oldData)) {
                $settings = array_merge($oldData, $settings);
            }

            $updateFields = array_intersect_key($settings, [
                'access_token' => '',
                'refresh_token' => '',
                'client_endpoint' => '',

                'domain' => '',
                'member_id' => '',

                'expires' => '',
//                'expires_in' => '',

                'user_id' => '',
                'status' => '',
                'scope' => '',
                'application_token' => ''
            ]);
            try {
                \X3Group\B24Api\Models\B24User::updateOrCreate(
                    ['member_id' => $this->memberId, 'user_id' => $this->b24UserId],
                    $updateFields
                );

                return true;
            } catch (\Exception $exception) {
                Log::error((string)$exception);
                return false;
            }
        }
        return false;
    }
}
