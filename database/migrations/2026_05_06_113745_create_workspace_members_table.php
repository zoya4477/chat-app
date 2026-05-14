<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateWorkspaceMembersTable extends Migration
{
    public function up()
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('role', ['admin', 'member', 'guest'])->default('member');
            $table->json('permissions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->timestamps();
            
            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('workspace_members');
    }
}