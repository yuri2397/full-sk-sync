<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Synchronisation Factures v2 - {{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Styles -->
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Figtree', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
        }

        .header {
            text-align: center;
            color: white;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .header p {
            font-size: 1.1rem;
            opacity: 0.9;
        }

        .dashboard {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .stats-card {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            transition: transform 0.3s ease;
        }

        .stats-card:hover {
            transform: translateY(-5px);
        }

        .stats-card h3 {
            color: #374151;
            font-size: 1.2rem;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .stat-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }

        .stat-item:last-child {
            border-bottom: none;
        }

        .stat-label {
            color: #6b7280;
            font-weight: 500;
            font-size: 0.9rem;
        }

        .stat-value {
            font-weight: 600;
            font-size: 1rem;
        }

        .stat-value.pending { color: #f59e0b; }
        .stat-value.synced { color: #10b981; }
        .stat-value.urgent { color: #ef4444; }
        .stat-value.tres-urgent { color: #dc2626; }
        .stat-value.failed { color: #ef4444; }
        .stat-value.new { color: #3b82f6; }

        .priority-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 15px 0;
        }

        .priority-item {
            background: #f8fafc;
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .priority-item.tres-urgent { border-color: #dc2626; background: #fef2f2; }
        .priority-item.urgent { border-color: #ef4444; background: #fef2f2; }
        .priority-item.normal { border-color: #f59e0b; background: #fffbeb; }
        .priority-item.surveillance { border-color: #10b981; background: #f0fdf4; }

        .overdue-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin: 15px 0;
        }

        .overdue-item {
            background: #f8fafc;
            padding: 8px;
            border-radius: 6px;
            text-align: center;
            border: 1px solid #e2e8f0;
        }

        .urgent-invoices-table {
            background: white;
            border-radius: 16px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }

        .table th,
        .table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }

        .table th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
        }

        .table td {
            font-size: 0.9rem;
        }

        .priority-badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .priority-badge.tres-urgent { background: #fecaca; color: #991b1b; }
        .priority-badge.urgent { background: #fed7d7; color: #c53030; }
        .priority-badge.normal { background: #fef3c7; color: #92400e; }
        .priority-badge.surveillance { background: #d1fae5; color: #065f46; }

        .actions-section {
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }

        .actions-section h3 {
            color: #374151;
            font-size: 1.25rem;
            margin-bottom: 25px;
            text-align: center;
        }

        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            font-size: 0.9rem;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            min-width: 180px;
            justify-content: center;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.15);
        }

        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-success { background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; }
        .btn-warning { background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; }
        .btn-danger { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; }
        .btn-info { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }

        .loading {
            display: none;
            text-align: center;
            padding: 20px;
            color: #6b7280;
        }

        .spinner {
            border: 3px solid #f3f4f6;
            border-top: 3px solid #667eea;
            border-radius: 50%;
            width: 30px;
            height: 30px;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 20px 0;
            display: none;
        }

        .alert-success { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .alert-error { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; }
        .alert-info { background: #dbeafe; color: #1e40af; border: 1px solid #93c5fd; }

        .progress-bar {
            background: #f3f4f6;
            border-radius: 10px;
            height: 20px;
            margin: 15px 0;
            overflow: hidden;
        }

        .progress-fill {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            height: 100%;
            width: 0%;
            transition: width 0.5s ease;
            border-radius: 10px;
        }

        .efficiency-score {
            font-size: 1.5rem;
            font-weight: bold;
            text-align: center;
            padding: 15px;
            border-radius: 12px;
            margin: 15px 0;
        }

        .efficiency-score.excellent { background: #d1fae5; color: #065f46; }
        .efficiency-score.good { background: #fef3c7; color: #92400e; }
        .efficiency-score.poor { background: #fee2e2; color: #991b1b; }

        .sync-log {
            background: #f8fafc;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
            max-height: 300px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            display: none;
        }

        .log-entry {
            padding: 5px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .log-entry:last-child {
            border-bottom: none;
        }

        @media (max-width: 768px) {
            .dashboard {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
                align-items: center;
            }

            .btn {
                width: 100%;
                max-width: 300px;
            }

            .priority-grid {
                grid-template-columns: 1fr;
            }

            .overdue-stats {
                grid-template-columns: 1fr;
            }
        }

        .metric-highlight {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px;
            border-radius: 12px;
            text-align: center;
            margin: 15px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔄 Synchronisation des Factures v2.0</h1>
            <p>Tableau de bord enrichi pour la synchronisation et le recouvrement</p>
        </div>

        <!-- Alerts -->
        <div id="alert-success" class="alert alert-success"></div>
        <div id="alert-error" class="alert alert-error"></div>
        <div id="alert-info" class="alert alert-info"></div>

        <!-- Dashboard Stats -->
        <div class="dashboard">
            <!-- Stats Buffer Enrichies -->
            <div class="stats-card">
                <h3>📊 Buffer de Synchronisation</h3>
                <div class="stat-item">
                    <span class="stat-label">Total factures buffer</span>
                    <span class="stat-value" id="total-buffer">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">En attente sync</span>
                    <span class="stat-value pending" id="pending-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Synchronisées</span>
                    <span class="stat-value synced" id="synced-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Échecs</span>
                    <span class="stat-value failed" id="failed-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Solde en attente</span>
                    <span class="stat-value pending" id="pending-balance">-</span>
                </div>
                <div class="efficiency-score" id="efficiency-score">
                    Score: <span id="efficiency-value">-</span>%
                </div>
            </div>

            <!-- Priorités de Recouvrement -->
            <div class="stats-card">
                <h3>🚨 Priorités de Recouvrement</h3>
                <div class="priority-grid">
                    <div class="priority-item tres-urgent">
                        <div class="stat-label">TRÈS URGENT</div>
                        <div class="stat-value tres-urgent" id="tres-urgent-count">-</div>
                    </div>
                    <div class="priority-item urgent">
                        <div class="stat-label">URGENT</div>
                        <div class="stat-value urgent" id="urgent-count">-</div>
                    </div>
                    <div class="priority-item normal">
                        <div class="stat-label">NORMAL</div>
                        <div class="stat-value" id="normal-count">-</div>
                    </div>
                    <div class="priority-item surveillance">
                        <div class="stat-label">SURVEILLANCE</div>
                        <div class="stat-value" id="surveillance-count">-</div>
                    </div>
                </div>
            </div>

            <!-- Retards -->
            <div class="stats-card">
                <h3>⏰ Analyse des Retards</h3>
                <div class="overdue-stats">
                    <div class="overdue-item">
                        <div class="stat-label">+90j</div>
                        <div class="stat-value urgent" id="retard-90-plus">-</div>
                    </div>
                    <div class="overdue-item">
                        <div class="stat-label">61-90j</div>
                        <div class="stat-value" id="retard-61-90">-</div>
                    </div>
                    <div class="overdue-item">
                        <div class="stat-label">31-60j</div>
                        <div class="stat-value" id="retard-31-60">-</div>
                    </div>
                    <div class="overdue-item">
                        <div class="stat-label">1-30j</div>
                        <div class="stat-value" id="retard-1-30">-</div>
                    </div>
                    <div class="overdue-item">
                        <div class="stat-label">Non échu</div>
                        <div class="stat-value" id="non-echu">-</div>
                    </div>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Retard moyen</span>
                    <span class="stat-value" id="avg-overdue">-</span>
                </div>
            </div>

            <!-- Stats Sage -->
            <div class="stats-card">
                <h3>💾 Statistiques Sage</h3>
                <div class="stat-item">
                    <span class="stat-label">Total Sage</span>
                    <span class="stat-value" id="total-sage">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">VTEM (Ventes)</span>
                    <span class="stat-value" id="vtem-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">RANO (Reports)</span>
                    <span class="stat-value" id="rano-count">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Clients uniques</span>
                    <span class="stat-value" id="unique-clients">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Progression dump</span>
                    <span class="stat-value" id="dump-progress">-</span>
                </div>
            </div>

            <!-- Performance -->
            <div class="stats-card">
                <h3>📈 Métriques Performance</h3>
                <div class="stat-item">
                    <span class="stat-label">Sync aujourd'hui</span>
                    <span class="stat-value synced" id="synced-today">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Échecs aujourd'hui</span>
                    <span class="stat-value failed" id="failed-today">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Actions prévues</span>
                    <span class="stat-value new" id="actions-today">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Tentatives moy.</span>
                    <span class="stat-value" id="avg-attempts">-</span>
                </div>
            </div>

            <!-- Recouvrement -->
            <div class="stats-card">
                <h3>🎯 Statuts Recouvrement</h3>
                <div class="stat-item">
                    <span class="stat-label">Nouveaux</span>
                    <span class="stat-value new" id="recovery-new">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">En cours</span>
                    <span class="stat-value pending" id="recovery-progress">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Contact établi</span>
                    <span class="stat-value" id="recovery-contact">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Promesses paiement</span>
                    <span class="stat-value" id="recovery-promise">-</span>
                </div>
                <div class="stat-item">
                    <span class="stat-label">Actions semaine</span>
                    <span class="stat-value" id="actions-week">-</span>
                </div>
            </div>
        </div>

        <!-- Factures Urgentes -->
        <div class="urgent-invoices-table">
            <h3>🚨 Factures Urgentes (Top 15)</h3>
            <table class="table" id="urgent-invoices-table">
                <thead>
                    <tr>
                        <th>N° Facture</th>
                        <th>Client</th>
                        <th>Solde Dû</th>
                        <th>Échéance</th>
                        <th>Retard</th>
                        <th>Priorité</th>
                        <th>Statut</th>
                        <th>Prochaine Action</th>
                    </tr>
                </thead>
                <tbody id="urgent-invoices-body">
                    <tr>
                        <td colspan="8" style="text-align: center; color: #6b7280;">Chargement...</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Actions -->
        <div class="actions-section">
            <h3>🚀 Actions de Synchronisation</h3>
            <div class="action-buttons">
                <button class="btn btn-primary" onclick="dumpInvoices()">
                    📥 Dump Nouvelles Factures
                </button>
                <button class="btn btn-success" onclick="pushInvoices()">
                    🚀 Sync vers App Principale
                </button>
                <button class="btn btn-warning" onclick="pushUrgentOnly()">
                    ⚡ Sync Urgentes Uniquement
                </button>
                <button class="btn btn-info" onclick="refreshStats()">
                    📊 Actualiser Stats
                </button>
                <button class="btn btn-danger" onclick="retryFailed()">
                    🔄 Reprendre Échecs
                </button>
            </div>

            <!-- Loading -->
            <div id="loading" class="loading">
                <div class="spinner"></div>
                <p>Traitement en cours...</p>
            </div>

            <!-- Progress Bar -->
            <div id="progress-container" style="display: none;">
                <div class="progress-bar">
                    <div class="progress-fill" id="progress-fill"></div>
                </div>
                <p id="progress-text" style="text-align: center; color: #6b7280;"></p>
            </div>

            <!-- Sync Log -->
            <div id="sync-log" class="sync-log">
                <h4>📋 Journal de synchronisation</h4>
                <div id="log-entries"></div>
            </div>
        </div>
    </div>

    <script>
        // Configuration
        const API_BASE = 'http://localhost:8080/sk/public/api/sync';
        // const EXTERNAL_SYNC_URL = 'https://app-skd-cloud-api-prod.digita.sn/api/sage-sync/receive-invoices'; // prod
        const EXTERNAL_SYNC_URL = 'https://sk-cloud-api-app.digita.sn/api/sage-sync/receive-invoices'; // text

        // Éléments DOM
        const elements = {
            // Buffer stats
            totalBuffer: document.getElementById('total-buffer'),
            pendingCount: document.getElementById('pending-count'),
            syncedCount: document.getElementById('synced-count'),
            failedCount: document.getElementById('failed-count'),
            pendingBalance: document.getElementById('pending-balance'),

            // Priorités
            tresUrgentCount: document.getElementById('tres-urgent-count'),
            urgentCount: document.getElementById('urgent-count'),
            normalCount: document.getElementById('normal-count'),
            surveillanceCount: document.getElementById('surveillance-count'),

            // Retards
            retard90Plus: document.getElementById('retard-90-plus'),
            retard6190: document.getElementById('retard-61-90'),
            retard3160: document.getElementById('retard-31-60'),
            retard130: document.getElementById('retard-1-30'),
            nonEchu: document.getElementById('non-echu'),
            avgOverdue: document.getElementById('avg-overdue'),

            // Sage
            totalSage: document.getElementById('total-sage'),
            vtemCount: document.getElementById('vtem-count'),
            ranoCount: document.getElementById('rano-count'),
            uniqueClients: document.getElementById('unique-clients'),
            dumpProgress: document.getElementById('dump-progress'),

            // Performance
            syncedToday: document.getElementById('synced-today'),
            failedToday: document.getElementById('failed-today'),
            actionsToday: document.getElementById('actions-today'),
            avgAttempts: document.getElementById('avg-attempts'),

            // Recouvrement
            recoveryNew: document.getElementById('recovery-new'),
            recoveryProgress: document.getElementById('recovery-progress'),
            recoveryContact: document.getElementById('recovery-contact'),
            recoveryPromise: document.getElementById('recovery-promise'),
            actionsWeek: document.getElementById('actions-week'),

            // Efficiency
            efficiencyScore: document.getElementById('efficiency-score'),
            efficiencyValue: document.getElementById('efficiency-value'),

            // Interface
            loading: document.getElementById('loading'),
            progressContainer: document.getElementById('progress-container'),
            progressFill: document.getElementById('progress-fill'),
            progressText: document.getElementById('progress-text'),
            syncLog: document.getElementById('sync-log'),
            logEntries: document.getElementById('log-entries'),
            urgentInvoicesBody: document.getElementById('urgent-invoices-body')
        };

        // Utilitaires
        function showAlert(type, message) {
            const alertElement = document.getElementById(`alert-${type}`);
            alertElement.textContent = message;
            alertElement.style.display = 'block';
            setTimeout(() => {
                alertElement.style.display = 'none';
            }, 5000);
        }

        function showLoading() {
            elements.loading.style.display = 'block';
            document.querySelectorAll('.btn').forEach(btn => btn.disabled = true);
        }

        function hideLoading() {
            elements.loading.style.display = 'none';
            document.querySelectorAll('.btn').forEach(btn => btn.disabled = false);
        }

        function addLogEntry(message) {
            const timestamp = new Date().toLocaleTimeString();
            const logEntry = document.createElement('div');
            logEntry.className = 'log-entry';
            logEntry.textContent = `[${timestamp}] ${message}`;
            elements.logEntries.appendChild(logEntry);
            elements.syncLog.style.display = 'block';
            elements.syncLog.scrollTop = elements.syncLog.scrollHeight;
        }

        function formatAmount(amount) {
            return new Intl.NumberFormat('fr-FR', {
                style: 'currency',
                currency: 'XOF',
                minimumFractionDigits: 0
            }).format(amount);
        }

        function formatDate(dateString) {
            if (!dateString) return '-';
            return new Date(dateString).toLocaleDateString('fr-FR');
        }

        function updateEfficiencyScore(score) {
            elements.efficiencyValue.textContent = score;
            elements.efficiencyScore.className = 'efficiency-score';

            if (score >= 80) {
                elements.efficiencyScore.classList.add('excellent');
            } else if (score >= 60) {
                elements.efficiencyScore.classList.add('good');
            } else {
                elements.efficiencyScore.classList.add('poor');
            }
        }

        // API Calls
        async function apiCall(endpoint, method = 'GET', data = null) {
            const options = {
                method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                }
            };

            if (data) {
                options.body = JSON.stringify(data);
            }

            const response = await fetch(`${API_BASE}${endpoint}`, options);
            return await response.json();
        }

        // Mise à jour des statistiques
        async function refreshStats() {
            try {
                addLogEntry('🔄 Actualisation des statistiques...');
                const response = await apiCall('/stats');

                if (response.success) {
                    const stats = response.stats;

                    // Sage
                    elements.totalSage.textContent = stats.comptability.total_invoices;
                    elements.vtemCount.textContent = stats.buffer.vtem_count;
                    elements.ranoCount.textContent = stats.buffer.rano_count;
                    elements.uniqueClients.textContent = stats.comptability.unique_clients;
                    elements.dumpProgress.textContent = `${stats.sync_progress.dumped_percentage}%`;

                    // Performance
                    elements.syncedToday.textContent = stats.performance.synced_today;
                    elements.failedToday.textContent = stats.performance.failed_today;
                    elements.actionsToday.textContent = stats.performance.pending_actions_today;
                    elements.avgAttempts.textContent = parseFloat(stats.performance.avg_sync_attempts || 0).toFixed(1);

                    // Recouvrement
                    elements.recoveryNew.textContent = stats.recovery.new_count;
                    elements.recoveryProgress.textContent = stats.recovery.in_progress_count;
                    elements.recoveryContact.textContent = stats.recovery.contact_made_count;
                    elements.recoveryPromise.textContent = stats.recovery.promise_count;
                    elements.actionsWeek.textContent = stats.recovery.actions_this_week;

                    // Score d'efficacité
                    updateEfficiencyScore(stats.sync_progress.efficiency_score);

                    // Factures urgentes
                    await updateUrgentInvoices();

                    addLogEntry('✅ Statistiques actualisées');
                } else {
                    throw new Error(response.message);
                }
            } catch (error) {
                addLogEntry(`❌ Erreur: ${error.message}`);
                showAlert('error', `Erreur lors de l'actualisation: ${error.message}`);
            }
        }

        // Mise à jour du tableau des factures urgentes
        async function updateUrgentInvoices() {
            try {
                const response = await apiCall('/unsynced-invoices?priority=TRES_URGENT,URGENT&limit=15');

                if (response.success && response.data.length > 0) {
                    const tbody = elements.urgentInvoicesBody;
                    tbody.innerHTML = '';

                    response.data.forEach(invoice => {
                        const row = document.createElement('tr');

                        const priorityClass = invoice.priority.toLowerCase().replace('_', '-');
                        const nextAction = invoice.next_action_date ? formatDate(invoice.next_action_date) : '-';

                        row.innerHTML = `
                            <td><strong>${invoice.invoice_number}</strong></td>
                            <td>
                                <div>${invoice.client.name}</div>
                                <small style="color: #6b7280;">${invoice.client.code}</small>
                            </td>
                            <td><strong>${formatAmount(invoice.balance_due)}</strong></td>
                            <td>${formatDate(invoice.due_date)}</td>
                            <td>
                                <span style="color: #ef4444; font-weight: 600;">
                                    ${invoice.days_overdue} jours
                                </span>
                            </td>
                            <td>
                                <span class="priority-badge ${priorityClass}">
                                    ${invoice.priority.replace('_', ' ')}
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.8rem; color: #6b7280;">
                                    ${invoice.recovery_status.replace('_', ' ')}
                                </span>
                            </td>
                            <td>${nextAction}</td>
                        `;

                        tbody.appendChild(row);
                    });
                } else {
                    elements.urgentInvoicesBody.innerHTML = `
                        <tr>
                            <td colspan="8" style="text-align: center; color: #10b981;">
                                ✅ Aucune facture urgente en attente
                            </td>
                        </tr>
                    `;
                }
            } catch (error) {
                elements.urgentInvoicesBody.innerHTML = `
                    <tr>
                        <td colspan="8" style="text-align: center; color: #ef4444;">
                            ❌ Erreur lors du chargement
                        </td>
                    </tr>
                `;
            }
        }

        // Dump des factures avec force refresh
        async function dumpInvoices(forceRefresh = false) {
            try {
                showLoading();
                addLogEntry('🔄 Démarrage du dump des factures...');

                const payload = {
                    limit: 2000,
                    from_date: '2020-01-01',
                    force_refresh: forceRefresh
                };

                const response = await apiCall('/dump-invoices', 'POST', payload);

                if (response.success) {
                    const message = `✅ Dump terminé: ${response.results.added_count} nouvelles, ${response.results.updated_count} mises à jour`;
                    addLogEntry(message);
                    showAlert('success', message);
                    await refreshStats();
                } else {
                    throw new Error(response.message);
                }
            } catch (error) {
                addLogEntry(`❌ Erreur dump: ${error.message}`);
                showAlert('error', `Erreur lors du dump: ${error.message}`);
            } finally {
                hideLoading();
            }
        }

        // Synchronisation vers l'app principale
        async function pushInvoices(priorityFilter = null) {
            try {
                showLoading();
                elements.progressContainer.style.display = 'block';
                addLogEntry('🚀 Démarrage de la synchronisation...');

                // Étape 1: Dump des nouvelles factures
                addLogEntry('📥 Étape 1: Dump des factures...');
                elements.progressFill.style.width = '20%';
                elements.progressText.textContent = 'Dump des nouvelles factures...';

                await dumpInvoices(false);

                // Étape 2: Récupération des factures à synchroniser
                addLogEntry('📋 Étape 2: Récupération des factures non sync...');
                elements.progressFill.style.width = '40%';
                elements.progressText.textContent = 'Récupération des factures à synchroniser...';

                const queryParams = priorityFilter ? `?priority=${priorityFilter}&limit=2000` : '?limit=2000';
                const unsyncedResponse = await apiCall(`/unsynced-invoices${queryParams}`);

                if (!unsyncedResponse.success) {
                    throw new Error(`Erreur récupération: ${unsyncedResponse.message}`);
                }

                const invoicesData = unsyncedResponse.data;
                addLogEntry(`📊 ${invoicesData.length} factures à synchroniser`);

                if (invoicesData.length === 0) {
                    addLogEntry('ℹ️ Aucune facture à synchroniser');
                    showAlert('info', 'Aucune facture en attente de synchronisation');
                    return;
                }

                // Étape 3: Envoi vers l'application externe
                addLogEntry('🌐 Étape 3: Envoi vers l\'application externe...');
                elements.progressFill.style.width = '60%';
                elements.progressText.textContent = 'Envoi des données à l\'application externe...';

                const syncPayload = {
                    invoices: invoicesData,
                    metadata: {
                        timestamp: new Date().toISOString(),
                        source: 'laravel-sync-buffer-v2',
                        version: '2.0',
                        priority_filter: priorityFilter,
                        total_count: invoicesData.length
                    }
                };

                // Envoyer vers l'application externe
                const externalResponse = await fetch(EXTERNAL_SYNC_URL, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-API-Key': 'sk-digitanalh2HRpxrDVJ6bkk5Gy0iHehnf6i9Czhtiv7rG82REOENWLzK42Sv6qGW04cLz4j3hhyf44yJ3d8jShdudGl9NzvuGUfQHPkiHg1YtUL9dEWsbZ55yrJYY'
                    },
                    body: JSON.stringify(syncPayload)
                });

                if (!externalResponse.ok) {
                    throw new Error(`Erreur application externe: ${externalResponse.status} - ${externalResponse.statusText}`);
                }

                const externalResult = await externalResponse.json();
                addLogEntry(`✅ Réponse externe reçue: ${externalResult.message || 'OK'}`);

                // Étape 4: Marquage comme synchronisé
                addLogEntry('✅ Étape 4: Marquage des factures comme synchronisées...');
                elements.progressFill.style.width = '80%';
                elements.progressText.textContent = 'Mise à jour du statut des factures...';

                const invoiceIds = invoicesData.map(invoice => invoice.id);
                const batchId = 'batch_' + new Date().toISOString().slice(0, 19).replace(/[-:]/g, '').replace('T', '_');

                const markSyncedResponse = await apiCall('/mark-synced', 'POST', {
                    invoice_ids: invoiceIds,
                    sync_batch_id: batchId,
                    notes: `Synchronisé via application externe v2 le ${new Date().toLocaleString()}`
                });

                if (!markSyncedResponse.success) {
                    throw new Error(`Erreur marquage: ${markSyncedResponse.message}`);
                }

                // Finalisation
                elements.progressFill.style.width = '100%';
                elements.progressText.textContent = 'Synchronisation terminée!';
                addLogEntry(`🎉 Synchronisation réussie: ${invoiceIds.length} factures`);

                const filterText = priorityFilter ? ` (priorité: ${priorityFilter})` : '';
                showAlert('success', `${invoiceIds.length} factures synchronisées avec succès${filterText}!`);

                await refreshStats();

            } catch (error) {
                addLogEntry(`❌ Erreur synchronisation: ${error.message}`);
                showAlert('error', `Erreur lors de la synchronisation: ${error.message}`);
            } finally {
                hideLoading();
                setTimeout(() => {
                    elements.progressContainer.style.display = 'none';
                    elements.progressFill.style.width = '0%';
                }, 3000);
            }
        }

        // Synchronisation des factures urgentes uniquement
        async function pushUrgentOnly() {
            if (confirm('Synchroniser uniquement les factures TRÈS URGENTES et URGENTES ?')) {
                await pushInvoices('TRES_URGENT,URGENT');
            }
        }

        // Reprendre les échecs
        async function retryFailed() {
            try {
                if (!confirm('Remettre en attente toutes les factures en échec ?')) {
                    return;
                }

                showLoading();
                addLogEntry('🔄 Reprise des factures en échec...');

                // Remettre les échecs en pending (via un endpoint à créer)
                const response = await fetch(`${API_BASE}/retry-failed`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
                    }
                });

                if (response.ok) {
                    const result = await response.json();
                    addLogEntry(`✅ ${result.updated_count || 0} factures remises en attente`);
                    showAlert('success', 'Factures en échec remises en attente de synchronisation');
                    await refreshStats();
                } else {
                    throw new Error('Erreur lors de la remise en attente');
                }

            } catch (error) {
                addLogEntry(`❌ Erreur reprise échecs: ${error.message}`);
                showAlert('error', `Erreur: ${error.message}`);
            } finally {
                hideLoading();
            }
        }

        // Initialisation
        document.addEventListener('DOMContentLoaded', function() {
            addLogEntry('🚀 Interface de synchronisation v2.0 initialisée');
            refreshStats();

            // Auto-refresh toutes les 60 secondes
            setInterval(refreshStats, 60000);

            // Vérification de connectivité toutes les 5 minutes
            setInterval(async () => {
                try {
                    const response = await apiCall('/ping');
                    if (!response.success) {
                        addLogEntry('⚠️ Problème de connectivité détecté');
                    }
                } catch (error) {
                    addLogEntry('❌ Perte de connectivité');
                }
            }, 300000);
        });

        // Gestion des raccourcis clavier
        document.addEventListener('keydown', function(e) {
            if (e.ctrlKey) {
                switch(e.key) {
                    case 'd':
                        e.preventDefault();
                        dumpInvoices();
                        break;
                    case 's':
                        e.preventDefault();
                        pushInvoices();
                        break;
                    case 'r':
                        e.preventDefault();
                        refreshStats();
                        break;
                    case 'u':
                        e.preventDefault();
                        pushUrgentOnly();
                        break;
                }
            }
        });

        // Gestion des erreurs globales
        window.addEventListener('error', function(e) {
            addLogEntry(`❌ Erreur JavaScript: ${e.error.message}`);
        });

        // Notification de visibilité de la page
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page redevenue visible, rafraîchir les stats
                setTimeout(refreshStats, 1000);
            }
        });
    </script>
</body>
</html>