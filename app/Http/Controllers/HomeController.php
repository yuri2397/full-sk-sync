<?php

namespace App\Http\Controllers;

use App\Models\InvoiceSyncBuffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class HomeController extends Controller
{
    /**
     * URL de l'application principale (à configurer)
     */
    private $mainAppUrl = 'https://app-skd-cloud-api-prod.digita.sn'; // Mis à jour

    /**
     * Afficher le dashboard avec toutes les données compilées
     */
    public function index()
    {
        try {
            $dashboardData = $this->compileDashboardData();
            return view('welcome', compact('dashboardData'));
        } catch (Exception $e) {
            Log::error('Erreur compilation dashboard: ' . $e->getMessage());
            $dashboardData = $this->getDefaultDashboardData();
            return view('welcome', compact('dashboardData'));
        }
    }

    /**
     * Compiler toutes les données du dashboard ENRICHIES
     */
    private function compileDashboardData()
    {
        // 1. Statistiques enrichies de la table tampon
        $bufferStats = $this->getEnrichedBufferStats();

        // 2. Statistiques de la comptabilité
        $comptabilityStats = $this->getComptabilityStats();

        // 3. Factures urgentes avec nouvelles priorités
        $urgentInvoices = $this->getUrgentInvoices();

        // 4. Statistiques de recouvrement
        $recoveryStats = $this->getRecoveryStats();

        // 5. Activité récente enrichie
        $recentActivity = $this->getRecentActivity();

        // 6. Progression de synchronisation
        $syncProgress = $this->calculateSyncProgress($bufferStats, $comptabilityStats);

        // 7. Métriques de performance
        $performanceMetrics = $this->getPerformanceMetrics();

        return [
            'buffer_stats' => $bufferStats,
            'comptability_stats' => $comptabilityStats,
            'urgent_invoices' => $urgentInvoices,
            'recovery_stats' => $recoveryStats,
            'recent_activity' => $recentActivity,
            'sync_progress' => $syncProgress,
            'performance_metrics' => $performanceMetrics,
            'last_updated' => now()->toIso8601String()
        ];
    }

    /**
     * Statistiques enrichies de la table tampon
     */
    private function getEnrichedBufferStats()
    {
        $stats = InvoiceSyncBuffer::selectRaw("
            COUNT(*) as total_invoices,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced_invoices,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed_invoices,
            
            -- Nouveaux montants basés sur balance_due
            SUM(CASE WHEN sync_status = 'pending' THEN balance_due ELSE 0 END) as pending_amount,
            SUM(CASE WHEN sync_status = 'synced' THEN balance_due ELSE 0 END) as synced_amount,
            SUM(balance_due) as total_balance_due,
            SUM(amount_paid) as total_amount_paid,
            
            -- Nouvelles priorités
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'TRES_URGENT' THEN 1 ELSE 0 END) as tres_urgent_pending,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'URGENT' THEN 1 ELSE 0 END) as urgent_pending,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'NORMAL' THEN 1 ELSE 0 END) as normal_pending,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'SURVEILLANCE' THEN 1 ELSE 0 END) as surveillance_pending,
            
            -- Catégories de retard
            SUM(CASE WHEN sync_status = 'pending' AND overdue_category = 'RETARD_PLUS_90' THEN 1 ELSE 0 END) as retard_90_plus,
            SUM(CASE WHEN sync_status = 'pending' AND overdue_category = 'RETARD_61_90' THEN 1 ELSE 0 END) as retard_61_90,
            SUM(CASE WHEN sync_status = 'pending' AND overdue_category = 'RETARD_31_60' THEN 1 ELSE 0 END) as retard_31_60,
            SUM(CASE WHEN sync_status = 'pending' AND overdue_category = 'RETARD_1_30' THEN 1 ELSE 0 END) as retard_1_30,
            SUM(CASE WHEN sync_status = 'pending' AND overdue_category = 'NON_ECHU' THEN 1 ELSE 0 END) as non_echu,
            
            -- Informations Sage
            SUM(CASE WHEN journal_type = 'VTEM' THEN 1 ELSE 0 END) as vtem_count,
            SUM(CASE WHEN journal_type = 'RANO' THEN 1 ELSE 0 END) as rano_count,
            SUM(CASE WHEN is_lettred = 1 THEN 1 ELSE 0 END) as lettred_count,
            
            MAX(CASE WHEN sync_status = 'synced' THEN synced_at END) as last_sync,
            MAX(created_at) as last_dump,
            MAX(last_sage_sync) as last_sage_sync
        ")->first();

        return [
            'total_invoices' => (int) $stats->total_invoices,
            'pending_invoices' => (int) $stats->pending_invoices,
            'synced_invoices' => (int) $stats->synced_invoices,
            'failed_invoices' => (int) $stats->failed_invoices,

            'pending_amount' => (float) $stats->pending_amount,
            'synced_amount' => (float) $stats->synced_amount,
            'total_balance_due' => (float) $stats->total_balance_due,
            'total_amount_paid' => (float) $stats->total_amount_paid,

            'tres_urgent_pending' => (int) $stats->tres_urgent_pending,
            'urgent_pending' => (int) $stats->urgent_pending,
            'normal_pending' => (int) $stats->normal_pending,
            'surveillance_pending' => (int) $stats->surveillance_pending,

            'retard_90_plus' => (int) $stats->retard_90_plus,
            'retard_61_90' => (int) $stats->retard_61_90,
            'retard_31_60' => (int) $stats->retard_31_60,
            'retard_1_30' => (int) $stats->retard_1_30,
            'non_echu' => (int) $stats->non_echu,

            'vtem_count' => (int) $stats->vtem_count,
            'rano_count' => (int) $stats->rano_count,
            'lettred_count' => (int) $stats->lettred_count,

            'last_sync' => $stats->last_sync,
            'last_dump' => $stats->last_dump,
            'last_sage_sync' => $stats->last_sage_sync,
        ];
    }

    /**
     * Statistiques de recouvrement enrichies
     */
    private function getRecoveryStats()
    {
        $stats = InvoiceSyncBuffer::selectRaw("
            SUM(CASE WHEN recovery_status = 'NEW' THEN 1 ELSE 0 END) as new_count,
            SUM(CASE WHEN recovery_status = 'IN_PROGRESS' THEN 1 ELSE 0 END) as in_progress_count,
            SUM(CASE WHEN recovery_status = 'CONTACT_MADE' THEN 1 ELSE 0 END) as contact_made_count,
            SUM(CASE WHEN recovery_status = 'PROMISE_TO_PAY' THEN 1 ELSE 0 END) as promise_count,
            SUM(CASE WHEN recovery_status = 'LITIGATION' THEN 1 ELSE 0 END) as litigation_count,
            
            SUM(CASE WHEN recovery_status = 'NEW' THEN balance_due ELSE 0 END) as new_amount,
            SUM(CASE WHEN recovery_status = 'IN_PROGRESS' THEN balance_due ELSE 0 END) as in_progress_amount,
            
            COUNT(CASE WHEN next_action_date = CURDATE() THEN 1 END) as actions_today,
            COUNT(CASE WHEN next_action_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 END) as actions_this_week,
            
            COUNT(DISTINCT client_code) as unique_clients_pending,
            AVG(days_overdue) as avg_days_overdue,
            MAX(days_overdue) as max_days_overdue
        ")->first();

        return [
            'new_count' => (int) $stats->new_count,
            'in_progress_count' => (int) $stats->in_progress_count,
            'contact_made_count' => (int) $stats->contact_made_count,
            'promise_count' => (int) $stats->promise_count,
            'litigation_count' => (int) $stats->litigation_count,

            'new_amount' => (float) $stats->new_amount,
            'in_progress_amount' => (float) $stats->in_progress_amount,

            'actions_today' => (int) $stats->actions_today,
            'actions_this_week' => (int) $stats->actions_this_week,

            'unique_clients_pending' => (int) $stats->unique_clients_pending,
            'avg_days_overdue' => round((float) $stats->avg_days_overdue, 1),
            'max_days_overdue' => (int) $stats->max_days_overdue,
        ];
    }

    /**
     * Statistiques de la comptabilité mises à jour
     */
    private function getComptabilityStats()
    {
        try {
            $stats = DB::select("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(e.EC_Montant) as total_amount,
                    SUM(e.EC_Montant - ISNULL(e.EC_MontantRegle, 0)) as total_balance_due,
                    COUNT(DISTINCT e.CT_Num) as unique_clients,
                    
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 THEN 1 END) as overdue_90_plus,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 61 AND 90 THEN 1 END) as overdue_61_90,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 31 AND 60 THEN 1 END) as overdue_31_60,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 1 AND 30 THEN 1 END) as overdue_1_30,
                    
                    COUNT(CASE WHEN e.JO_Num = 'VTEM' THEN 1 END) as vtem_total,
                    COUNT(CASE WHEN e.JO_Num = 'RANO' THEN 1 END) as rano_total,
                    
                    SUM(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 AND e.EC_Montant > 500000 THEN e.EC_Montant ELSE 0 END) as tres_urgent_amount

                FROM F_ECRITUREC e
                LEFT JOIN F_COMPTET c ON e.CT_Num = c.CT_Num

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.JO_Num IN ('VTEM', 'RANO')
                    AND e.EC_Sens = 0
                    AND (e.EC_Lettrage = '' OR e.EC_Lettrage IS NULL)
                    AND e.EC_Montant > 0
                    AND e.EC_Echeance >= '2020-01-01'
            ");

            $result = $stats[0];

            return [
                'total_invoices' => (int) $result->total_invoices,
                'total_amount' => (float) $result->total_amount,
                'total_balance_due' => (float) $result->total_balance_due,
                'unique_clients' => (int) $result->unique_clients,
                'overdue_90_plus' => (int) $result->overdue_90_plus,
                'overdue_61_90' => (int) $result->overdue_61_90,
                'overdue_31_60' => (int) $result->overdue_31_60,
                'overdue_1_30' => (int) $result->overdue_1_30,
                'vtem_total' => (int) $result->vtem_total,
                'rano_total' => (int) $result->rano_total,
                'tres_urgent_amount' => (float) $result->tres_urgent_amount,
            ];
        } catch (Exception $e) {
            Log::error('Erreur stats comptabilité: ' . $e->getMessage());
            return [
                'total_invoices' => 0,
                'total_amount' => 0,
                'total_balance_due' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_61_90' => 0,
                'overdue_31_60' => 0,
                'overdue_1_30' => 0,
                'vtem_total' => 0,
                'rano_total' => 0,
                'tres_urgent_amount' => 0,
            ];
        }
    }

    /**
     * Factures urgentes avec nouvelles priorités
     */
    private function getUrgentInvoices()
    {
        return InvoiceSyncBuffer::where('sync_status', 'pending')
            ->whereIn('priority', ['TRES_URGENT', 'URGENT'])
            ->orderByRaw("
                CASE 
                    WHEN priority = 'TRES_URGENT' THEN 1
                    WHEN priority = 'URGENT' THEN 2
                    ELSE 3
                END
            ")
            ->orderBy('due_date')
            ->limit(15)
            ->get([
                'id',
                'invoice_number',
                'client_name',
                'client_code',
                'balance_due',
                'due_date',
                'priority',
                'days_overdue',
                'overdue_category',
                'recovery_status',
                'next_action_date'
            ]);
    }

    /**
     * Activité récente enrichie
     */
    private function getRecentActivity()
    {
        return InvoiceSyncBuffer::where('sync_status', 'synced')
            ->orderBy('synced_at', 'desc')
            ->limit(8)
            ->get([
                'invoice_number',
                'client_name',
                'balance_due',
                'synced_at',
                'sync_notes',
                'priority',
                'sync_batch_id'
            ]);
    }

    /**
     * Métriques de performance
     */
    private function getPerformanceMetrics()
    {
        $today = now()->startOfDay();
        $yesterday = now()->subDay()->startOfDay();
        $weekAgo = now()->subWeek();

        return [
            'synced_today' => InvoiceSyncBuffer::where('synced_at', '>=', $today)->count(),
            'synced_yesterday' => InvoiceSyncBuffer::whereBetween('synced_at', [$yesterday, $today])->count(),
            'synced_this_week' => InvoiceSyncBuffer::where('synced_at', '>=', $weekAgo)->count(),
            'failed_today' => InvoiceSyncBuffer::where('last_sync_attempt', '>=', $today)
                ->where('sync_status', 'failed')->count(),
            'avg_sync_attempts' => InvoiceSyncBuffer::where('sync_status', 'synced')->avg('sync_attempts'),
            'pending_actions_today' => InvoiceSyncBuffer::whereDate('next_action_date', $today)->count(),
        ];
    }

    /**
     * Progression de synchronisation enrichie
     */
    private function calculateSyncProgress($bufferStats, $comptabilityStats)
    {
        $dumpedPercentage = $comptabilityStats['total_invoices'] > 0
            ? round(($bufferStats['total_invoices'] / $comptabilityStats['total_invoices']) * 100, 2)
            : 0;

        $syncedPercentage = $bufferStats['total_invoices'] > 0
            ? round(($bufferStats['synced_invoices'] / $bufferStats['total_invoices']) * 100, 2)
            : 0;

        $balanceProgress = $comptabilityStats['total_balance_due'] > 0
            ? round(($bufferStats['synced_amount'] / $comptabilityStats['total_balance_due']) * 100, 2)
            : 0;

        return [
            'dumped_percentage' => $dumpedPercentage,
            'synced_percentage' => $syncedPercentage,
            'balance_synced_percentage' => $balanceProgress,
            'remaining_to_dump' => max(0, $comptabilityStats['total_invoices'] - $bufferStats['total_invoices']),
            'remaining_to_sync' => $bufferStats['pending_invoices'],
            'efficiency_score' => $this->calculateEfficiencyScore($bufferStats),
        ];
    }

    /**
     * Score d'efficacité de synchronisation
     */
    private function calculateEfficiencyScore($bufferStats)
    {
        if ($bufferStats['total_invoices'] == 0) return 0;

        $syncedRatio = $bufferStats['synced_invoices'] / $bufferStats['total_invoices'];
        $failedRatio = $bufferStats['failed_invoices'] / $bufferStats['total_invoices'];

        return round((($syncedRatio * 100) - ($failedRatio * 50)), 1);
    }

    /**
     * API: Statistiques enrichies en temps réel
     */
    public function getStats()
    {
        try {
            $dashboardData = $this->compileDashboardData();

            return response()->json([
                'success' => true,
                'stats' => [
                    'buffer' => $dashboardData['buffer_stats'],
                    'comptability' => $dashboardData['comptability_stats'],
                    'recovery' => $dashboardData['recovery_stats'],
                    'sync_progress' => $dashboardData['sync_progress'],
                    'performance' => $dashboardData['performance_metrics'],
                    'urgent_count' => $dashboardData['urgent_invoices']->count(),
                    'last_updated' => $dashboardData['last_updated']
                ]
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
     * API: Envoyer les factures vers l'application principale (AMÉLIORÉ)
     */
    public function pushUnsyncedInvoices(Request $request)
    {
        try {
            $limit = min((int)$request->input('limit', 1000), 2000);
            $priority = $request->input('priority');
            $forceSync = $request->boolean('force_sync', false);

            // Récupérer les factures avec les nouveaux champs
            $query = InvoiceSyncBuffer::where('sync_status', 'pending');

            if ($priority) {
                $query->where('priority', $priority);
            }

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
                ->limit($limit)
                ->get();

            if ($invoices->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'Aucune facture à synchroniser',
                    'pushed_count' => 0
                ]);
            }

            // Payload enrichi avec nouveaux champs
            $payload = [
                'invoices' => $invoices->map(function ($invoice) {
                    return [
                        'local_id' => $invoice->id,
                        'invoice_number' => $invoice->invoice_number,
                        'invoice_reference' => $invoice->invoice_reference,
                        'invoice_date' => $invoice->invoice_date,
                        'due_date' => $invoice->due_date,
                        'amount' => $invoice->amount,
                        'invoice_total' => $invoice->invoice_total,
                        'amount_paid' => $invoice->amount_paid,
                        'balance_due' => $invoice->balance_due,
                        'currency' => 'XOF',

                        'overdue_category' => $invoice->overdue_category,
                        'days_overdue' => $invoice->days_overdue,
                        'priority' => $invoice->priority,

                        'recovery_status' => $invoice->recovery_status,
                        'last_contact_date' => $invoice->last_contact_date,
                        'next_action_date' => $invoice->next_action_date,

                        'journal_type' => $invoice->journal_type,
                        'sage_lettrage_code' => $invoice->sage_lettrage_code,
                        'is_lettred' => $invoice->is_lettred,

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
                        'source_entry_id' => $invoice->source_entry_id,
                        'sage_guid' => $invoice->sage_guid,
                        'document_guid' => $invoice->document_guid,
                        'sync_batch_id' => $invoice->sync_batch_id,
                        'created_at' => $invoice->created_at,
                        'updated_at' => $invoice->updated_at
                    ];
                }),
                'metadata' => [
                    'total_count' => $invoices->count(),
                    'timestamp' => now()->toIso8601String(),
                    'source' => 'laravel-sync-buffer-v2',
                    'version' => '2.0',
                    'priority_filter' => $priority,
                    'force_sync' => $forceSync,
                ]
            ];

            // Envoyer vers l'application principale
            $response = Http::timeout(120)
                ->retry(3, 2000)
                ->withHeaders([
                    'X-API-Key' => 'sk-digitanalh2HRpxrDVJ6bkk5Gy0iHehnf6i9Czhtiv7rG82REOENWLzK42Sv6qGW04cLz4j3hhyf44yJ3d8jShdudGl9NzvuGUfQHPkiHg1YtUL9dEWsbZ55yrJYY',
                    'X-Source' => 'sage-sync-buffer',
                ])
                ->post($this->mainAppUrl . '/api/sage-sync/receive-invoices', $payload);

            if ($response->successful()) {
                $responseData = $response->json();
                $batchId = 'batch_' . now()->format('YmdHis') . '_' . substr(md5(json_encode($payload)), 0, 8);

                // Marquer comme synchronisées avec batch tracking
                $invoiceIds = $invoices->pluck('id')->toArray();
                $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                    ->update([
                        'sync_status' => 'synced',
                        'synced_at' => now(),
                        'sync_notes' => 'Envoyé vers application principale v2: ' . ($responseData['message'] ?? 'OK'),
                        'sync_batch_id' => $batchId,
                        'sync_attempts' => DB::raw('sync_attempts + 1'),
                        'last_sync_attempt' => now(),
                    ]);

                return response()->json([
                    'success' => true,
                    'message' => "Synchronisation réussie: {$updatedCount} factures envoyées",
                    'pushed_count' => $updatedCount,
                    'batch_id' => $batchId,
                    'main_app_response' => $responseData
                ]);
            } else {
                // Marquer comme échouées
                $invoiceIds = $invoices->pluck('id')->toArray();
                InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                    ->update([
                        'sync_status' => 'failed',
                        'sync_attempts' => DB::raw('sync_attempts + 1'),
                        'last_sync_attempt' => now(),
                        'last_error_message' => 'HTTP ' . $response->status() . ': ' . $response->body()
                    ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'envoi vers l\'application principale',
                    'error' => 'HTTP ' . $response->status(),
                    'details' => $response->body()
                ], 500);
            }
        } catch (Exception $e) {
            Log::error('Erreur push factures: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'envoi des factures',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Données par défaut enrichies
     */
    private function getDefaultDashboardData()
    {
        return [
            'buffer_stats' => [
                'total_invoices' => 0,
                'pending_invoices' => 0,
                'synced_invoices' => 0,
                'failed_invoices' => 0,
                'pending_amount' => 0,
                'synced_amount' => 0,
                'total_balance_due' => 0,
                'total_amount_paid' => 0,
                'tres_urgent_pending' => 0,
                'urgent_pending' => 0,
                'normal_pending' => 0,
                'surveillance_pending' => 0,
                'retard_90_plus' => 0,
                'retard_61_90' => 0,
                'retard_31_60' => 0,
                'retard_1_30' => 0,
                'non_echu' => 0,
                'vtem_count' => 0,
                'rano_count' => 0,
                'lettred_count' => 0,
                'last_sync' => null,
                'last_dump' => null,
                'last_sage_sync' => null,
            ],
            'comptability_stats' => [
                'total_invoices' => 0,
                'total_amount' => 0,
                'total_balance_due' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_61_90' => 0,
                'overdue_31_60' => 0,
                'overdue_1_30' => 0,
                'vtem_total' => 0,
                'rano_total' => 0,
                'tres_urgent_amount' => 0,
            ],
            'recovery_stats' => [
                'new_count' => 0,
                'in_progress_count' => 0,
                'contact_made_count' => 0,
                'promise_count' => 0,
                'litigation_count' => 0,
                'new_amount' => 0,
                'in_progress_amount' => 0,
                'actions_today' => 0,
                'actions_this_week' => 0,
                'unique_clients_pending' => 0,
                'avg_days_overdue' => 0,
                'max_days_overdue' => 0,
            ],
            'urgent_invoices' => collect(),
            'recent_activity' => collect(),
            'sync_progress' => [
                'dumped_percentage' => 0,
                'synced_percentage' => 0,
                'balance_synced_percentage' => 0,
                'remaining_to_dump' => 0,
                'remaining_to_sync' => 0,
                'efficiency_score' => 0
            ],
            'performance_metrics' => [
                'synced_today' => 0,
                'synced_yesterday' => 0,
                'synced_this_week' => 0,
                'failed_today' => 0,
                'avg_sync_attempts' => 0,
                'pending_actions_today' => 0,
            ],
            'last_updated' => now()->toIso8601String()
        ];
    }
}
