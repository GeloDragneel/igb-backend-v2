<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administration → User Logs: every INSERT / UPDATE / DELETE done by a staff login (m_user_staff).
 * Written by App\Http\Middleware\LogStaffActivity.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_user_logs')) return;

        Schema::create('t_user_logs', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->char('request_id', 36);                       // all statements of one click share it
            $t->unsignedBigInteger('user_staff_id');          // m_user_staff.id
            $t->string('username', 100)->nullable();          // copied, so the log survives renames
            $t->string('employee_name', 150)->nullable();
            $t->string('action', 10);                         // INSERT | UPDATE | DELETE
            $t->string('table_name', 64);
            $t->string('record_id', 64)->nullable();          // id when known (insert id / where id = …)
            $t->longText('changes')->nullable();              // JSON: column => value (insert / update)
            $t->text('where_clause')->nullable();             // update / delete condition with values
            $t->text('sql_text')->nullable();                 // the statement, values filled in
            $t->string('method', 10)->nullable();             // POST / PUT / DELETE …
            $t->string('path', 255)->nullable();              // api/sales/12
            $t->string('route_action', 191)->nullable();      // SalesController@update
            $t->string('ip', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->dateTime('created_at')->useCurrent();

            $t->index('created_at', 'ul_created');
            $t->index(['user_staff_id', 'created_at'], 'ul_user_created');
            $t->index(['table_name', 'created_at'], 'ul_table_created');
            $t->index('request_id', 'ul_request');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_user_logs');
    }
};