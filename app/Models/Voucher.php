<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Voucher extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'voucher_type',
        'source_type',
        'source_id',
        'date',
        'ac_dr_sid',
        'ac_cr_sid',
        'amount',
        'remarks',
        'attachments',
    ];

    protected $casts = [
        'attachments' => 'array',
        'date'        => 'date',
    ];

    public function debitAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_dr_sid');
    }

    public function creditAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'ac_cr_sid');
    }

    public function source()
    {
        return $this->morphTo();
    }

    public function scopeType($query, string $type)
    {
        return $query->where('voucher_type', $type);
    }

    public function scopeManual($query)
    {
        return $query->whereIn('voucher_type', ['journal', 'payment', 'receipt']);
    }

    public function scopeSystem($query)
    {
        return $query->whereNotIn('voucher_type', ['journal', 'payment', 'receipt']);
    }

    public function scopeForSource($query, string $sourceType, int $sourceId)
    {
        return $query->where('source_type', $sourceType)
                     ->where('source_id', $sourceId);
    }

    public function isManual(): bool
    {
        return in_array($this->voucher_type, ['journal', 'payment', 'receipt']);
    }

    public function isSystem(): bool
    {
        return !$this->isManual();
    }

    public function getSourceLabelAttribute(): string
    {
        if (!$this->source_type) return '-';
        return class_basename($this->source_type) . ' #' . $this->source_id;
    }
}