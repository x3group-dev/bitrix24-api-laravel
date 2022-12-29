<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class ActiveErrorreauth extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('b24api', function (Blueprint $table) {
            $table->boolean('active')->default(1)->after('id');
            $table->integer('error_get_token')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('b24api', function (Blueprint $table) {
            $table->dropColumn('active');
            $table->dropColumn('error_get_token');
        });
    }
}
