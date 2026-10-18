<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Philippine holidays 2024 – 2028 (from HOLIDAY.ods, 100 rows) → m_holiday
 *   Regular → regular (rate 1 = +100%) · Special → special (rate 0.3 = +30%) — the pay multipliers are in m_pay_rate
 *   a date that is already in m_holiday is updated (name / type), never duplicated
 */
return new class extends Migration
{
    private const HOLIDAYS = [
        // [date, name, type]
            ['2024-01-01', 'New Year\'s Day', 'regular'],
            ['2024-02-10', 'Chinese New Year', 'special'],
            ['2024-02-25', 'EDSA People Power Revolution', 'special'],
            ['2024-03-28', 'Maundy Thursday', 'regular'],
            ['2024-03-29', 'Good Friday', 'regular'],
            ['2024-03-30', 'Black Saturday', 'special'],
            ['2024-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2024-04-10', 'Eid al-Fitr', 'regular'],
            ['2024-05-01', 'Labor Day', 'regular'],
            ['2024-06-12', 'Independence Day', 'regular'],
            ['2024-06-17', 'Eid al-Adha', 'regular'],
            ['2024-08-21', 'Ninoy Aquino Day', 'special'],
            ['2024-08-26', 'National Heroes Day', 'regular'],
            ['2024-11-01', 'All Saints Day', 'special'],
            ['2024-11-30', 'Bonifacio Day', 'regular'],
            ['2024-12-08', 'Feast of Immaculate Conception', 'special'],
            ['2024-12-24', 'Christmas Eve', 'special'],
            ['2024-12-25', 'Christmas Day', 'regular'],
            ['2024-12-30', 'Rizal Day', 'regular'],
            ['2024-12-31', 'Last Day of the Year', 'special'],
            ['2025-01-01', 'New Year\'s Day', 'regular'],
            ['2025-01-29', 'Chinese New Year', 'special'],
            ['2025-02-25', 'EDSA People Power Revolution', 'special'],
            ['2025-03-31', 'Eid al-Fitr', 'regular'],
            ['2025-04-17', 'Maundy Thursday', 'regular'],
            ['2025-04-18', 'Good Friday', 'regular'],
            ['2025-04-19', 'Black Saturday', 'special'],
            ['2025-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2025-05-01', 'Labor Day', 'regular'],
            ['2025-06-12', 'Independence Day', 'regular'],
            ['2025-06-07', 'Eid al-Adha', 'regular'],
            ['2025-08-21', 'Ninoy Aquino Day', 'special'],
            ['2025-08-25', 'National Heroes Day', 'regular'],
            ['2025-11-01', 'All Saints Day', 'special'],
            ['2025-11-30', 'Bonifacio Day', 'regular'],
            ['2025-12-08', 'Feast of Immaculate Conception', 'special'],
            ['2025-12-24', 'Christmas Eve', 'special'],
            ['2025-12-25', 'Christmas Day', 'regular'],
            ['2025-12-30', 'Rizal Day', 'regular'],
            ['2025-12-31', 'Last Day of the Year', 'special'],
            ['2026-01-01', 'New Year\'s Day', 'regular'],
            ['2026-02-17', 'Chinese New Year', 'special'],
            ['2026-02-25', 'EDSA People Power Revolution', 'special'],
            ['2026-03-20', 'Eid al-Fitr', 'regular'],
            ['2026-04-02', 'Maundy Thursday', 'regular'],
            ['2026-04-03', 'Good Friday', 'regular'],
            ['2026-04-04', 'Black Saturday', 'special'],
            ['2026-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2026-05-01', 'Labor Day', 'regular'],
            ['2026-05-27', 'Eid al-Adha', 'regular'],
            ['2026-06-12', 'Independence Day', 'regular'],
            ['2026-08-21', 'Ninoy Aquino Day', 'special'],
            ['2026-08-31', 'National Heroes Day', 'regular'],
            ['2026-11-01', 'All Saints Day', 'special'],
            ['2026-11-30', 'Bonifacio Day', 'regular'],
            ['2026-12-08', 'Feast of Immaculate Conception', 'special'],
            ['2026-12-24', 'Christmas Eve', 'special'],
            ['2026-12-25', 'Christmas Day', 'regular'],
            ['2026-12-30', 'Rizal Day', 'regular'],
            ['2026-12-31', 'Last Day of the Year', 'special'],
            ['2027-01-01', 'New Year\'s Day', 'regular'],
            ['2027-02-06', 'Chinese New Year', 'special'],
            ['2027-02-25', 'EDSA People Power Revolution', 'special'],
            ['2027-03-09', 'Eid al-Fitr', 'regular'],
            ['2027-03-25', 'Maundy Thursday', 'regular'],
            ['2027-03-26', 'Good Friday', 'regular'],
            ['2027-03-27', 'Black Saturday', 'special'],
            ['2027-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2027-05-01', 'Labor Day', 'regular'],
            ['2027-05-16', 'Eid al-Adha', 'regular'],
            ['2027-06-12', 'Independence Day', 'regular'],
            ['2027-08-21', 'Ninoy Aquino Day', 'special'],
            ['2027-08-30', 'National Heroes Day', 'regular'],
            ['2027-11-01', 'All Saints Day', 'special'],
            ['2027-11-30', 'Bonifacio Day', 'regular'],
            ['2027-12-08', 'Feast of Immaculate Conception', 'special'],
            ['2027-12-24', 'Christmas Eve', 'special'],
            ['2027-12-25', 'Christmas Day', 'regular'],
            ['2027-12-30', 'Rizal Day', 'regular'],
            ['2027-12-31', 'Last Day of the Year', 'special'],
            ['2028-01-01', 'New Year\'s Day', 'regular'],
            ['2028-01-26', 'Chinese New Year', 'special'],
            ['2028-02-26', 'Eid al-Fitr', 'regular'],
            ['2028-02-25', 'EDSA People Power Revolution', 'special'],
            ['2028-04-13', 'Maundy Thursday', 'regular'],
            ['2028-04-14', 'Good Friday', 'regular'],
            ['2028-04-15', 'Black Saturday', 'special'],
            ['2028-04-09', 'Araw ng Kagitingan', 'regular'],
            ['2028-05-05', 'Eid al-Adha', 'regular'],
            ['2028-05-01', 'Labor Day', 'regular'],
            ['2028-06-12', 'Independence Day', 'regular'],
            ['2028-08-21', 'Ninoy Aquino Day', 'special'],
            ['2028-08-28', 'National Heroes Day', 'regular'],
            ['2028-11-01', 'All Saints Day', 'special'],
            ['2028-11-30', 'Bonifacio Day', 'regular'],
            ['2028-12-08', 'Feast of Immaculate Conception', 'special'],
            ['2028-12-24', 'Christmas Eve', 'special'],
            ['2028-12-25', 'Christmas Day', 'regular'],
            ['2028-12-30', 'Rizal Day', 'regular'],
            ['2028-12-31', 'Last Day of the Year', 'special'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('m_holiday')) return;
        $now = now();
        foreach (self::HOLIDAYS as [$date, $name, $type]) {
            DB::table('m_holiday')->updateOrInsert(
                ['holiday_date' => $date],
                ['name' => $name, 'holiday_type' => $type, 'updated_at' => $now, 'created_at' => $now]
            );
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('m_holiday')) return;
        DB::table('m_holiday')->whereIn('holiday_date', array_column(self::HOLIDAYS, 0))->delete();
    }
};