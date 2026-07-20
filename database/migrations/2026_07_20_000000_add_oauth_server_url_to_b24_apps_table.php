<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('b24_apps', function (Blueprint $table) {
            $table->string('oauth_server_url')->nullable()->after('domain');
        });
    }
    public function down(): void {
        Schema::table('b24_apps', fn (Blueprint $t) => $t->dropColumn('oauth_server_url'));
    }
};
