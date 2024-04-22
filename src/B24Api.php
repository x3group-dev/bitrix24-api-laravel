<?php
declare(strict_types=1);

namespace X3Group\B24Api;

use Bitrix24Api\ApiClient;
use Bitrix24Api\Config\Credential;
use Bitrix24Api\Exceptions\ApiException;
use Bitrix24Api\Exceptions\ApplicationNotInstalled;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JetBrains\PhpStorm\ArrayShape;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use X3Group\B24Api\Models\B24User;

/**
 * Основной класс обертка для общения с б24
 */
class B24Api
{
    protected Logger $log;
    protected string $memberId;
    protected ApiClient $api;

    public function getApi(): ApiClient
    {
        return $this->api;
    }

    /**
     * @throws \Exception
     */
    public function __construct($memberId)
    {
        if (!empty($memberId)) {
            $this->memberId = $memberId;
            $settings = static::getSettings();
            $dataClientEndpoint = parse_url($settings['client_endpoint']);

            $logMaxFiles = intval(getenv('B24API_LOG_MAX_FILES'));
            if ($logMaxFiles == 0)
                $logMaxFiles = 3;

            $this->log = new Logger('name');
            $this->log->pushHandler(new RotatingFileHandler(storage_path('logs/b24api/' . $dataClientEndpoint['host'] . '-' . $memberId . '/b24api.log'), $logMaxFiles));

            $application = new \Bitrix24Api\Config\Application(getenv('B24API_CLIENT_ID'), getenv('B24API_CLIENT_SECRET'));

            if (!empty($settings)) {
                $credential = \Bitrix24Api\Config\Credential::initFromArray($settings);
                $config = new \Bitrix24Api\Config\Config(null, $application, $credential, $this->log);
                $api = new \Bitrix24Api\ApiClient($config);
                $api->onAccessTokenRefresh(function (\Bitrix24Api\Config\Credential $credential) {
                    $this->saveMemberData($credential->toArray());
                });
                $this->api = $api;
            } else {
                throw new \Exception('settings is null');
            }
        } else {
            throw new \Exception('empty member_id');
        }
    }

    /**
     * Статичный метод, используется при установки приложения для сохранения авторизационных данных
     * @param string $memberId
     * @param Request $request
     * @return array
     */
    #[ArrayShape(['rest_only' => "bool", 'install' => "bool"])] public static function install(string $memberId, Request $request): array
    {
        $result = [
            'rest_only' => true,
            'install' => false
        ];
        if (empty($memberId))
            return $result;

        if ($request->post('event') == 'ONAPPINSTALL' && !empty($request->post('auth'))) {
            $result['install'] = static::saveMemberDataInstall($memberId, $request->post('auth'));
        } elseif ($request->post('PLACEMENT') == 'DEFAULT') {
            $result['rest_only'] = false;

            $result['install'] = static::saveMemberDataInstall($memberId, [
                'access_token' => htmlspecialchars($request->post('AUTH_ID')),
                'refresh_token' => htmlspecialchars($request->post('REFRESH_ID')),
                'client_endpoint' => 'https://' . htmlspecialchars($request->get('DOMAIN')) . '/rest/',

                'member_id' => $memberId,
                'domain' => htmlspecialchars($request->get('DOMAIN')),

                'expires' => time() + (int)$request->post('AUTH_EXPIRES') - 600,
                'expires_in' => htmlspecialchars($request->post('AUTH_EXPIRES')),

                'status' => htmlspecialchars($request->post('status')),
                'application_token' => htmlspecialchars($request->get('APP_SID')),
            ]);
        }
        return $result;
    }

    /**
     * Адаптационный метод для сохранения обратной совместимости
     * @param string $apiMethod
     * @param array $parameters
     * @return array
     * @throws \Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface
     * @throws \Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface
     */
    #[ArrayShape(['result' => "array"])] public function call(string $apiMethod, array $parameters = []): array
    {
        $responseData = $this->getApi()->request($apiMethod, $parameters)->getResponseData();
        return [
            'result' => $responseData->getResult()->getResultData(),
        ];
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

        $dataApiB24 = \X3Group\B24Api\Models\B24Api::where('expires', '<=', time() - (20 * 3600 * 24))->get();
        foreach ($dataApiB24 as $b24) {
            $api = (new self($b24->member_id));
            $b24Api = $api->getApi();
            $b24Api->onAccessTokenRefresh(function (Credential $credential) use ($api) {
                $api->saveMemberData($credential->toArray());
            });
            $b24Api->getNewAccessToken();
        }
    }

    /**
     * Функция проверяет статус приложения на порталах
     * если приложение удалено, удаляем запись в таблице токенов
     * @return void
     */
    public static function checkStatus(): void
    {
        $model = new \X3Group\B24Api\Models\B24Api;
        $dataApiB24 = $model->get();
        foreach ($dataApiB24 as $b24) {
            $api = (new self($b24->member_id));
            $b24Api = $api->getApi();
            try {
                $appInfo = $b24Api->request('app.info');
            } catch (ApplicationNotInstalled $exception) {
                //todo: remove delaytasks
                B24User::query()->where('member_id', $b24->member_id)->delete();
                $b24->delete();
            } catch (ApiException $e) {

            }
        }
    }

    /**
     * Сохранение/обновление авторизационных данных в БД
     * @param $settings
     * @return bool
     */
    protected function saveMemberData($settings): bool
    {
        if (is_array($settings)) {
            $oldData = static::getSettings();

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
                'expires_in' => '',

                'user_id' => '',
                'status' => '',
                'scope' => '',
                'application_token' => ''
            ]);

            try {
                \X3Group\B24Api\Models\B24Api::updateOrCreate(
                    ['member_id' => $this->memberId],
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

    /**
     * @param string $memberId
     * @param $settings
     * @return bool
     */
    protected static function saveMemberDataInstall(string $memberId, $settings): bool
    {
        if (is_array($settings)) {
            $oldData = [];
            $dataObject = \X3Group\B24Api\Models\B24Api::where('member_id', $memberId)->first();
            if ($dataObject)
                $oldData = $dataObject->toArray();

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
                'expires_in' => '',

                'user_id' => '',
                'status' => '',
                'scope' => '',
                'application_token' => ''
            ]);

            try {
                \X3Group\B24Api\Models\B24Api::updateOrCreate(
                    ['member_id' => $memberId],
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

    /**
     * Получение данных приложения из базы
     * @return array
     */
    protected function getSettings(): array
    {
        $data = \X3Group\B24Api\Models\B24Api::where('member_id', $this->memberId)->first();
        if ($data)
            return $data->toArray();

        return [];
    }
}
