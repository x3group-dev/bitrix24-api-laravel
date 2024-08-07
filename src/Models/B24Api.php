<?php

namespace X3Group\B24Api\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class B24Api extends Model
{
//    use HasFactory;
    protected $table = 'b24api';
    protected $fillable = [
        'access_token',
        'refresh_token',
        'client_endpoint',

        'domain',
        'member_id',

        'expires',
        'expires_in',

        'user_id',
        'status',
        'scope',
        'application_token',

        'error_update',
    ];
}
