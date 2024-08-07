<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('b24api', function (Blueprint $table) {
            $table->tinyInteger('error_update')->default(0);
        });

        Schema::table('b24user', function (Blueprint $table) {
            $table->tinyInteger('error_update')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('b24api', function (Blueprint $table) {
            $table->dropColumn('error_update');
        });

        Schema::table('b24user', function (Blueprint $table) {
            $table->dropColumn('error_update');
        });
    }
};
