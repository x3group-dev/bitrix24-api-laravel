<?php

namespace X3Group\B24Api\Classes;

use Illuminate\Support\Facades\DB;

class QueryStatMonth
{
    public static function add($member_id)
    {
        DB::table('b24api_query_stat')
            ->updateOrInsert(['member_id' => $member_id, 'month' => date('Y-m-01')],
                ['query_cnt' => DB::raw('query_cnt+1')]);
    }
}
