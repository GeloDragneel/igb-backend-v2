<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Client → Master File: only creates the tables that are missing (old tbl2_documents, m_branch_type, m_tax_registration)
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('m_documents') && !Schema::hasTable('m_document') && !Schema::hasTable('m_document_list')) {
            Schema::create('m_documents', function (Blueprint $t) {
                $t->id();
                $t->string('alias', 50)->nullable();
                $t->string('document_name', 255);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('m_branch_type')) {
            Schema::create('m_branch_type', function (Blueprint $t) {
                $t->id();
                $t->string('name', 100);
                $t->timestamps();
            });
        }
        if (!Schema::hasTable('m_tax_registration') && !Schema::hasTable('m_tax_registrations')) {
            Schema::create('m_tax_registration', function (Blueprint $t) {
                $t->id();
                $t->string('description', 150);
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        // keeps the data: these tables may already have been there
    }
};