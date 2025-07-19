<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

class InvoiceSyncBuffer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'invoice_sync_buffer';

    protected $fillable = [
        // === INFORMATIONS DE BASE FACTURE ===
        'invoice_number',
        'invoice_reference',
        'client_code',
        'client_name',
        'amount',
        'invoice_total',
        'amount_paid',
        'balance_due',
        'invoice_date',
        'due_date',

        // === INFORMATIONS SAGE ===
        'journal_type',
        'sage_lettrage_code',
        'is_lettred',

        // === SYNCHRONISATION ===
        'sync_status',
        'synced_at',
        'sync_notes',
        'sync_batch_id',

        // === INFORMATIONS CLIENT ENRICHIES ===
        'client_phone',
        'client_email',
        'client_address',
        'client_city',
        'commercial_contact',
        'client_credit_limit',
        'client_payment_terms',

        // === RETARD ET PRIORITÉ ===
        'overdue_category',
        'days_overdue',
        'priority',

        // === RECOUVREMENT ===
        'recovery_status',
        'last_contact_date',
        'next_action_date',
        'recovery_notes',

        // === MÉTADONNÉES SOURCE ===
        'source_entry_id',
        'sage_guid',
        'document_guid',
        'description',
        'source_created_at',
        'source_updated_at',

        // === HISTORIQUE ===
        'change_history',
        'last_sage_sync',

        // === TENTATIVES SYNC ===
        'sync_attempts',
        'last_sync_attempt',
        'last_error_message',
    ];

    protected $casts = [
        // === MONTANTS ===
        'amount' => 'decimal:2',
        'invoice_total' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance_due' => 'decimal:2',
        'client_credit_limit' => 'decimal:2',

        // === DATES ===
        'invoice_date' => 'date',
        'due_date' => 'date',
        'synced_at' => 'datetime',
        'last_contact_date' => 'date',
        'next_action_date' => 'date',
        'source_created_at' => 'datetime',
        'source_updated_at' => 'datetime',
        'last_sage_sync' => 'datetime',
        'last_sync_attempt' => 'datetime',

        // === ENTIERS ===
        'days_overdue' => 'integer',
        'sync_attempts' => 'integer',

        // === BOOLÉENS ===
        'is_lettred' => 'boolean',

        // === JSON ===
        'change_history' => 'array',
    ];

    // === CONSTANTES POUR LES ENUMS ===

    // Statuts de synchronisation
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED = 'synced';
    const STATUS_FAILED = 'failed';
    const STATUS_EXCLUDED = 'excluded';

    // Priorités
    const PRIORITY_SURVEILLANCE = 'SURVEILLANCE';
    const PRIORITY_NORMAL = 'NORMAL';
    const PRIORITY_URGENT = 'URGENT';
    const PRIORITY_TRES_URGENT = 'TRES_URGENT';

    // Catégories de retard
    const OVERDUE_NON_ECHU = 'NON_ECHU';
    const OVERDUE_1_30 = 'RETARD_1_30';
    const OVERDUE_31_60 = 'RETARD_31_60';
    const OVERDUE_61_90 = 'RETARD_61_90';
    const OVERDUE_90_PLUS = 'RETARD_PLUS_90';

    // Statuts de recouvrement
    const RECOVERY_NEW = 'NEW';
    const RECOVERY_IN_PROGRESS = 'IN_PROGRESS';
    const RECOVERY_CONTACT_MADE = 'CONTACT_MADE';
    const RECOVERY_PROMISE_TO_PAY = 'PROMISE_TO_PAY';
    const RECOVERY_LITIGATION = 'LITIGATION';
    const RECOVERY_WRITE_OFF = 'WRITE_OFF';
    const RECOVERY_COLLECTED = 'COLLECTED';

    // Types de journal
    const JOURNAL_VTEM = 'VTEM';
    const JOURNAL_RANO = 'RANO';
    const JOURNAL_OTHER = 'OTHER';

    // === SCOPES POUR FACILITER LES REQUÊTES ===

    public function scopePending(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_PENDING);
    }

    public function scopeSynced(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_SYNCED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('sync_status', self::STATUS_FAILED);
    }

    public function scopeByPriority(Builder $query, string $priority): Builder
    {
        return $query->where('priority', $priority);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->whereIn('overdue_category', [
            self::OVERDUE_1_30,
            self::OVERDUE_31_60,
            self::OVERDUE_61_90,
            self::OVERDUE_90_PLUS
        ]);
    }

    public function scopeUrgent(Builder $query): Builder
    {
        return $query->whereIn('priority', [
            self::PRIORITY_URGENT, 
            self::PRIORITY_TRES_URGENT
        ]);
    }

    public function scopeByBatch(Builder $query, string $batchId): Builder
    {
        return $query->where('sync_batch_id', $batchId);
    }

    public function scopeByRecoveryStatus(Builder $query, string $status): Builder
    {
        return $query->where('recovery_status', $status);
    }

    public function scopeByJournalType(Builder $query, string $journalType): Builder
    {
        return $query->where('journal_type', $journalType);
    }

    public function scopeLettred(Builder $query): Builder
    {
        return $query->where('is_lettred', true);
    }

    public function scopeNotLettred(Builder $query): Builder
    {
        return $query->where('is_lettred', false);
    }

    public function scopeByClient(Builder $query, string $clientCode): Builder
    {
        return $query->where('client_code', $clientCode);
    }

    public function scopeAmountBetween(Builder $query, float $min, float $max): Builder
    {
        return $query->whereBetween('balance_due', [$min, $max]);
    }

    public function scopeDueBefore(Builder $query, $date): Builder
    {
        return $query->where('due_date', '<', $date);
    }

    public function scopeNeedsAction(Builder $query): Builder
    {
        return $query->where('next_action_date', '<=', now()->toDateString());
    }

    // === MÉTHODES UTILITAIRES ===

    public function markAsSynced(string $notes = null, string $batchId = null): void
    {
        $now = Carbon::now();
        
        $updateData = [
            'sync_status' => self::STATUS_SYNCED,
            'synced_at' => $now->format('Y-m-d H:i:s.v'),
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => $now->format('Y-m-d H:i:s.v'),
        ];

        if ($notes) {
            $updateData['sync_notes'] = $notes;
        }

        if ($batchId) {
            $updateData['sync_batch_id'] = $batchId;
        }

        $this->update($updateData);
    }

    public function markAsFailed(string $errorMessage): void
    {
        $now = Carbon::now();
        
        $this->update([
            'sync_status' => self::STATUS_FAILED,
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => $now->format('Y-m-d H:i:s.v'),
            'last_error_message' => $errorMessage,
        ]);
    }

    public function resetForRetry(): void
    {
        $this->update([
            'sync_status' => self::STATUS_PENDING,
            'last_error_message' => null,
        ]);
    }

    public function updateRecoveryStatus(string $status, string $notes = null, $nextActionDate = null): void
    {
        $updateData = [
            'recovery_status' => $status,
            'last_contact_date' => now()->toDateString(),
        ];

        if ($notes) {
            $updateData['recovery_notes'] = $notes;
        }

        if ($nextActionDate) {
            $updateData['next_action_date'] = $nextActionDate;
        }

        $this->update($updateData);
    }

    public function addToChangeHistory(string $action, array $changes = []): void
    {
        $history = $this->change_history ?? [];
        $history[] = [
            'action' => $action,
            'changes' => $changes,
            'timestamp' => now()->toISOString(),
            'user' => auth()->id() ?? 'system',
        ];

        $this->update(['change_history' => $history]);
    }

    // === ACCESSEURS ===

    public function getFormattedAmountAttribute(): string
    {
        return number_format($this->amount, 0, ',', ' ') . ' FCFA';
    }

    public function getFormattedBalanceDueAttribute(): string
    {
        $balance = $this->balance_due ?? $this->amount;
        return number_format($balance, 0, ',', ' ') . ' FCFA';
    }

    public function getIsOverdueAttribute(): bool
    {
        return in_array($this->overdue_category, [
            self::OVERDUE_1_30,
            self::OVERDUE_31_60,
            self::OVERDUE_61_90,
            self::OVERDUE_90_PLUS
        ]);
    }

    public function getIsUrgentAttribute(): bool
    {
        return in_array($this->priority, [
            self::PRIORITY_URGENT, 
            self::PRIORITY_TRES_URGENT
        ]);
    }

    public function getDaysOverdueTextAttribute(): string
    {
        if ($this->days_overdue <= 0) {
            return 'À échoir';
        }
        return $this->days_overdue . ' jour' . ($this->days_overdue > 1 ? 's' : '') . ' de retard';
    }

    public function getOverdueCategoryTextAttribute(): string
    {
        return match($this->overdue_category) {
            self::OVERDUE_NON_ECHU => 'Non échu',
            self::OVERDUE_1_30 => 'Retard 1-30 jours',
            self::OVERDUE_31_60 => 'Retard 31-60 jours',
            self::OVERDUE_61_90 => 'Retard 61-90 jours',
            self::OVERDUE_90_PLUS => 'Retard +90 jours',
            default => 'Non défini'
        };
    }

    public function getPriorityTextAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_SURVEILLANCE => 'Surveillance',
            self::PRIORITY_NORMAL => 'Normal',
            self::PRIORITY_URGENT => 'Urgent',
            self::PRIORITY_TRES_URGENT => 'Très Urgent',
            default => 'Non défini'
        };
    }

    public function getRecoveryStatusTextAttribute(): string
    {
        return match($this->recovery_status) {
            self::RECOVERY_NEW => 'Nouveau',
            self::RECOVERY_IN_PROGRESS => 'En cours',
            self::RECOVERY_CONTACT_MADE => 'Contact établi',
            self::RECOVERY_PROMISE_TO_PAY => 'Promesse de paiement',
            self::RECOVERY_LITIGATION => 'Contentieux',
            self::RECOVERY_WRITE_OFF => 'Passé en perte',
            self::RECOVERY_COLLECTED => 'Récupéré',
            default => 'Non défini'
        };
    }

    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_TRES_URGENT => '#dc2626', // Rouge foncé
            self::PRIORITY_URGENT => '#ef4444',      // Rouge
            self::PRIORITY_NORMAL => '#f59e0b',      // Orange
            self::PRIORITY_SURVEILLANCE => '#6b7280', // Gris
            default => '#6b7280'
        };
    }

    public function getSyncStatusColorAttribute(): string
    {
        return match($this->sync_status) {
            self::STATUS_SYNCED => '#10b981',    // Vert
            self::STATUS_PENDING => '#f59e0b',   // Orange
            self::STATUS_FAILED => '#ef4444',    // Rouge
            self::STATUS_EXCLUDED => '#6b7280',  // Gris
            default => '#6b7280'
        };
    }

    // === MUTATEURS ===

    public function setInvoiceDateAttribute($value): void
    {
        $this->attributes['invoice_date'] = $value ? date('Y-m-d', strtotime($value)) : null;
    }

    public function setDueDateAttribute($value): void
    {
        $this->attributes['due_date'] = $value ? date('Y-m-d', strtotime($value)) : null;
    }

    public function setLastContactDateAttribute($value): void
    {
        $this->attributes['last_contact_date'] = $value ? date('Y-m-d', strtotime($value)) : null;
    }

    public function setNextActionDateAttribute($value): void
    {
        $this->attributes['next_action_date'] = $value ? date('Y-m-d', strtotime($value)) : null;
    }

    // === RELATIONS (si nécessaire plus tard) ===

    // public function syncLogs()
    // {
    //     return $this->hasMany(SyncLog::class);
    // }

    // public function recoveryActions()
    // {
    //     return $this->hasMany(RecoveryAction::class);
    // }
}