<?php
// php artisan make:migration create_task_management_tables
// Task Management: Activity List (m_jo_activity) · Job Orders (t_job_order) · the activities of each JO (t_job_order_activity)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    const ACTIVITIES = [
        'COLLECT DOCUMENTS',
        'TRANSMIT DOCUMENTS',
        'TRANSMIT BIR RETURN',
        'COLLECT BIR RETURN PAYMENTS',
        'INITIAL BIR RETURNS',
        'OVER THE COUNTER BIR RETURN PAYMENTS',
        'NOTARIAL DOCUMENTS',
        'COLLECT NOTARIAL DOCUMENTS',
        'PURCHASE ORDER',
        'PURCHASE ORDER PAYMENTS',
        'TRANSMIT BILLING',
        'COLLECT PROFESSIONAL FEES',
        'BANK DEPOSIT/WITHDRAW/ENCASH',
        'PAYMENT - SSS CONTRIBUTION',
        'COLLECT GB FUEL/CAI-B SALES',
        'SUBMIT DOCUMENTS TO DENR/CITY HALL/BIR',
        'PERMIT PAYMENT',
        'OTHER PRIORITY',
        'DELIVER DOCUMENTS',
        'PAYMENT - PHIC',
        'PAYMENT - HDMF SAVINGS AND MPL',
        'PAYMENT - MORESCO BILL',
        'PAYMENT - WATER DISTRICT',
        'PAYMENT - SSS CONTRIBUTION AND SSS LOAN',
        'INQUIRY - SSS',
        'COLLECT PAYMENTS',
        'TRANSMIT BIR RETURN FOR PAYMENT ISSUANCE',
        'DOCUMENTS FOR CLIENT SIGNATURE',
        'DOCUMENTS FOR BIR RECEIVE',
        'PAYMENT - PURCHASED',
        'PICK-UP DOCUMENTS (NON RECURRING)',
        'COLLECT SHIPMENT BUDGET',
    ];

    public function up(): void
    {
        // Task Management → Activity List (the activities an employee can pick in a JO)
        if (!Schema::hasTable('m_jo_activity')) {
            Schema::create('m_jo_activity', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('description', 255)->nullable();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort')->default(0);          // order in the dropdown
                $table->unsignedBigInteger('created_by')->nullable();     // m_user_staff.id
                $table->timestamps();
            });
        }

        // the activities of the old system (same order as its dropdown)
        if (!DB::table('m_jo_activity')->exists()) {
            $now = now();
            DB::table('m_jo_activity')->insert(array_map(fn ($name, $i) => [
                'name' => $name, 'is_active' => true, 'sort' => $i + 1, 'created_at' => $now, 'updated_at' => $now,
            ], self::ACTIVITIES, array_keys(self::ACTIVITIES)));
        }

        // Task Management → Prepare JO
        if (!Schema::hasTable('t_job_order')) {
            Schema::create('t_job_order', function (Blueprint $table) {
                $table->id();
                $table->string('jo_no', 30)->unique();                   // JO-202611-0001
                $table->date('jo_date')->index();
                $table->boolean('no_time')->default(true);              // true = whole day, no time range
                $table->time('time_from')->nullable();
                $table->time('time_to')->nullable();
                $table->unsignedBigInteger('assigned_by')->nullable()->index();   // m_employee.id
                $table->unsignedBigInteger('assigned_to')->nullable()->index();   // m_employee.id
                $table->string('location_type', 10)->default('client');           // client · other
                $table->unsignedBigInteger('caid')->nullable()->index();          // m_client_account.id (location = client account)
                $table->string('location_other', 150)->nullable();                // e.g. Phone call, Office, BIR
                $table->string('status', 20)->default('PENDING')->index();       // PENDING · IN_PROGRESS · COMPLETED · CANCELLED
                $table->text('remarks')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->unsignedBigInteger('completed_by')->nullable();           // m_employee.id
                $table->unsignedBigInteger('created_by')->nullable();             // m_user_staff.id
                $table->unsignedBigInteger('updated_by')->nullable();             // m_user_staff.id
                $table->timestamps();
            });
        }

        // the activities of one JO (picked from the Activity List, with remarks, ticked when done)
        if (!Schema::hasTable('t_job_order_activity')) {
            Schema::create('t_job_order_activity', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('job_order_id')->index();
                $table->unsignedBigInteger('activity_id')->nullable();           // m_jo_activity.id
                $table->string('activity', 150);                                 // name at the time (kept if the activity is renamed)
                $table->text('remarks')->nullable();
                $table->boolean('is_done')->default(false);
                $table->dateTime('done_at')->nullable();
                $table->unsignedSmallInteger('sort')->default(0);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_job_order_activity');
        Schema::dropIfExists('t_job_order');
        Schema::dropIfExists('m_jo_activity');
    }
};