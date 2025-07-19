<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class InvoiceSyncBuffer extends Model
{
    use HasFactory;

    protected $table = 'invoice_sync_buffer';

    // === DÉSACTIVER LES TIMESTAMPS AUTOMATIQUES ===
    public $timestamps = false;

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

        // === ENTIERS ===
        'days_overdue' => 'integer',
        'sync_attempts' => 'integer',

        // === BOOLÉENS ===
        'is_lettred' => 'boolean',

        // === JSON ===
        'change_history' => 'array',

        // === TOUTES LES DATES SONT DES STRINGS ===
        // Pas de cast datetime
    ];

    // === CONSTANTES POUR LES ENUMS ===
    const STATUS_PENDING = 'pending';
    const STATUS_SYNCED = 'synced';
    const STATUS_FAILED = 'failed';
    const STATUS_EXCLUDED = 'excluded';

    const PRIORITY_SURVEILLANCE = 'SURVEILLANCE';
    const PRIORITY_NORMAL = 'NORMAL';
    const PRIORITY_URGENT = 'URGENT';
    const PRIORITY_TRES_URGENT = 'TRES_URGENT';

    const OVERDUE_NON_ECHU = 'NON_ECHU';
    const OVERDUE_1_30 = 'RETARD_1_30';
    const OVERDUE_31_60 = 'RETARD_31_60';
    const OVERDUE_61_90 = 'RETARD_61_90';
    const OVERDUE_90_PLUS = 'RETARD_PLUS_90';

    const RECOVERY_NEW = 'NEW';
    const RECOVERY_IN_PROGRESS = 'IN_PROGRESS';
    const RECOVERY_CONTACT_MADE = 'CONTACT_MADE';
    const RECOVERY_PROMISE_TO_PAY = 'PROMISE_TO_PAY';
    const RECOVERY_LITIGATION = 'LITIGATION';
    const RECOVERY_WRITE_OFF = 'WRITE_OFF';
    const RECOVERY_COLLECTED = 'COLLECTED';

    const JOURNAL_VTEM = 'VTEM';
    const JOURNAL_RANO = 'RANO';
    const JOURNAL_OTHER = 'OTHER';

    // === SCOPES ===
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

    public function scopeByClient(Builder $query, string $clientCode): Builder
    {
        return $query->where('client_code', $clientCode);
    }

    public function scopeAmountBetween(Builder $query, float $min, float $max): Builder
    {
        return $query->whereBetween('balance_due', [$min, $max]);
    }

    // === MÉTHODES UTILITAIRES ===

    public function markAsSynced(string $notes = null, string $batchId = null): void
    {
        $now = now()->format('Y-m-d H:i:s');
        
        $updateData = [
            'sync_status' => self::STATUS_SYNCED,
            'synced_at' => $now,
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => $now,
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
        $now = now()->format('Y-m-d H:i:s');
        
        $this->update([
            'sync_status' => self::STATUS_FAILED,
            'sync_attempts' => $this->sync_attempts + 1,
            'last_sync_attempt' => $now,
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

    public function updateRecoveryStatus(string $status, string $notes = null, string $nextActionDate = null): void
    {
        $updateData = [
            'recovery_status' => $status,
            'last_contact_date' => now()->format('Y-m-d'),
        ];

        if ($notes) {
            $updateData['recovery_notes'] = $notes;
        }

        if ($nextActionDate) {
            $updateData['next_action_date'] = $nextActionDate;
        }

        $this->update($updateData);
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

    public function getPriorityColorAttribute(): string
    {
        return match($this->priority) {
            self::PRIORITY_TRES_URGENT => '#dc2626',
            self::PRIORITY_URGENT => '#ef4444',
            self::PRIORITY_NORMAL => '#f59e0b',
            self::PRIORITY_SURVEILLANCE => '#6b7280',
            default => '#6b7280'
        };
    }

    public function getSyncStatusColorAttribute(): string
    {
        return match($this->sync_status) {
            self::STATUS_SYNCED => '#10b981',
            self::STATUS_PENDING => '#f59e0b',
            self::STATUS_FAILED => '#ef4444',
            self::STATUS_EXCLUDED => '#6b7280',
            default => '#6b7280'
        };
    }

    // === FORMATAGE DATES ===

    public function getInvoiceDateFormatted(): string
    {
        return $this->invoice_date ? date('d/m/Y', strtotime($this->invoice_date)) : '-';
    }

    public function getDueDateFormatted(): string
    {
        return $this->due_date ? date('d/m/Y', strtotime($this->due_date)) : '-';
    }

    public function getSyncedAtFormatted(): string
    {
        return $this->synced_at ? date('d/m/Y H:i', strtotime($this->synced_at)) : '-';
    }

    public function getLastSyncAttemptFormatted(): string
    {
        return $this->last_sync_attempt ? date('d/m/Y H:i', strtotime($this->last_sync_attempt)) : '-';
    }
}