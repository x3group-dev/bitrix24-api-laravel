<?php

namespace X3Group\B24Api\Http\Controllers;

use Illuminate\Foundation\Validation\ValidatesRequests;
use Illuminate\Support\Facades\Log;
use X3Group\B24Api\B24Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class B24InstallController extends Controller
{
    use ValidatesRequests;

    public function install(Request $request)
    {
        $memberId = null;
        if ($request->has('auth') && !empty($request->get('auth')['member_id']))
            $memberId = $request->get('auth')['member_id'];

        if ($request->has('member_id') && !empty($request->get('member_id')))
            $memberId = $request->get('member_id');

        try {
            $resultInstall = B24Api::install($memberId, $request);
            if ($resultInstall['install'] == true) {
                if ($resultInstall['rest_only']) {
                    return '';
                } else {
                    return view('b24api/install', []);
                }
            }
        } catch (\Exception $exception) {

        }
    }
}
