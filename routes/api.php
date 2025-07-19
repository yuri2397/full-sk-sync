<?php

// routes/api.php - App Locale

use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\LocalSyncController;

/*
|--------------------------------------------------------------------------
| API Routes pour l'app locale de synchronisation (VERSION AMÉLIORÉE)
|--------------------------------------------------------------------------
*/

Route::prefix('sync')->group(function () {

    // === GESTION DES DUMPS ===

    // 1. Dump des factures depuis Sage vers la table tampon
    Route::any('/dump-invoices', [LocalSyncController::class, 'dumpInvoices'])
        ->name('sync.dump-invoices');

    // === RÉCUPÉRATION DES DONNÉES ===

    // 2. Récupérer les factures non synchronisées (avec filtres avancés)
    Route::get('/unsynced-invoices', [LocalSyncController::class, 'getUnsyncedInvoices'])
        ->name('sync.unsynced-invoices');

    // === MARQUAGE DES STATUTS ===

    // 3. Marquer des factures comme synchronisées
    Route::post('/mark-synced', [LocalSyncController::class, 'markAsSynced'])
        ->name('sync.mark-synced');

    // 4. Marquer des factures comme échouées
    Route::post('/mark-failed', [LocalSyncController::class, 'markAsFailed'])
        ->name('sync.mark-failed');

    // === MONITORING ET STATS ===

    // 5. Test de connectivité
    Route::get('/ping', [LocalSyncController::class, 'ping'])
        ->name('sync.ping');

    // 6. Statistiques enrichies
    Route::get('/stats', [LocalSyncController::class, 'getStats'])
        ->name('sync.stats');

    // === MAINTENANCE ===

    // 7. Nettoyage des anciennes données
    Route::post('/cleanup', [LocalSyncController::class, 'cleanup'])
        ->name('sync.cleanup');

});

/*
|--------------------------------------------------------------------------
| Routes additionnelles pour gestion avancée (OPTIONNEL)
|--------------------------------------------------------------------------
*/

Route::prefix('invoices')->group(function () {

    // === GESTION INDIVIDUELLE DES FACTURES ===

    // Détail d'une facture
    Route::get('/{id}', [LocalSyncController::class, 'getInvoiceDetail'])
        ->name('invoices.detail');

    // Mise à jour du statut de recouvrement
    Route::patch('/{id}/recovery-status', [LocalSyncController::class, 'updateRecoveryStatus'])
        ->name('invoices.update-recovery-status');

    // Ajouter une note de recouvrement
    Route::post('/{id}/recovery-note', [LocalSyncController::class, 'addRecoveryNote'])
        ->name('invoices.add-recovery-note');

    // Planifier une action de recouvrement
    Route::post('/{id}/schedule-action', [LocalSyncController::class, 'scheduleRecoveryAction'])
        ->name('invoices.schedule-action');

});

/*
|--------------------------------------------------------------------------
| Routes de dashboard pour monitoring (OPTIONNEL)
|--------------------------------------------------------------------------
*/

Route::prefix('dashboard')->group(function () {

    // Tableau de bord principal
    Route::get('/overview', [LocalSyncController::class, 'getDashboardOverview'])
        ->name('dashboard.overview');

    // Factures urgentes
    Route::get('/urgent-invoices', [LocalSyncController::class, 'getUrgentInvoices'])
        ->name('dashboard.urgent-invoices');

    // Actions de recouvrement du jour
    Route::get('/todays-actions', [LocalSyncController::class, 'getTodaysRecoveryActions'])
        ->name('dashboard.todays-actions');

    // Clients avec plus de retard
    Route::get('/top-overdue-clients', [LocalSyncController::class, 'getTopOverdueClients'])
        ->name('dashboard.top-overdue-clients');

});