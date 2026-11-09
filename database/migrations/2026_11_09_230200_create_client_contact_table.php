<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rattachement des contacts aux clients : un role par rattachement
 * (gardien, comptable...) et un seul contact principal par client
 * (index unique partiel).
 */
return new class extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::create('client_contact', function (Blueprint $table): void {
            $table->string('tenant_id');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained('contacts')->cascadeOnDelete();
            $table->string('role', 30)->nullable();
            $table->boolean('principal')->default(false);

            $table->primary(['client_id', 'contact_id']);
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index('tenant_id');
            $table->index('contact_id');
        });

        DB::statement('CREATE UNIQUE INDEX client_contact_un_principal ON client_contact (client_id) WHERE principal');
    }

    /**
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('client_contact');
    }
};
