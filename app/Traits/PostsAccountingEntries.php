<?php

namespace App\Traits;

use App\Models\ChartOfAccounts;
use App\Models\Voucher;
use Illuminate\Database\Eloquent\Model;

trait PostsAccountingEntries
{
    /**
     * Wipe and recreate all voucher rows for a given source model.
     *
     * $entries = [
     *   ['dr' => '104001', 'cr' => '205001', 'amount' => 5000, 'remarks' => '...'],
     *   // or use 'dr_id' / 'cr_id' directly when the ID is dynamic (e.g. vendor_id)
     *   ['dr_id' => $vendorId, 'cr' => '402001', 'amount' => 390, 'remarks' => '...'],
     * ]
     */
    protected function syncVoucherEntries(
        Model  $source,
        string $voucherType,
        string $date,
        array  $entries
    ): void {
        // Wipe old auto-generated entries for this source
        Voucher::where('source_type', get_class($source))
               ->where('source_id', $source->id)
               ->delete();

        foreach ($entries as $entry) {
            $amount = (float)($entry['amount'] ?? 0);

            if ($amount <= 0) {
                continue; // skip zero-value lines
            }

            $drId = isset($entry['dr_id'])
                ? (int)$entry['dr_id']
                : $this->accountId($entry['dr']);

            $crId = isset($entry['cr_id'])
                ? (int)$entry['cr_id']
                : $this->accountId($entry['cr']);

            Voucher::create([
                'voucher_type' => $voucherType,
                'source_type'  => get_class($source),
                'source_id'    => $source->id,
                'date'         => $date,
                'ac_dr_sid'    => $drId,
                'ac_cr_sid'    => $crId,
                'amount'       => $amount,
                'remarks'      => $entry['remarks'] ?? null,
                'attachments'  => [],
            ]);
        }
    }

    protected function deleteVoucherEntries(Model $source): void
    {
        Voucher::where('source_type', get_class($source))
               ->where('source_id', $source->id)
               ->delete();
    }

    /**
     * Resolve a system account ID by account_code.
     * Results are cached per request so repeated lookups don't hit the DB.
     */
    protected function accountId(string $code): int
    {
        static $cache = [];
        return $cache[$code] ??= ChartOfAccounts::where('account_code', $code)->value('id')
            ?? throw new \RuntimeException("System account [{$code}] not found in chart_of_accounts.");
    }
}