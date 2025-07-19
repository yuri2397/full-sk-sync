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
     * URL de l'application principale
     */
    private $mainAppUrl;

    public function __construct()
    {
        $this->mainAppUrl = env('MAIN_APP_URL');
    }

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
     * Compiler toutes les données du dashboard
     */
    private function compileDashboardData()
    {
        // 1. Statistiques de la table tampon
        $bufferStats = $this->getBufferStats();

        // 2. Statistiques de la comptabilité
        $comptabilityStats = $this->getComptabilityStats();

        // 3. Factures urgentes
        $urgentInvoices = $this->getUrgentInvoices();

        // 4. Dernières activités
        $recentActivity = $this->getRecentActivity();

        // 5. Progression de synchronisation
        $syncProgress = $this->calculateSyncProgress($bufferStats, $comptabilityStats);

        return [
            'buffer_stats' => $bufferStats,
            'comptability_stats' => $comptabilityStats,
            'urgent_invoices' => $urgentInvoices,
            'recent_activity' => $recentActivity,
            'sync_progress' => $syncProgress,
            'last_updated' => now()->toIso8601String()
        ];
    }

    /**
     * Obtenir les statistiques de la table tampon (SIMPLIFIÉ)
     */
    private function getBufferStats()
    {
        $stats = InvoiceSyncBuffer::selectRaw("
            COUNT(*) as total_invoices,
            SUM(CASE WHEN sync_status = 'pending' THEN 1 ELSE 0 END) as pending_invoices,
            SUM(CASE WHEN sync_status = 'synced' THEN 1 ELSE 0 END) as synced_invoices,
            SUM(CASE WHEN sync_status = 'failed' THEN 1 ELSE 0 END) as failed_invoices,
            SUM(CASE WHEN sync_status = 'pending' THEN ISNULL(balance_due, amount) ELSE 0 END) as pending_amount,
            SUM(CASE WHEN sync_status = 'synced' THEN ISNULL(balance_due, amount) ELSE 0 END) as synced_amount,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'TRES_URGENT' THEN 1 ELSE 0 END) as tres_urgent_pending,
            SUM(CASE WHEN sync_status = 'pending' AND priority = 'URGENT' THEN 1 ELSE 0 END) as urgent_pending,
            MAX(CASE WHEN sync_status = 'synced' THEN synced_at END) as last_sync,
            MAX(created_at) as last_dump
        ")->first();

        return [
            'total_invoices' => (int) $stats->total_invoices,
            'pending_invoices' => (int) $stats->pending_invoices,
            'synced_invoices' => (int) $stats->synced_invoices,
            'failed_invoices' => (int) $stats->failed_invoices,
            'pending_amount' => (float) $stats->pending_amount,
            'synced_amount' => (float) $stats->synced_amount,
            'tres_urgent_pending' => (int) $stats->tres_urgent_pending,
            'urgent_pending' => (int) $stats->urgent_pending,
            'last_sync' => $stats->last_sync,
            'last_dump' => $stats->last_dump,
        ];
    }

    /**
     * Obtenir les statistiques de la comptabilité (SIMPLIFIÉ)
     */
    private function getComptabilityStats()
    {
        try {
            $stats = DB::select("
                SELECT 
                    COUNT(*) as total_invoices,
                    SUM(e.EC_Montant) as total_amount,
                    COUNT(DISTINCT e.CT_Num) as unique_clients,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) > 90 THEN 1 END) as overdue_90_plus,
                    COUNT(CASE WHEN DATEDIFF(DAY, e.EC_Echeance, GETDATE()) BETWEEN 31 AND 90 THEN 1 END) as overdue_31_90

                FROM F_ECRITUREC e

                WHERE 
                    e.CT_Num LIKE '411%'
                    AND e.JO_Num IN ('VTEM', 'RANO')
                    AND e.EC_Sens = 0
                    AND (e.EC_Lettrage = '' OR e.EC_Lettrage IS NULL)
                    AND e.EC_RefPiece IS NOT NULL
                    AND e.EC_Montant > 0
                    AND e.EC_Echeance >= '2020-01-01'
            ");

            $result = $stats[0];

            return [
                'total_invoices' => (int) $result->total_invoices,
                'total_amount' => (float) $result->total_amount,
                'unique_clients' => (int) $result->unique_clients,
                'overdue_90_plus' => (int) $result->overdue_90_plus,
                'overdue_31_90' => (int) $result->overdue_31_90,
            ];
        } catch (Exception $e) {
            Log::error('Erreur stats comptabilité: ' . $e->getMessage());
            return [
                'total_invoices' => 0,
                'total_amount' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_31_90' => 0,
            ];
        }
    }

    /**
     * Obtenir les factures urgentes (SIMPLIFIÉ)
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
            ->limit(10)
            ->get(['id', 'invoice_number', 'client_name', 'balance_due', 'amount', 'due_date', 'priority', 'days_overdue']);
    }

    /**
     * Obtenir l'activité récente (SIMPLIFIÉ)
     */
    private function getRecentActivity()
    {
        return InvoiceSyncBuffer::where('sync_status', 'synced')
            ->orderBy('synced_at', 'desc')
            ->limit(5)
            ->get(['invoice_number', 'client_name', 'balance_due', 'amount', 'synced_at', 'sync_notes']);
    }

    /**
     * Calculer la progression de synchronisation
     */
    private function calculateSyncProgress($bufferStats, $comptabilityStats)
    {
        $dumpedPercentage = $comptabilityStats['total_invoices'] > 0
            ? round(($bufferStats['total_invoices'] / $comptabilityStats['total_invoices']) * 100, 2)
            : 0;

        $syncedPercentage = $bufferStats['total_invoices'] > 0
            ? round(($bufferStats['synced_invoices'] / $bufferStats['total_invoices']) * 100, 2)
            : 0;

        return [
            'dumped_percentage' => $dumpedPercentage,
            'synced_percentage' => $syncedPercentage,
            'remaining_to_dump' => max(0, $comptabilityStats['total_invoices'] - $bufferStats['total_invoices']),
            'remaining_to_sync' => $bufferStats['pending_invoices']
        ];
    }

    /**
     * Données par défaut en cas d'erreur
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
                'tres_urgent_pending' => 0,
                'urgent_pending' => 0,
                'last_sync' => null,
                'last_dump' => null,
            ],
            'comptability_stats' => [
                'total_invoices' => 0,
                'total_amount' => 0,
                'unique_clients' => 0,
                'overdue_90_plus' => 0,
                'overdue_31_90' => 0,
            ],
            'urgent_invoices' => collect(),
            'recent_activity' => collect(),
            'sync_progress' => [
                'dumped_percentage' => 0,
                'synced_percentage' => 0,
                'remaining_to_dump' => 0,
                'remaining_to_sync' => 0
            ],
            'last_updated' => now()->toIso8601String()
        ];
    }

    public function pushUnsyncedInvoices(Request $request)
{
    try {
        $limit = min((int)$request->input('limit', 1000), 2000);
        $priority = $request->input('priority');

        // ... récupération des factures (identique) ...

        if ($response->successful()) {
            $responseData = $response->json();
            $batchId = 'batch_' . now()->format('YmdHis');

            $now = now()->format('Y-m-d H:i:s');
            $invoiceIds = $invoices->pluck('id')->toArray();

            $updatedCount = InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                ->update([
                    'sync_status' => 'synced',
                    'synced_at' => $now,
                    'sync_notes' => 'Envoyé vers application principale: ' . ($responseData['message'] ?? 'OK'),
                    'sync_batch_id' => $batchId,
                    'sync_attempts' => DB::raw('ISNULL(sync_attempts, 0) + 1'),
                    'last_sync_attempt' => $now,
                    // === PLUS DE updated_at ===
                ]);

            return response()->json([
                'success' => true,
                'message' => "Synchronisation réussie: {$updatedCount} factures envoyées",
                'pushed_count' => $updatedCount,
                'batch_id' => $batchId,
                'main_app_response' => $responseData
            ]);

        } else {
            $now = now()->format('Y-m-d H:i:s');
            $invoiceIds = $invoices->pluck('id')->toArray();

            InvoiceSyncBuffer::whereIn('id', $invoiceIds)
                ->update([
                    'sync_status' => 'failed',
                    'sync_attempts' => DB::raw('ISNULL(sync_attempts, 0) + 1'),
                    'last_sync_attempt' => $now,
                    'last_error_message' => 'HTTP ' . $response->status() . ': ' . $response->body()
                    // === PLUS DE updated_at ===
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
     * Obtenir les statistiques en temps réel (API endpoint)
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
                    'sync_progress' => $dashboardData['sync_progress'],
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
}
