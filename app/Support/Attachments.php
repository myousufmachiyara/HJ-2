<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Shared attachment handling for invoices, returns, receivings and vouchers.
 *   - files are stored on the "public" disk
 *   - they are opened through route('attachments.show') (works even when the
 *     public/storage link is missing, and only for logged-in users with access)
 *   - on edit, ticked existing files (remove_attachments[]) are deleted
 */
class Attachments
{
    public const RULE = 'nullable|file|mimes:jpg,jpeg,png,webp,pdf,zip,xlsx,xls,csv,doc,docx|max:5120';

    /** Save uploaded files into a hasMany attachments relation. */
    public static function saveTo(HasMany $relation, Request $request, string $folder, string $input = 'attachments'): void
    {
        foreach ((array) $request->file($input, []) as $file) {
            if (!$file || !$file->isValid()) continue;
            $relation->create([
                'file_path'     => $file->store($folder, 'public'),
                'original_name' => $file->getClientOriginalName(),
                'file_type'     => $file->getClientMimeType(),
            ]);
        }
    }

    /** Delete the attachments the user ticked for removal (only ones belonging to this relation). */
    public static function removeFrom(HasMany $relation, Request $request, string $input = 'remove_attachments'): void
    {
        $ids = array_filter(array_map('intval', (array) $request->input($input, [])));
        if (!$ids) return;
        foreach ($relation->whereIn('id', $ids)->get() as $att) {
            Storage::disk('public')->delete($att->file_path);
            $att->delete();
        }
    }

    /** Display name of an attachment (original name, else the stored file name). */
    public static function name($att): string
    {
        $path = is_array($att) ? ($att['path'] ?? '') : (is_string($att) ? $att : ($att->file_path ?? ''));
        $name = is_object($att) ? ($att->original_name ?? $att->file_name ?? null) : null;
        return $name ?: basename($path);
    }
}
