<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('topic')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('archived_by')->nullable()->constrained('users');
        });

        Schema::table('channel_members', function (Blueprint $table) {
            $table->timestamp('last_read_at')->nullable();
            $table->integer('unread_count')->default(0);
        });
    }

    public function down()
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['topic', 'is_archived', 'archived_at', 'archived_by']);
        });

        Schema::table('channel_members', function (Blueprint $table) {
            $table->dropColumn(['last_read_at', 'unread_count']);
        });
    }
};