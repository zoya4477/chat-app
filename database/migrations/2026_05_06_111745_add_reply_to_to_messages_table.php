// database/migrations/xxxx_add_reply_to_to_messages_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddReplyToToMessagesTable extends Migration
{
    public function up()
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('reply_to')->nullable()->after('group_id');
            $table->foreign('reply_to')->references('id')->on('messages')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to']);
            $table->dropColumn('reply_to');
        });
    }
}