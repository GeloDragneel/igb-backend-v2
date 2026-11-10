<?php
// php artisan make:migration create_t_send_request_table
// Send via … requests (SOA etc.) waiting for approval: PENDING → CONFIRMED (sent) / DECLINED / REVISED (back to the requester)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('t_send_request')) return;
        Schema::create('t_send_request', function (Blueprint $table) {
            $table->id();
            $table->string('doc_type', 30)->default('SOA');           // SOA …
            $table->string('reference_no', 60)->index();              // t_transaction.trans_number
            $table->string('print_type', 30)->nullable();             // SOA · C_SOA · SOA2 …
            $table->unsignedBigInteger('service_id')->nullable();     // SOA2 (one service)
            $table->string('label', 150)->nullable();                 // shown in the list / email subject
            $table->string('client_num', 50)->nullable()->index();
            $table->string('party_name', 255)->nullable();            // trade name
            $table->text('channels');                                 // ["email","viber",…]
            $table->text('emails')->nullable();                       // ["a@x.com",…]
            $table->text('contacts')->nullable();                     // [{"channel":"viber","value":"0917…"},…]
            $table->string('subject', 200)->nullable();
            $table->text('message')->nullable();
            $table->string('file_path', 255);                         // S3 Send_Request/…pdf
            $table->string('status', 20)->default('PENDING')->index(); // PENDING · REVISED · DECLINED · CONFIRMED
            $table->text('remarks')->nullable();                      // approver's reason (declined / revise)
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->unsignedBigInteger('approved_by')->nullable();    // who confirmed / declined / asked to revise
            $table->dateTime('approved_at')->nullable();
            $table->dateTime('sent_at')->nullable();                  // email sent
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_send_request');
    }
};