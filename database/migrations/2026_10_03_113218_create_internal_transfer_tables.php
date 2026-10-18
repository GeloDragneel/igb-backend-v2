<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('t_internal_transfer_master')) {
            Schema::create('t_internal_transfer_master', function (Blueprint $t) {
                $t->id();                                          // ID
                $t->string('transfer_no', 40)->unique();           // TransferNo
                $t->string('document_no', 100)->nullable();        // DocumentNo
                $t->string('remarks', 255)->nullable();            // Remarks
                $t->unsignedBigInteger('added_by')->index();       // UserID
                $t->timestamps();                                  // DateAdded
            });
        }

        if (!Schema::hasTable('t_internal_transfer_detail')) {
            Schema::create('t_internal_transfer_detail', function (Blueprint $t) {
                $t->id();                                                // ID
                $t->string('transfer_no', 40)->index();                 // TransferNo
                $t->unsignedBigInteger('item_id')->default(0);          // ItemID
                $t->unsignedBigInteger('from_location_id')->default(0); // From
                $t->unsignedBigInteger('to_location_id')->default(0);   // To
                $t->decimal('qty', 15, 4)->default(0);                  // Qty
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('t_internal_transfer_detail');
        Schema::dropIfExists('t_internal_transfer_master');
    }
};