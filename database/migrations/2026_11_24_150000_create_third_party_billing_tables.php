<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Administration → Third Party: billing pulled from Amazon (AWS) and GoDaddy
 *   t_tp_bill      invoices (AWS) and orders (GoDaddy)
 *   t_tp_cost      AWS cost per month per service (Cost Explorer)
 *   t_tp_renewal   GoDaddy domains + subscriptions with expiry / renewal dates
 *   t_tp_sync_log  every sync run
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('t_tp_bill', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);                 // aws | godaddy
            $t->string('kind', 20);                     // invoice | order
            $t->string('ref_no', 100);                  // AWS invoice id · GoDaddy order id
            $t->date('bill_date')->nullable();
            $t->string('period', 7)->nullable();        // YYYY-MM
            $t->string('description', 500)->nullable();
            $t->decimal('amount', 14, 2)->default(0);
            $t->decimal('tax', 14, 2)->default(0);
            $t->string('currency', 3)->nullable();
            $t->date('due_date')->nullable();
            $t->string('status', 40)->nullable();
            $t->json('raw')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'ref_no']);
            $t->index(['provider', 'bill_date']);
        });

        Schema::create('t_tp_cost', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20)->default('aws');
            $t->string('period', 7);                    // YYYY-MM
            $t->string('service', 150);
            $t->decimal('amount', 14, 4)->default(0);
            $t->string('currency', 3)->nullable();
            $t->boolean('estimated')->default(false);   // month not closed yet
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'period', 'service']);
        });

        Schema::create('t_tp_renewal', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20)->default('godaddy');
            $t->string('kind', 20);                     // domain | subscription
            $t->string('ref', 150);                     // domain name · subscription id
            $t->string('name', 255)->nullable();
            $t->string('product', 255)->nullable();
            $t->string('status', 40)->nullable();
            $t->date('expires_at')->nullable();
            $t->boolean('renew_auto')->default(false);
            $t->json('raw')->nullable();
            $t->timestamp('synced_at')->nullable();
            $t->timestamps();
            $t->unique(['provider', 'kind', 'ref']);
            $t->index('expires_at');
        });

        Schema::create('t_tp_sync_log', function (Blueprint $t) {
            $t->id();
            $t->string('provider', 20);
            $t->boolean('ok')->default(false);
            $t->string('message', 1000)->nullable();
            $t->json('counts')->nullable();
            $t->unsignedBigInteger('run_by')->nullable(); // user / staff id · null = schedule
            $t->timestamp('started_at')->nullable();
            $t->timestamp('finished_at')->nullable();
            $t->timestamps();
            $t->index(['provider', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('t_tp_sync_log');
        Schema::dropIfExists('t_tp_renewal');
        Schema::dropIfExists('t_tp_cost');
        Schema::dropIfExists('t_tp_bill');
    }
};
