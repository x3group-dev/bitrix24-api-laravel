<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b24_apps', function (Blueprint $table) {
            $table->id();

            $table->string('member_id')->unique();
            $table->string('domain');
            $table->string('application_token')->nullable();
            $table->string('access_token');
            $table->string('refresh_token');
            $table->integer('expires_in');
            $table->integer('expires');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b24_apps');
    }
};
