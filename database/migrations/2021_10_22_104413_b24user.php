<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class B24user extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('b24user', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
            $table->string('password');
            $table->rememberToken();
            $table->integer('user_id')->nullable();
            $table->string('member_id');//member_id
            $table->string('access_token');//AUTH_ID
            $table->string('refresh_token'); //REFRESH_ID
            $table->string('application_token');
            $table->string('domain');//DOMAIN
            $table->boolean('is_admin')->default(0);
            $table->integer('expires')->nullable();//AUTH_EXPIRES
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('b24user');
    }
}
