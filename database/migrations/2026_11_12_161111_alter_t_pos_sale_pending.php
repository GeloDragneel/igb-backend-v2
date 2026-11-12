<?php
// php artisan make:migration add_employee_to_t_pos_sale
// POS: employee as customer → Charge (deducted from the employee later) or Allowance (company benefit)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('t_pos_sale', function (Blueprint $table) {
            if (!Schema::hasColumn('t_pos_sale', 'employee_id')) {
                $table->unsignedBigInteger('employee_id')->nullable()->after('customer_id')->index();   // m_employee.id
            }
            if (!Schema::hasColumn('t_pos_sale', 'employee_name')) {
                $table->string('employee_name', 150)->nullable()->after('employee_id');
            }
            if (!Schema::hasColumn('t_pos_sale', 'sale_type')) {
                $table->string('sale_type', 10)->default('REGULAR')->after('employee_name')->index();  // REGULAR · CHARGE · ALLOWANCE
            }
        });
    }

    public function down(): void
    {
        Schema::table('t_pos_sale', function (Blueprint $table) {
            foreach (['sale_type', 'employee_name', 'employee_id'] as $c) {
                if (Schema::hasColumn('t_pos_sale', $c)) {
                    if ($c !== 'employee_name') $table->dropIndex([$c]);
                    $table->dropColumn($c);
                }
            }
        });
    }
};