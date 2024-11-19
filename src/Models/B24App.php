<?php

namespace X3Group\Bitrix24\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $access_token
 * @property string $refresh_token
 * @property string $domain
 * @property string $member_id
 * @property int $expires
 * @property int $expires_in
 * @property string $application_token
 */
class B24App extends Model
{
    protected $fillable = [
        'access_token',
        'refresh_token',
        'domain',
        'member_id',
        'expires',
        'expires_in',
        'application_token',
    ];
}
