<?php

namespace X3Group\B24Api\Http\Controllers;

use Bitrix24Api\ApiClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use X3Group\B24Api\B24Api;
use X3Group\B24Api\Classes\QueryStatMonth;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class B24Controller extends Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected string $memberId;
    protected int $userId;
    protected string $eventToken;
//    protected ApiClient $apiClient;
//    protected B24Api $api;

    public function __construct(Request $request)
    {
        if ($request->has('event_token')) {
            $this->eventToken = $request->post('event_token');
        }

        if ($request->has('user_id') && $request->post('user_id')) {
            $this->userId = $request->post('user_id');
        }

        if ($request->has('member_id') && $request->post('member_id')) {
            $this->memberId = $request->post('member_id');
        } elseif ($request->has('auth') && $request->post('auth')['member_id']) {
            $this->memberId = $request->post('auth')['member_id'];
        } elseif ($request->has('auth') && $request->json('auth')['member_id']) {
            $this->memberId = $request->json('auth')['member_id'];
        } elseif ($request->hasHeader('X-b24-Member-Id') && $request->header('X-b24-Member-Id')) {
            $this->memberId = $request->header('X-b24-Member-Id');
        } else {
            throw new \Exception('memberId is null');
        }

        if ($this->memberId) {
            QueryStatMonth::add($this->memberId);
//            $this->api = new B24Api($this->memberId);
//            $this->apiClient = $this->api->getApi();
        }
    }

    public function getEventToken(): ?string
    {
        return $this->eventToken;
    }

    public function index(Request $request)
    {
//        $this->api = new B24Api($this->memberId);
//        $this->apiClient = $this->api->getApi();

//        $this->apiClient->request('task.automation.trigger.add', ['FIELDS' => ['CODE' => 'extask_comment_add', 'NAME' => 'Добавлена задача из внешнего КП']]);
        return view('b24api/index', []);
    }

//    public function getApi(): \X3Group\B24Api\B24Api
//    {
//        return $this->api;
//    }
//
//    public function getApiClient(): ApiClient
//    {
//        return $this->apiClient;
//    }

    /**
     * @return array|mixed|string|null
     */
    public function getMemberId(): mixed
    {
        return $this->memberId;
    }
}
