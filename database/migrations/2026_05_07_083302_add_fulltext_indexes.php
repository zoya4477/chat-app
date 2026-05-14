<?php
// database/migrations/2024_01_01_000000_add_fulltext_indexes.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddFulltextIndexes extends Migration
{
    public function up()
    {
        // Add fulltext index to messages table for faster search
        DB::statement('ALTER TABLE messages ADD FULLTEXT INDEX messages_fulltext (message)');
        
        // Add indexes for other searchable columns
        Schema::table('users', function (Blueprint $table) {
            $table->index('name');
            $table->index('email');
        });
        
        Schema::table('channels', function (Blueprint $table) {
            $table->index('name');
            $table->index('description');
        });
    }

    public function down()
    {
        DB::statement('ALTER TABLE messages DROP INDEX messages_fulltext');
        
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['email']);
        });
        
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['description']);
        });
    }
}