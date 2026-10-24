<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Crée la table des factures.
 *
 * Écran financier volontairement séparé du chantier (le conducteur de
 * travaux n'est pas l'administratif, décision actée section 5.1 du
 * cadrage fonctionnel). Une facture peut être un acompte, une facture
 * liée à une situation de travaux, ou la facture finale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('tenant_id')->nullable()->index();
            $table->foreignId('construction_site_id')->constrained('construction_sites')->cascadeOnDelete();
            $table->foreignId('work_progress_statement_id')->nullable()->constrained('work_progress_statements')->nullOnDelete();
            $table->string('invoice_number')->nullable();
            $table->string('type')->default('acompte'); // acompte, situation, finale
            $table->string('status')->default('a_emettre');
            $table->decimal('amount_ht', 10, 2);
            $table->decimal('amount_vat', 10, 2);
            $table->decimal('amount_ttc', 10, 2);
            $table->date('issued_at')->nullable();
            $table->date('due_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
