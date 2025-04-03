<?php

namespace X3Group\B24Api;

use Bitrix24Api\Config\Credential;
use Bitrix24Api\Exceptions\ExpiredRefreshToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use X3Group\B24Api\Models\B24User;

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

        $dataApiB24 = \X3Group\B24Api\Models\B24User::query()
            ->where('error_update', '<', 10)
            ->where(function (Builder $query) {
                $query->where('expires', '<=', time() - (20 * 3600 * 24))
                    ->orWhere('expires', null);
            })
            ->get();
        foreach ($dataApiB24 as $b24) {
            try {
                $api = (new self($b24->member_id, $b24->user_id));
                $b24Api = $api->getApi();
                $b24Api->onAccessTokenRefresh(function (Credential $credential) use ($api) {
                    $api->saveMemberData($credential->toArray());
                });

                $b24Api->getNewAccessToken();
                $b24->error_update = 0;
                $b24->save();
            } catch (ExpiredRefreshToken $e) {
                $b24->error_update++;
                $b24->save();

                Log::error('Expired refresh token: ' . $e->getMessage(), [
                    'portal' => $b24->domain,
                    'user' => $b24->user_id,
                    'member_id' => $b24->member_id,
                ]);
            } catch (\Exception $exception) {
                $b24->error_update++;
                $b24->save();
                Log::error('Error renew user tokens. Exception: ' . $exception->getMessage(), []);
            }
        }
    }

    public static function clear(): void
    {
        $data = B24User::query()->leftJoin('b24api as b24a', 'b24user.member_id', '=', 'b24a.member_id')->select(['b24user.id', 'b24user.user_id', 'b24user.member_id', 'b24a.domain'])->whereNull('b24a.domain')->get();
        foreach ($data as $item) {
            $item->delete();
        }

        B24User::query()
            ->whereNull('refresh_token')
            ->orWhere('refresh_token', '')
            ->delete();
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
            $updateFields['error_update'] = 0;
            if (empty($updateFields['refresh_token'])) {
                Log::error('empty refresh token, memberId:' . $this->memberId . ' ,userId:' . $this->b24UserId);
                return false;
            }
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
