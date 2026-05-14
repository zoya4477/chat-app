<?php
// database/migrations/xxxx_create_message_reports_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateMessageReportsTable extends Migration
{
    public function up()
    {
        Schema::create('message_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_id')->constrained()->onDelete('cascade');
            $table->foreignId('reporter_id')->constrained('users')->onDelete('cascade');
            $table->text('reason');
            $table->enum('status', ['pending', 'resolved'])->default('pending');
            $table->enum('action_taken', ['approved', 'removed', null])->nullable();
            $table->timestamps();
            
            // One user can report a message only once
            $table->unique(['message_id', 'reporter_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('message_reports');
    }
}