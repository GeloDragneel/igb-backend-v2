<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Biometric (TXT from the device: No · TMNo · EnNo · Name GMNo · Mode · DateTime)
 *   m_biometric_map  device EnNo → m_employee.id
 *   t_biometric_log  every raw punch (same EnNo + same second is saved once, so re-uploading a file is safe)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_biometric_map')) {
            Schema::create('m_biometric_map', function (Blueprint $t) {
                $t->id();
                $t->string('en_no', 20)->unique()->comment('EnNo on the device');
                $t->unsignedBigInteger('employee_id')->index()->comment('m_employee.id');
                $t->string('device_name', 100)->nullable()->comment('name saved on the device');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('t_biometric_log')) {
            Schema::create('t_biometric_log', function (Blueprint $t) {
                $t->id();
                $t->string('en_no', 20);
                $t->unsignedBigInteger('employee_id')->nullable()->index();
                $t->dateTime('punched_at')->comment('Philippine time, as on the device');
                $t->unsignedTinyInteger('mode')->nullable()->comment('device mode (not used to tell in / out)');
                $t->string('device_name', 100)->nullable();
                $t->string('batch', 40)->nullable()->comment('upload batch');
                $t->unsignedBigInteger('uploaded_by')->nullable();
                $t->timestamps();
                $t->unique(['en_no', 'punched_at'], 'bio_log_unique');
                $t->index(['employee_id', 'punched_at'], 'bio_log_emp_time');
            });
        }

        // BIO_METRIC_EMPLOYEE_ID_IN_DB (EnNo → employee_id)
        $map = [
            '4' => 10, '5' => 11, '6' => 21, '13' => 12, '18' => 39, '20' => 37, '26' => 25, '31' => 29,
            '2034' => 59, '2035' => 60, '2036' => 40, '5004' => 31, '5011' => 36, '5017' => 27, '5019' => 28,
            '5021' => 15, '5027' => 30, '5028' => 32, '5029' => 33, '5030' => 64, '5031' => 119, '6007' => 34,
        ];
        $now = now();
        DB::table('m_biometric_map')->insertOrIgnore(array_map(
            fn ($en, $emp) => ['en_no' => (string) $en, 'employee_id' => $emp, 'created_at' => $now, 'updated_at' => $now],
            array_keys($map), $map
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('t_biometric_log');
        Schema::dropIfExists('m_biometric_map');
    }
};