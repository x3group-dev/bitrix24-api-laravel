<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('b24_users', function (Blueprint $table) {
            $table->id();

            $table->string('member_id');

            $table->foreign('member_id')
                ->references('member_id')
                ->on('b24_apps')
                ->cascadeOnDelete();

            $table->string('domain');
            $table->integer('user_id');
            $table->boolean('is_admin');
            $table->string('access_token');
            $table->string('refresh_token');
            $table->integer('expires_in');
            $table->integer('expires');

            $table->timestamps();

            $table->unique(['member_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('b24_users');
    }
};
