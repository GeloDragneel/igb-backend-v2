<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Staff → Calendar
   t_calendar_event        the events (one row per event; a repeating event is one row)
   t_calendar_event_guest  the employees it is shared with (they see it on their calendar + get the "due today" popup)
   m_calendar_access       per employee: may read / write / delete (set by the IT Officer) */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('t_calendar_event')) {
            Schema::create('t_calendar_event', function (Blueprint $t) {
                $t->id();
                $t->string('kind', 20)->default('event');              // event | task | meeting | reminder
                $t->string('title', 150);
                $t->text('description')->nullable();
                $t->string('location', 150)->nullable();
                $t->dateTime('start_at');
                $t->dateTime('end_at');
                $t->boolean('all_day')->default(false);
                $t->string('color', 16)->default('#2563eb');
                $t->string('repeat', 10)->default('none');           // none | daily | weekly | monthly | yearly
                $t->date('repeat_until')->nullable();
                $t->boolean('everyone')->default(false);              // shared with every employee
                $t->boolean('is_done')->default(false);               // tasks: ticked when finished
                $t->unsignedBigInteger('created_by_emp')->nullable(); // m_employee.id
                $t->string('created_by_name', 120)->nullable();
                $t->timestamps();
                $t->index(['start_at', 'end_at']);
                $t->index('created_by_emp');
            });
        }
        if (!Schema::hasTable('t_calendar_event_guest')) {
            Schema::create('t_calendar_event_guest', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('event_id');
                $t->unsignedBigInteger('employee_id');
                $t->unique(['event_id', 'employee_id']);
                $t->index('employee_id');
            });
        }
        if (!Schema::hasTable('m_calendar_access')) {
            Schema::create('m_calendar_access', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('employee_id')->unique();
                $t->boolean('can_read')->default(true);
                $t->boolean('can_write')->default(false);
                $t->boolean('can_delete')->default(false);
                $t->string('updated_by_name', 120)->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_calendar_event_guest');
        Schema::dropIfExists('t_calendar_event');
        Schema::dropIfExists('m_calendar_access');
    }
};
