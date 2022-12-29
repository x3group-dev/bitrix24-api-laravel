<?php

namespace X3Group\B24Api\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class B24ApiQueryStat extends Model
{
//    use HasFactory;
    protected $table = 'b24api_query_stat';
    protected $fillable = [
        'member_id',
        'domain',
        'month',
        'query_cnt',
    ];
}
