<?php

namespace App\Http\Controllers\Bitrix24\Events;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use X3Group\Bitrix24\Models\B24App;

class DemoOnApplicationInstallController extends Controller
{
    public function handle(Request $request)
    {
        $memberId = $request->input('auth')['member_id'];
        $applicationToken = $request->input('auth')['application_token'];

        $b24app = B24App::query()
            ->where('member_id', $memberId)
            ->whereNull('application_token')
            ->first();

        if ($b24app) {
            $b24app->application_token = $applicationToken;
            $b24app->save();
        }
    }
}
