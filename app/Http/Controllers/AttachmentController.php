<?php

namespace App\Http\Controllers;

use App\Models\ProductionReceivingAttachment;
use App\Models\PurchaseInvoiceAttachment;
use App\Models\PurchaseReturnAttachment;
use App\Models\Voucher;
use App\Support\Attachments;
use Illuminate\Support\Facades\Storage;

/**
 * Opens an attachment in the browser. Files are read from storage directly, so
 * links work even if the public/storage shortcut was never created on the server.
 */
class AttachmentController extends Controller
{
    public function show(string $kind, int $id, ?int $n = null)
    {
        [$path, $name, $permission] = match ($kind) {
            'purchase'        => $this->row(PurchaseInvoiceAttachment::findOrFail($id), 'purchase_invoices.index'),
            'purchase-return' => $this->row(PurchaseReturnAttachment::findOrFail($id), 'purchase_return.index'),
            'receiving'       => $this->row(ProductionReceivingAttachment::findOrFail($id), 'production_receiving.index'),
            'voucher'         => $this->voucher($id, (int) $n),
            default           => abort(404),
        };

        abort_unless(auth()->user()?->can($permission), 403);
        abort_unless($path && Storage::disk('public')->exists($path), 404, 'File not found on the server.');

        // inline = opens in the browser tab (PDF / images); other types download
        return Storage::disk('public')->response($path, $name, [], 'inline');
    }

    private function row($att, string $permission): array
    {
        return [$att->file_path, Attachments::name($att), $permission];
    }

    private function voucher(int $id, int $n): array
    {
        $v    = Voucher::findOrFail($id);
        $path = ($v->attachments ?? [])[$n] ?? null;
        return [$path, $path ? basename($path) : null, 'vouchers.index'];
    }
}
