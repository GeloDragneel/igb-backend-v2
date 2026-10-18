<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance, overtime and leave (Philippine time)
 *   t_attendance     one row per employee per work date: time in, break in, break out, time out + computed minutes
 *   t_overtime       overtime requests with approval
 *   t_leave_request  leave requests with approval
 *
 * work_date = the date the shift STARTS (a 10 PM – 7 AM shift on Oct 7 is work_date 2026-10-07).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_attendance', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->date('work_date');
            $t->unsignedBigInteger('shift_id')->nullable()->comment('m_shift used that day');
            // schedule of the day (copied from the shift so later shift edits do not change old days)
            $t->dateTime('sched_time_in')->nullable();
            $t->dateTime('sched_break_in')->nullable();
            $t->dateTime('sched_break_out')->nullable();
            $t->dateTime('sched_time_out')->nullable();
            // actual punches
            $t->dateTime('time_in')->nullable();
            $t->dateTime('break_in')->nullable();
            $t->dateTime('break_out')->nullable();
            $t->dateTime('time_out')->nullable();
            // computed (minutes)
            $t->unsignedSmallInteger('worked_minutes')->default(0);
            $t->unsignedSmallInteger('late_minutes')->default(0);
            $t->unsignedSmallInteger('undertime_minutes')->default(0);
            $t->unsignedSmallInteger('overbreak_minutes')->default(0);
            $t->unsignedSmallInteger('night_diff_minutes')->default(0);
            $t->unsignedSmallInteger('approved_ot_minutes')->default(0)->comment('from approved t_overtime');
            $t->string('day_type', 20)->default('regular')->comment('regular | rest_day | special | regular_holiday');
            $t->string('status', 20)->default('present')->comment('present | absent | on_leave | half_day | holiday | rest_day');
            $t->string('source', 20)->default('manual')->comment('biometric | manual | mobile | web');
            $t->string('remarks', 255)->nullable();
            $t->unsignedBigInteger('payroll_run_id')->nullable()->comment('locked once paid');
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['employee_id', 'work_date'], 'attendance_emp_date_unique');
            $t->index('work_date');
        });

        Schema::create('t_overtime', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->date('work_date');
            $t->dateTime('time_start');
            $t->dateTime('time_end');
            $t->unsignedSmallInteger('minutes')->default(0);
            $t->string('pay_rate_code', 30)->nullable()->comment('m_pay_rate.code, e.g. REG_OT, RD_OT');
            $t->string('reason', 255);
            $t->string('status', 20)->default('pending')->comment('pending | approved | rejected | cancelled');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('approver_remarks', 255)->nullable();
            $t->unsignedBigInteger('payroll_run_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'work_date']);
            $t->index('status');
        });

        Schema::create('t_leave_request', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('employee_id');
            $t->foreignId('leave_type_id')->constrained('m_leave_type');
            $t->date('date_from');
            $t->date('date_to');
            $t->decimal('days', 5, 2)->comment('working days asked (0.5 = half day)');
            $t->boolean('is_half_day')->default(false);
            $t->string('half_day_part', 2)->nullable()->comment('AM | PM');
            $t->string('reason', 255);
            $t->string('attachment', 255)->nullable();
            $t->string('status', 20)->default('pending')->comment('pending | approved | rejected | cancelled');
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->dateTime('approved_at')->nullable();
            $t->string('approver_remarks', 255)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'date_from']);
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_leave_request');
        Schema::dropIfExists('t_overtime');
        Schema::dropIfExists('t_attendance');
    }
};
