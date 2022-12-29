<?php

namespace X3Group\B24Api\Http\Controllers;

use Bitrix24Api\ApiClient;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Bus\DispatchesJobs;
use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\Log;
use X3Group\B24Api\B24Api;
use X3Group\B24Api\B24ApiUser;
use Illuminate\Http\Request;

class B24UserController extends B24Controller
{
    use AuthorizesRequests, DispatchesJobs, ValidatesRequests;

    protected string $memberId;
    protected string $eventToken;
    protected ApiClient $apiClient;
    protected B24Api $api;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->api = new B24ApiUser($this->memberId);
    }
}
