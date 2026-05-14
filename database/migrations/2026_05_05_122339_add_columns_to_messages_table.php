<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('messages', function (Blueprint $table) {
            // DON'T use nullableChange() - use simple syntax
            if (!Schema::hasColumn('messages', 'group_id')) {
                $table->unsignedBigInteger('group_id')->nullable();
                $table->foreign('group_id')->references('id')->on('groups')->onDelete('cascade');
            }
            
            if (!Schema::hasColumn('messages', 'message_type')) {
                $table->enum('message_type', ['text', 'image', 'file'])->default('text');
            }
            
            if (!Schema::hasColumn('messages', 'file_url')) {
                $table->text('file_url')->nullable();
            }
            
            if (!Schema::hasColumn('messages', 'file_name')) {
                $table->string('file_name')->nullable();
            }
            
            if (!Schema::hasColumn('messages', 'file_size')) {
                $table->integer('file_size')->nullable();
            }
            
            if (!Schema::hasColumn('messages', 'reactions')) {
                $table->json('reactions')->nullable();
            }
        });
    }

    public function down()
    {
        Schema::table('messages', function (Blueprint $table) {
            if (Schema::hasColumn('messages', 'group_id')) {
                $table->dropForeign(['group_id']);
                $table->dropColumn('group_id');
            }
            
            if (Schema::hasColumn('messages', 'message_type')) {
                $table->dropColumn('message_type');
            }
            
            if (Schema::hasColumn('messages', 'file_url')) {
                $table->dropColumn('file_url');
            }
            
            if (Schema::hasColumn('messages', 'file_name')) {
                $table->dropColumn('file_name');
            }
            
            if (Schema::hasColumn('messages', 'file_size')) {
                $table->dropColumn('file_size');
            }
            
            if (Schema::hasColumn('messages', 'reactions')) {
                $table->dropColumn('reactions');
            }
        });
    }
};