<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->bigInteger('expires_in')->change();
            $table->bigInteger('expires')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->integer('expires_in')->change();
            $table->integer('expires')->change();
        });
    }
};
