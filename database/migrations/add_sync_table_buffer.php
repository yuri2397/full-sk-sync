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
            $table->decimal('amount_paid', 15, 2)->default(0); // AJOUT: Montant déjà payé
            $table->decimal('balance_due', 15, 2)->nullable(); // AJOUT: Solde restant à recouvrer
            $table->date('invoice_date')->nullable();
            $table->date('due_date')->nullable();

            // AJOUT: Type de journal et statut Sage
            $table->enum('journal_type', ['VTEM', 'RANO', 'OTHER'])->nullable()->index();
            $table->string('sage_lettrage_code')->nullable(); // Code de lettrage Sage
            $table->boolean('is_lettred')->default(false)->index(); // Si lettré dans Sage

            // Informations de synchronisation
            $table->enum('sync_status', ['pending', 'synced', 'failed', 'excluded'])->default('pending')->index();
            $table->timestamp('synced_at')->nullable();
            $table->text('sync_notes')->nullable();
            $table->string('sync_batch_id')->nullable()->index();

            // Informations client ENRICHIES
            $table->string('client_phone')->nullable();
            $table->string('client_email')->nullable();
            $table->text('client_address')->nullable();
            $table->string('client_city')->nullable(); // AJOUT: Ville
            $table->string('commercial_contact')->nullable();
            $table->decimal('client_credit_limit', 15, 2)->nullable(); // AJOUT: Encours autorisé
            $table->string('client_payment_terms')->nullable(); // AJOUT: Conditions de règlement

            // Informations de retard et priorité AMÉLIORÉES
            $table->enum('overdue_category', [
                'NON_ECHU',        // MODIFIÉ: Plus clair
                'RETARD_1_30',     // MODIFIÉ: Correspond aux calculs Sage
                'RETARD_31_60',
                'RETARD_61_90',
                'RETARD_PLUS_90'
            ])->nullable()->index();
            $table->integer('days_overdue')->nullable()->index(); // AJOUT: Index pour tri
            $table->enum('priority', ['SURVEILLANCE', 'NORMAL', 'URGENT', 'TRES_URGENT'])->nullable()->index(); // MODIFIÉ: Correspond aux calculs

            // AJOUT: Informations de recouvrement
            $table->enum('recovery_status', [
                'NEW',              // Nouvelle facture
                'IN_PROGRESS',      // En cours de recouvrement
                'CONTACT_MADE',     // Contact établi
                'PROMISE_TO_PAY',   // Promesse de paiement
                'LITIGATION',       // Contentieux
                'WRITE_OFF',        // Créance passée en perte
                'COLLECTED'         // Récupérée
            ])->default('NEW')->index();
            $table->date('last_contact_date')->nullable(); // AJOUT: Dernière action de recouvrement
            $table->date('next_action_date')->nullable()->index(); // AJOUT: Prochaine action planifiée
            $table->text('recovery_notes')->nullable(); // AJOUT: Notes de recouvrement

            // Métadonnées de la source ENRICHIES
            $table->string('source_entry_id')->nullable(); // ID de F_ECRITUREC
            $table->string('sage_guid')->nullable()->index(); // AJOUT: GUID Sage pour traçabilité
            $table->string('document_guid')->nullable(); // AJOUT: GUID du document
            $table->text('description')->nullable();
            $table->timestamp('source_created_at')->nullable();
            $table->timestamp('source_updated_at')->nullable();

            // AJOUT: Historique des modifications
            $table->json('change_history')->nullable(); // Pour tracker les changements
            $table->timestamp('last_sage_sync')->nullable(); // Dernière synchro avec Sage

            // Tentatives de synchronisation
            $table->integer('sync_attempts')->default(0);
            $table->timestamp('last_sync_attempt')->nullable();
            $table->text('last_error_message')->nullable();

            // AJOUT: Soft deletes pour historique
            $table->softDeletes();
            $table->timestamps();

            // Index composés optimisés
            $table->index(['sync_status', 'priority']);
            $table->index(['client_code', 'sync_status']);
            $table->index(['sync_batch_id', 'sync_status']);
            $table->index(['overdue_category', 'priority']); // AJOUT: Pour dashboard recouvrement
            $table->index(['recovery_status', 'next_action_date']); // AJOUT: Pour planning actions
            $table->index(['journal_type', 'is_lettred']); // AJOUT: Pour filtres Sage
            $table->index(['due_date', 'days_overdue']); // AJOUT: Pour calculs retards
            $table->index(['created_at', 'sync_status']); // AJOUT: Pour suivi chronologique

            // Contrainte unique améliorée
            $table->unique(['invoice_number', 'client_code', 'journal_type'], 'unique_invoice_client_journal');
        });
    }

    public function down()
    {
        Schema::dropIfExists('invoice_sync_buffer');
    }
};
