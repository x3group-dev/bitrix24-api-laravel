<?php

namespace App\Http\Controllers\Bitrix24\Events;

use App\Http\Controllers\Controller;
use Bitrix24\SDK\Core\Exceptions\WrongSecuritySignatureException;
use Bitrix24\SDK\Services\RemoteEventsFabric;
use Illuminate\Http\Request;
use X3Group\Bitrix24\Models\B24App;

class OnApplicationUninstallController extends Controller
{
    public function handle(Request $request)
    {
        if (!RemoteEventsFabric::isCanProcess($request)) {
            return;
        }

        $memberId = $request->input('auth')['member_id'];

        $b24app = B24App::query()
            ->where('member_id', $memberId)
            ->first();

        if ($b24app === null) {
            return;
        }

        try {
            RemoteEventsFabric::init(resolve('b24log', [
                'memberId' => $memberId
            ]))
                ->createEvent(
                    request: $request,
                    applicationToken: $b24app->application_token,
                );

            $b24app->delete();
        } catch (WrongSecuritySignatureException $e) {
            return;
        }
    }
}
