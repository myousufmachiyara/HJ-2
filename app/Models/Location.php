<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A place where stock can sit.
 *
 *  type = warehouse : our own warehouse / shop (one of them is the default)
 *  type = customer  : a customer / marketplace holding our stock (e.g. Laam)
 *  type = vendor    : a vendor holding our stock (fabric dropped at a CMT)
 *
 * Every location has its own inventory account in the chart of accounts
 * ("Stock @ <name>") so the value of stock at each place is visible in the
 * ledger. The default warehouse uses 104001 Stock in Hand.
 */
class Location extends Model
{
    use HasFactory, SoftDeletes;

    public const WAREHOUSE = 'warehouse';
    public const CUSTOMER  = 'customer';
    public const VENDOR    = 'vendor';

    protected $fillable = ['name', 'code', 'type', 'is_default', 'chart_of_account_id', 'inventory_account_id'];

    protected $casts = [
        'is_default' => 'boolean',
    ];

    public function stockTransfersFrom()
    {
        return $this->hasMany(StockTransfer::class, 'from_location_id');
    }

    public function stockTransfersTo()
    {
        return $this->hasMany(StockTransfer::class, 'to_location_id');
    }

    // The customer / vendor account this location belongs to (null = own warehouse).
    public function customer()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'chart_of_account_id');
    }

    public function party()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'chart_of_account_id');
    }

    public function inventoryAccount()
    {
        return $this->belongsTo(ChartOfAccounts::class, 'inventory_account_id');
    }

    public function isCustomer(): bool
    {
        return $this->type === self::CUSTOMER;
    }

    public function isVendor(): bool
    {
        return $this->type === self::VENDOR;
    }

    public function isWarehouse(): bool
    {
        return $this->type === self::WAREHOUSE || $this->type === null;
    }

    public function typeLabel(): string
    {
        return match ($this->type) {
            self::CUSTOMER => 'Customer / Marketplace',
            self::VENDOR   => 'Vendor / CMT',
            default        => 'Warehouse',
        };
    }

    // ── Scopes ────────────────────────────────────────────────────────
    public function scopeWarehouses($query)
    {
        return $query->where('type', self::WAREHOUSE);
    }

    public function scopeCustomers($query)
    {
        return $query->where('type', self::CUSTOMER);
    }

    public function scopeVendors($query)
    {
        return $query->where('type', self::VENDOR);
    }

    /** Locations grouped for <optgroup> dropdowns. */
    public static function grouped()
    {
        return static::orderByRaw("FIELD(type,'warehouse','customer','vendor')")
            ->orderByDesc('is_default')->orderBy('name')->get()
            ->groupBy(fn ($l) => $l->typeLabel());
    }

    public static function default(): ?self
    {
        return static::warehouses()->where('is_default', true)->first()
            ?? static::warehouses()->orderBy('id')->first();
    }

    public static function defaultId(): ?int
    {
        return static::default()?->id;
    }

    public static function forAccount(?int $accountId): ?self
    {
        return $accountId ? static::where('chart_of_account_id', $accountId)->first() : null;
    }

    public function makeDefault(): bool
    {
        if (!$this->isWarehouse()) {
            return false;
        }

        static::query()->where('is_default', true)->update(['is_default' => false]);
        $this->is_default = true;
        return $this->save();
    }

    /**
     * Inventory account for this location — created on first use.
     */
    public function inventoryAccountId(): int
    {
        // the in-memory model may be stale — always check the stored value first
        if (!$this->inventory_account_id && $this->exists) {
            $this->inventory_account_id = static::withTrashed()->whereKey($this->id)->value('inventory_account_id');
        }

        if ($this->inventory_account_id && ChartOfAccounts::whereKey($this->inventory_account_id)->exists()) {
            return (int) $this->inventory_account_id;
        }

        // The (first) default warehouse uses the classic Stock in Hand account.
        if ($this->is_default && $this->isWarehouse()) {
            $stockInHand = ChartOfAccounts::where('account_code', '104001')->first();
            if ($stockInHand && !static::where('inventory_account_id', $stockInHand->id)->where('id', '!=', $this->id)->exists()) {
                $this->inventory_account_id = $stockInHand->id;
                $this->saveQuietly();
                return (int) $stockInHand->id;
            }
        }

        $inventoryShoa = SubHeadOfAccounts::where('name', 'Inventory')->value('id') ?? 4;
        $userId        = auth()->id() ?? User::orderBy('id')->value('id');

        $account = ChartOfAccounts::create([
            'shoa_id'      => $inventoryShoa,
            'account_code' => ChartOfAccounts::nextCode($inventoryShoa),
            'name'         => 'Stock @ ' . $this->name,
            'account_type' => 'inventory',
            'receivables'  => 0,
            'payables'     => 0,
            'credit_limit' => 0,
            'opening_date' => now()->toDateString(),
            'remarks'      => 'Auto-created for stock location #' . $this->id,
            'created_by'   => $userId,
            'updated_by'   => $userId,
        ]);

        $this->inventory_account_id = $account->id;
        $this->saveQuietly();

        return (int) $account->id;
    }

    /**
     * Ensure a stock-holder location exists for a customer or vendor account.
     * Idempotent — called whenever an account is created/updated.
     */
    public static function syncForAccount(ChartOfAccounts $account): ?self
    {
        $type = match ($account->account_type) {
            'customer' => self::CUSTOMER,
            'vendor'   => self::VENDOR,
            default    => null,
        };
        if (!$type) {
            return null;
        }

        $location = static::withTrashed()->firstOrNew(['chart_of_account_id' => $account->id]);
        $prefix   = $type === self::CUSTOMER ? 'Customer: ' : 'Vendor: ';
        $name     = $prefix . $account->name;
        // location names are unique — fall back to adding the code on a clash
        if (static::withTrashed()->where('name', $name)->where('id', '!=', $location->id ?? 0)->exists()) {
            $name .= ' (' . $account->account_code . ')';
        }
        $location->name       = $name;
        $location->code       = ($type === self::CUSTOMER ? 'CUST-' : 'VEND-') . $account->account_code;
        $location->type       = $type;
        $location->is_default = false;
        $location->deleted_at = null;
        $location->save();

        if ($location->inventory_account_id) {
            ChartOfAccounts::whereKey($location->inventory_account_id)->update(['name' => 'Stock @ ' . $location->name]);
        }

        return $location;
    }

    /** Backwards-compatible name used by older code. */
    public static function syncForCustomer(ChartOfAccounts $account): ?self
    {
        return static::syncForAccount($account);
    }

    /** Create missing customer/vendor locations and inventory accounts. */
    public static function syncAll(): void
    {
        ChartOfAccounts::whereIn('account_type', ['customer', 'vendor'])->get()
            ->each(fn ($acc) => static::syncForAccount($acc));

        static::all()->each(fn ($loc) => $loc->inventoryAccountId());
    }
}
