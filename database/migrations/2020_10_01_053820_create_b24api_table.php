<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateB24apiTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('b24api', function (Blueprint $table) {
            $table->id();
            $table->string('access_token');//AUTH_ID
            $table->string('refresh_token'); //REFRESH_ID
            $table->string('client_endpoint');

            $table->string('member_id');//member_id
            $table->string('domain');//DOMAIN

            $table->integer('expires')->nullable();
            $table->integer('expires_in');//AUTH_EXPIRES

            $table->integer('user_id')->nullable();

            $table->string('status')->nullable();
            $table->string('scope')->nullable();
            $table->string('application_token'); //APP_SID
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('b24api');
    }
}
