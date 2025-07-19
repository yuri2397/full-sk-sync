<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('invoice_sync_buffer', function (Blueprint $table) {
            $table->id();

            // Informations de base de la facture
            $table->string('invoice_number')->index();
            $table->string('invoice_reference')->nullable();
            $table->string('client_code')->index();
            $table->string('client_name');
            $table->decimal('amount', 15, 2);
            $table->decimal('invoice_total', 15, 2)->nullable();
            $table->decimal('amount_paid', 15, 2)->default(0);
            $table->decimal('balance_due', 15, 2)->nullable();
            $table->string('invoice_date')->nullable();
            $table->string('due_date')->nullable();

            // Type de journal et statut Sage
            $table->enum('journal_type', ['VTEM', 'RANO', 'OTHER'])->nullable()->index();
            $table->string('sage_lettrage_code')->nullable();
            $table->boolean('is_lettred')->default(false)->index();

            // Informations de synchronisation
            $table->enum('sync_status', ['pending', 'synced', 'failed', 'excluded'])->default('pending')->index();
            $table->string('synced_at')->nullable();
            $table->text('sync_notes')->nullable();
            $table->string('sync_batch_id')->nullable()->index();

            // Informations client ENRICHIES
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->text('client_address')->nullable();
            $table->string('client_city')->nullable();
            $table->string('commercial_contact')->nullable();
            $table->decimal('client_credit_limit', 15, 2)->nullable();
            $table->string('client_payment_terms')->nullable();

            // Informations de retard et priorité AMÉLIORÉES
            $table->enum('overdue_category', [
                'NON_ECHU',
                'RETARD_1_30',
                'RETARD_31_60',
                'RETARD_61_90',
                'RETARD_PLUS_90'
            ])->nullable()->index();
            $table->integer('days_overdue')->nullable()->index();
            $table->enum('priority', ['SURVEILLANCE', 'NORMAL', 'URGENT', 'TRES_URGENT'])->nullable()->index();

            // Informations de recouvrement
            $table->enum('recovery_status', [
                'NEW',
                'IN_PROGRESS',
                'CONTACT_MADE',
                'PROMISE_TO_PAY',
                'LITIGATION',
                'WRITE_OFF',
                'COLLECTED'
            ])->default('NEW')->index();
            $table->string('last_contact_date')->nullable();
            $table->string('next_action_date')->nullable()->index();
            $table->text('recovery_notes')->nullable();

            // Métadonnées de la source ENRICHIES
            $table->string('source_entry_id')->nullable();
            $table->string('sage_guid')->nullable()->index();
            $table->string('document_guid')->nullable();
            $table->text('description')->nullable();
            $table->string('source_created_at')->nullable();
            $table->string('source_updated_at')->nullable();

            // Historique des modifications
            $table->json('change_history')->nullable();
            $table->string('last_sage_sync')->nullable();

            // Tentatives de synchronisation
            $table->integer('sync_attempts')->default(0);
            $table->string('last_sync_attempt')->nullable();
            $table->text('last_error_message')->nullable();

            // === PLUS DE SOFT DELETES NI TIMESTAMPS ===
            // $table->softDeletes();
            // $table->timestamps();

            // Index composés optimisés
            $table->index(['sync_status', 'priority']);
            $table->index(['client_code', 'sync_status']);
            $table->index(['sync_batch_id', 'sync_status']);
            $table->index(['overdue_category', 'priority']);
            $table->index(['recovery_status', 'next_action_date']);
            $table->index(['journal_type', 'is_lettred']);
            $table->index(['due_date', 'days_overdue']);

            // Contrainte unique (sans created_at dans l'index)
            $table->unique(['invoice_number', 'client_code', 'journal_type'], 'unique_invoice_client_journal');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoice_sync_buffer');
    }
};