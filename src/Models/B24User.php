<?php

namespace X3Group\Bitrix24\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property int $user_id
 * @property string $member_id
 * @property string $access_token
 * @property string $refresh_token
 * @property int $expires
 * @property int $expires_in
 * @property string $domain
 * @property int $error_update
 */
class B24User extends Authenticatable
{
    protected $fillable = [
        'user_id',
        'member_id',
        'access_token',
        'refresh_token',
        'domain',
        'expires',
        'expires_in',
        'is_admin',
        'error_update',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function getMemberId(){
        return $this->member_id;
    }

    public function b24app(): BelongsTo
    {
        return $this->belongsTo(B24App::class, 'member_id', 'member_id');
    }
}
