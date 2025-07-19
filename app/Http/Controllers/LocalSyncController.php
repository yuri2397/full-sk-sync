<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSyncBuffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;

class LocalSyncController extends Controller
{
    /**
     * Dump les factures depuis la base comptable vers la table tampon
     * Compatible avec la migration améliorée
     */
    public function dumpInvoices(Request $request)
    {
        try {
            $limit = (int)$request->input('limit', 1000);
            $fromDate = $request->input('from_date', '2020-01-01');
            $forceRefresh = $request->boolean('force_refresh', false);
            $batchId = 'batch_' . now()->format('Y_m_d_H_i_s') . '_' . Str::random(8);

            // REQUÊTE CORRIGÉE avec tous les nouveaux champs
            $sql = "
                SELECT 
                    -- === INFORMATIONS CLIENT ===
                    e.CT_Num as client_code,
                    ISNULL(c.CT_Intitule, 'Client non trouvé') as client_name,
                    ISNULL(c.CT_Telephone, '') as client_phone,
                    ISNULL(c.CT_EMail, '') as client_email,
                    ISNULL(c.CT_Adresse, '') as client_address,
                    ISNULL(c.CT_Ville, '') as client_city,
                    ISNULL(c.CT_Contact, '') as commercial_contact,
                    ISNULL(c.CT_Encours, 0) as client_credit_limit,
                    CAST(ISNULL(c.N_Condition, 0) as VARCHAR) as client_payment_terms,
                    
                    -- === INFORMATIONS FACTURE ===
                    e.EC_RefPiece as invoice_number,
                    e.EC_RefPiece as invoice_reference,
                    ISNULL(f.DO_Date, e.EC_Date) as invoice_date,
                    e.EC_Echeance as due_date,
                    
                    -- === MONTANTS ENRICHIS ===
                    e.EC_Montant as amount,
                    ISNULL(f.DO_TotalTTC, e.EC_Montant) as invoice_total,
                    ISNULL(e.EC_MontantRegle, 0) as amount_paid,
                    (e.EC_Montant - ISNULL(e.EC_MontantRegle, 0)) as balance_due,
                    
                    -- === INFORMATIONS SAGE ENRICHIES ===
                    e.JO_Num as journal_type,
                    ISNULL(e.EC_Lettrage, '') as sage_lettrage_code,
                    CASE 
                        WHEN e.EC_Lettrage = '' OR e.EC_Lettrage IS NULL THEN 0 
                        ELSE 1 
                    END as is_lettred,
                    
                    -- === CALCULS DE RETARD AMÉLIORÉS ===
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) <= 0 THEN 'NON_ECHU'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 1 AND 30 THEN 'RETARD_1_30'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 31 AND 60 THEN 'RETARD_31_60'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 61 AND 90 THEN 'RETARD_61_90'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 THEN 'RETARD_PLUS_90'
                    END as overdue_category,
                    
                    DATEDIFF(DAY, e.EC_Echeance, GETDATE()) as days_overdue,
                    
                    -- === PRIORITÉ AMÉLIORÉE ===
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant > 500000 THEN 'TRES_URGENT'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 60 AND e.EC_Montant > 200000 THEN 'URGENT'
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 30 THEN 'NORMAL'
                        ELSE 'SURVEILLANCE'
                    END as priority,
                    
                    -- === MÉTADONNÉES ENRICHIES ===
                    e.EC_Intitule as description,
                    CAST(e.cbMarq as VARCHAR) as source_entry_id,
                    CAST(e.EC_FactureGUID as VARCHAR) as sage_guid,
                    ISNULL(CAST(f.DO_GUID as VARCHAR), '') as document_guid,
                    e.cbCreation as source_created_at,
                    e.cbModification as source_updated_at,
                    GETDATE() as last_sage_sync

                FROM F_ECRITUREC e
                LEFT JOIN F_DOCENTETE f ON e.EC_RefPiece = f.DO_Piece AND f.DO_Type = 7 AND f.DO_Tiers = e.CT_Num
                LEFT JOIN F_COMPTET c ON e.CT_Num = c.CT_Num

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.JO_Num IN ('VTEM', 'RANO')
                    AND e.EC_Sens = 0
                    AND (e.EC_Lettrage = '' OR e.EC_Lettrage IS NULL)
                    AND e.EC_RefPiece IS NOT NULL
                    AND e.EC_Montant > 0
                    AND e.EC_Echeance >= ?
                    " . ($forceRefresh ? "" : "
                    AND NOT EXISTS (
                        SELECT 1 FROM invoice_sync_buffer isb 
                        WHERE isb.invoice_number = e.EC_RefPiece
                        AND isb.client_code = e.CT_Num
                    )") . "

                ORDER BY 
                    CASE 
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant > 500000 THEN 1
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 60 AND e.EC_Montant > 200000 THEN 2
                        WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 30 THEN 3
                        ELSE 4
                    END,
                    e.EC_Echeance ASC,
                    e.EC_Montant DESC

                OFFSET 0 ROWS FETCH NEXT ? ROWS ONLY
            ";

            $invoices = DB::select($sql, [$fromDate, $limit]);

            $addedCount = 0;
            $updatedCount = 0;
            $errors = [];
            $skippedCount = 0;

            foreach ($invoices as $invoice) {
                try {
                    // Données enrichies pour la nouvelle migration
                    $data = [
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_reference' => $invoice->invoice_reference,
                        'client_code' => $invoice->client_code,
                        'client_name' => $invoice->client_name,
                        'amount' => (float) $invoice->amount,
                        'invoice_total' => (float) $invoice->invoice_total,
                        'amount_paid' => (float) $invoice->amount_paid,
                        'balance_due' => (float) $invoice->balance_due,
                        'invoice_date' => $invoice->invoice_date ? date('Y-m-d', strtotime($invoice->invoice_date)) : null,
                        'due_date' => $invoice->due_date ? date('Y-m-d', strtotime($invoice->due_date)) : null,

                        // Nouveaux champs Sage
                        'journal_type' => $invoice->journal_type,
                        'sage_lettrage_code' => $invoice->sage_lettrage_code,
                        'is_lettred' => (bool) $invoice->is_lettred,

                        // Informations client enrichies
                        'client_phone' => $invoice->client_phone,
                        'client_email' => $invoice->client_email,
                        'client_address' => $invoice->client_address,
                        'client_city' => $invoice->client_city,
                        'commercial_contact' => $invoice->commercial_contact,
                        'client_credit_limit' => (float) $invoice->client_credit_limit,
                        'client_payment_terms' => $invoice->client_payment_terms,

                        // Calculs de recouvrement
                        'overdue_category' => $invoice->overdue_category,
                        'days_overdue' => (int) $invoice->days_overdue,
                        'priority' => $invoice->priority,

                        // Statut recouvrement par défaut
                        'recovery_status' => 'NEW',

                        // Métadonnées
                        'source_entry_id' => $invoice->source_entry_id,
                        'sage_guid' => $invoice->sage_guid,
                        'document_guid' => $invoice->document_guid,
                        'description' => $invoice->description,
                        'source_created_at' => $invoice->source_created_at ? date('Y-m-d H:i:s', strtotime($invoice->source_created_at)) : null,
                        'source_updated_at' => $invoice->source_updated_at ? date('Y-m-d H:i:s', strtotime($invoice->source_updated_at)) : null,
                        'last_sage_sync' => $invoice->last_sage_sync ? date('Y-m-d H:i:s', strtotime($invoice->last_sage_sync)) : null,

                        // Synchronisation
                        'sync_status' => 'pending',
                        'sync_batch_id' => $batchId,
                        'sync_attempts' => 0,
                    ];

                    // UPDATE OR INSERT avec gestion des conflits
                    $existing = InvoiceSyncBuffer::where('invoice_number', $invoice->invoice_number)
                        ->where('client_code', $invoice->client_code)
                        ->first();

                    if ($existing) {
                        if ($forceRefresh) {
                            // Mise à jour complète en mode force refresh
                            $existing->update($data);
                            $updatedCount++;
                        } else {
                            // Juste mettre à jour les champs critiques si pas de force refresh
                            $existing->update([
                                'balance_due' => $data['balance_due'],
                                'amount_paid' => $data['amount_paid'],
                                'days_overdue' => $data['days_overdue'],
                                'overdue_category' => $data['overdue_category'],
                                'priority' => $data['priority'],
                                'last_sage_sync' => $data['last_sage_sync'],
                            ]);
                            $skippedCount++;
                        }
                    } else {
                        // Création nouvelle
                        InvoiceSyncBuffer::create($data);
                        $addedCount++;
                    }
                } catch (Exception $e) {
                    $errors[] = "Erreur facture {$invoice->invoice_number}: " . $e->getMessage();
                    Log::error("Erreur dump facture {$invoice->invoice_number}", [
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            return response()->json([
                'success' => true,
                'message' => "Dump terminé avec succès",
                'results' => [
                    'added_count' => $addedCount,
                    'updated_count' => $updatedCount,
                    'skipped_count' => $skippedCount,
                    'total_found' => count($invoices),
                    'errors_count' => count($errors),
                ],
                'batch_id' => $batchId,
                'from_date' => $fromDate,
                'force_refresh' => $forceRefresh,
                'errors' => $errors
            ]);
        } catch (Exception $e) {
            Log::error('Erreur dump factures: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du dump des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Récupérer les factures non synchronisées avec filtres avancés
     */
    public function getUnsyncedInvoices(Request $request)
    {
        try {
            $limit = min((int)$request->input('limit', 100), 1000); // Limite max 1000
            $offset = (int)$request->input('offset', 0);
            $priority = $request->input('priority');
            $clientCode = $request->input('client_code');
            $overdueCategory = $request->input('overdue_category');
            $recoveryStatus = $request->input('recovery_status', 'NEW');
            $minAmount = $request->input('min_amount');
            $maxAmount = $request->input('max_amount');

            $query = InvoiceSyncBuffer::where('sync_status', 'pending');

            // Filtres avancés
            if ($priority) {
                $query->where('priority', $priority);
            }

            if ($clientCode) {
                $query->where('client_code', 'like', "%{$clientCode}%");
            }

            if ($overdueCategory) {
                $query->where('overdue_category', $overdueCategory);
            }

            if ($recoveryStatus) {
                $query->where('recovery_status', $recoveryStatus);
            }

            if ($minAmount) {
                $query->where('balance_due', '>=', $minAmount);
            }

            if ($maxAmount) {
                $query->where('balance_due', '<=', $maxAmount);
            }

            // Tri optimisé par priorité
            $invoices = $query->orderByRaw("
                    CASE 
                        WHEN priority = 'TRES_URGENT' THEN 1
                        WHEN priority = 'URGENT' THEN 2
                        WHEN priority = 'NORMAL' THEN 3
                        WHEN priority = 'SURVEILLANCE' THEN 4
                        ELSE 5
                    END
                ")
                ->orderBy('due_date', 'asc')
                ->orderBy('balance_due', 'desc')
                ->offset($offset)
                ->limit($limit)
                ->get();

            // Formatage enrichi des données
            $formattedInvoices = $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'invoice_number' => $invoice->invoice_number,
                    'invoice_reference' => $invoice->invoice_reference,
                    'invoice_date' => $invoice->invoice_date,
                    'due_date' => $invoice->due_date,
                    'amount' => $invoice->amount,
                    'invoice_total' => $invoice->invoice_total,
                    'amount_paid' => $invoice->amount_paid,
                    'balance_due' => $invoice->balance_due,
                    'currency' => 'XOF',

                    // Informations de retard enrichies
                    'overdue_category' => $invoice->overdue_category,
                    'days_overdue' => $invoice->days_overdue,
                    'priority' => $invoice->priority,

                    // Statut recouvrement
                    'recovery_status' => $invoice->recovery_status,
                    'last_contact_date' => $invoice->last_contact_date,
                    'next_action_date' => $invoice->next_action_date,

                    // Informations Sage
                    'journal_type' => $invoice->journal_type,
                    'sage_lettrage_code' => $invoice->sage_lettrage_code,
                    'is_lettred' => $invoice->is_lettred,

                    // Client enrichi
                    'client' => [
                        'code' => $invoice->client_code,
                        'name' => $invoice->client_name,
                        'phone' => $invoice->client_phone,
                        'email' => $invoice->client_email,
                        'address' => $invoice->client_address,
                        'city' => $invoice->client_city,
                        'commercial_contact' => $invoice->commercial_contact,
                        'credit_limit' => $invoice->client_credit_limit,
                        'payment_terms' => $invoice->client_payment_terms,
                    ],

                    'description' => $invoice->description,
                    'sync_batch_id' => $invoice->sync_batch_id,
                    'last_sage_sync' => $invoice->last_sage_sync,
                    'created_at' => $invoice->created_at,
                    'updated_at' => $invoice->updated_at
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $formattedInvoices,
                'count' => $formattedInvoices->count(),
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'has_more' => $formattedInvoices->count() === $limit
                ],
                'filters_applied' => array_filter([
                    'priority' => $priority,
                    'client_code' => $clientCode,
                    'overdue_category' => $overdueCategory,
                    'recovery_status' => $recoveryStatus,
                    'min_amount' => $minAmount,
                    'max_amount' => $maxAmount,
                ])
            ]);
        } catch (Exception $e) {
            Log::error('Erreur récupération factures non sync: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des factures non synchronisées',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer des factures comme synchronisées avec tracking amélioré
     */
    public function markAsSynced(Request $request)
    {
        try {
            $invoiceIds = $request->input('invoice_ids', []);
            $notes = $request->input('notes', 'Synchronisé automatiquement');
            $syncBatchId = $request->input('sync_batch_id');

            if (empty($invoiceIds)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aucune facture spécifiée'
                ], 400);
            }

            $updateData = [
                'sync_status' => 'synced',
                'synced_at' => now(),
                'sync_notes' => $notes,
                'sync_attempts' => DB::raw('sync_attempts + 1'),
                'last_sync_attempt' => now(),
            ];

            if ($syncBatchId) {
                $updateData['sync_batch_id'] = $syncBatchId;
            }

            $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                ->where('sync_status', 'pending')
                ->update($updateData);

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} facture(s) marquée(s) comme synchronisée(s)",
                'updated_count' => $updatedCount,
                'sync_batch_id' => $syncBatchId
            ]);
        } catch (Exception $e) {
            Log::error('Erreur marquage synchronisé: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Marquer des tentatives de synchronisation échouées
     */
    public function markAsFailed(Request $request)
    {
        try {
            $invoiceIds = $request->input('invoice_ids', []);
            $errorMessage = $request->input('error_message', 'Erreur de synchronisation');

            $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                ->update([
                    'sync_status' => 'failed',
                    'sync_attempts' => DB::raw('sync_attempts + 1'),
                    'last_sync_attempt' => now(),
                    'last_error_message' => $errorMessage,
                ]);

            return response()->json([
                'success' => true,
                'message' => "{$updatedCount} facture(s) marquée(s) comme échouée(s)",
                'updated_count' => $updatedCount
            ]);
        } catch (Exception $e) {
            Log::error('Erreur marquage échec: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du marquage des échecs',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Statistiques enrichies avec métriques de recouvrement
     */
    public function getStats()
    {
        try {
            // Stats de base
            $bufferStats = [
                'total_invoices' => InvoiceSyncBuffer::count(),
                'pending_invoices' => InvoiceSyncBuffer::where('sync_status', 'pending')->count(),
                'synced_invoices' => InvoiceSyncBuffer::where('sync_status', 'synced')->count(),
                'failed_invoices' => InvoiceSyncBuffer::where('sync_status', 'failed')->count(),

                'total_amount' => InvoiceSyncBuffer::sum('balance_due'),
                'pending_amount' => InvoiceSyncBuffer::where('sync_status', 'pending')->sum('balance_due'),
                'synced_amount' => InvoiceSyncBuffer::where('sync_status', 'synced')->sum('balance_due'),
            ];

            // Stats par priorité
            $priorityStats = InvoiceSyncBuffer::where('sync_status', 'pending')
                ->selectRaw('priority, COUNT(*) as count, SUM(balance_due) as amount')
                ->groupBy('priority')
                ->get()
                ->keyBy('priority');

            // Stats par catégorie de retard
            $overdueStats = InvoiceSyncBuffer::where('sync_status', 'pending')
                ->selectRaw('overdue_category, COUNT(*) as count, SUM(balance_due) as amount')
                ->groupBy('overdue_category')
                ->get()
                ->keyBy('overdue_category');

            // Stats par statut de recouvrement
            $recoveryStats = InvoiceSyncBuffer::selectRaw('recovery_status, COUNT(*) as count, SUM(balance_due) as amount')
                ->groupBy('recovery_status')
                ->get()
                ->keyBy('recovery_status');

            // Stats clients
            $clientStats = InvoiceSyncBuffer::where('sync_status', 'pending')
                ->selectRaw('COUNT(DISTINCT client_code) as unique_clients, 
                            AVG(balance_due) as avg_invoice_amount,
                            MAX(balance_due) as max_invoice_amount')
                ->first();

            return response()->json([
                'success' => true,
                'stats' => [
                    'buffer' => $bufferStats,
                    'priority_breakdown' => $priorityStats,
                    'overdue_breakdown' => $overdueStats,
                    'recovery_breakdown' => $recoveryStats,
                    'client_stats' => $clientStats,
                    'last_sync' => InvoiceSyncBuffer::where('sync_status', 'synced')->max('synced_at'),
                    'last_dump' => InvoiceSyncBuffer::max('created_at'),
                    'last_sage_sync' => InvoiceSyncBuffer::max('last_sage_sync'),
                ],
                'currency' => 'XOF',
                'generated_at' => now()->toIso8601String()
            ]);
        } catch (Exception $e) {
            Log::error('Erreur récupération stats: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la récupération des statistiques',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Test de connectivité amélioré
     */
    public function ping()
    {
        try {
            // Test connexion Sage
            $sageTest = DB::select('SELECT COUNT(*) as count FROM F_ECRITUREC WHERE CT_Num LIKE \'411%\'');

            // Test table tampon
            $bufferCount = InvoiceSyncBuffer::count();
            $pendingCount = InvoiceSyncBuffer::where('sync_status', 'pending')->count();

            return response()->json([
                'success' => true,
                'message' => 'Service de synchronisation opérationnel',
                'timestamp' => now()->toIso8601String(),
                'connections' => [
                    'sage_db' => 'OK',
                    'buffer_table' => 'OK'
                ],
                'counts' => [
                    'sage_invoices' => (int) $sageTest[0]->count,
                    'buffer_total' => $bufferCount,
                    'buffer_pending' => $pendingCount,
                ]
            ]);
        } catch (Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erreur de connexion',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Nettoyage des anciennes données
     */
    public function cleanup(Request $request)
    {
        try {
            $daysOld = (int)$request->input('days_old', 30);
            $status = $request->input('status', 'synced');

            $deletedCount = InvoiceSyncBuffer::where('sync_status', $status)
                ->where('created_at', '<', now()->subDays($daysOld))
                ->delete();

            return response()->json([
                'success' => true,
                'message' => "Nettoyage terminé: {$deletedCount} enregistrement(s) supprimé(s)",
                'deleted_count' => $deletedCount,
                'criteria' => [
                    'status' => $status,
                    'older_than_days' => $daysOld
                ]
            ]);
        } catch (Exception $e) {
            Log::error('Erreur nettoyage: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors du nettoyage',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
