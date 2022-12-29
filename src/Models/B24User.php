<?php

namespace X3Group\B24Api\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * @property mixed $member_id
 */
class B24User extends Authenticatable
{
    use HasFactory;
    protected $table = 'b24user';
    protected $fillable = [
        'user_id',
        'password',
        'member_id',
        'access_token',
        'refresh_token',
        'application_token',
        'domain',
        'expires',
        'expires_in',
        'is_admin'
    ];
    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_admin' => 'boolean',
    ];

    public function getMemberId(){
        return $this->member_id;
    }
}
