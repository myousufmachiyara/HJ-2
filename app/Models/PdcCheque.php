<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Post-dated cheque issued to a vendor against one or more bills.
 *
 * Life cycle: issued → presented → cleared
 *                    ↘ bounced / cancelled (→ optionally replaced by a new cheque)
 */
class PdcCheque extends Model
{
    use SoftDeletes;

    public const STATUSES = [
        'issued'    => ['label' => 'Issued',             'badge' => 'bg-primary'],
        'presented' => ['label' => 'Presented to bank',  'badge' => 'bg-info'],
        'cleared'   => ['label' => 'Cleared',            'badge' => 'bg-success'],
        'bounced'   => ['label' => 'Bounced',            'badge' => 'bg-danger'],
        'cancelled' => ['label' => 'Cancelled',          'badge' => 'bg-secondary'],
        'replaced'  => ['label' => 'Replaced',           'badge' => 'bg-warning text-dark'],
    ];

    /** Statuses where the cheque still counts as paying the bills. */
    public const LIVE = ['issued', 'presented', 'cleared'];

    /** Allowed transitions */
    public const FLOW = [
        'issued'    => ['presented', 'cleared', 'bounced', 'cancelled'],
        'presented' => ['cleared', 'bounced', 'issued'],
        'cleared'   => ['bounced', 'presented'],
        'bounced'   => ['replaced', 'presented'],
        'cancelled' => ['replaced'],
        'replaced'  => [],
    ];

    protected $fillable = [
        'pdc_no', 'vendor_id', 'bank_account_id', 'cheque_no', 'issue_date', 'cheque_date',
        'amount', 'status', 'status_date', 'replaced_by_id', 'remarks', 'created_by',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'cheque_date' => 'date',
        'status_date' => 'date',
    ];

    public function vendor()      { return $this->belongsTo(ChartOfAccounts::class, 'vendor_id'); }
    public function bankAccount() { return $this->belongsTo(ChartOfAccounts::class, 'bank_account_id'); }
    public function bills()       { return $this->hasMany(PdcChequeBill::class); }
    public function logs()        { return $this->hasMany(PdcChequeLog::class)->latest('id'); }
    public function replacedBy()  { return $this->belongsTo(PdcCheque::class, 'replaced_by_id'); }
    public function replaces()    { return $this->hasOne(PdcCheque::class, 'replaced_by_id'); }
    public function creator()     { return $this->belongsTo(User::class, 'created_by'); }

    public function statusLabel(): string { return self::STATUSES[$this->status]['label'] ?? $this->status; }
    public function statusBadge(): string { return self::STATUSES[$this->status]['badge'] ?? 'bg-light'; }
    public function isLive(): bool        { return in_array($this->status, self::LIVE, true); }

    public function isOverdue(): bool
    {
        return in_array($this->status, ['issued', 'presented'], true) && $this->cheque_date && $this->cheque_date->isPast();
    }

    public static function nextNo(): string
    {
        $max = static::withTrashed()->where('pdc_no', 'like', 'PDC-%')->lockForUpdate()->pluck('pdc_no')
            ->map(fn ($n) => (int) substr($n, 4))->max() ?? 0;
        return 'PDC-' . str_pad($max + 1, 5, '0', STR_PAD_LEFT);
    }
}
