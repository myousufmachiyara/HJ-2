<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * No back-dated entries except for the super admin.
 *
 *   normal users : new entries must be dated today; entries dated before today
 *                  cannot be edited or deleted.
 *   super admin  : any date, any entry.
 *
 * "Today" is the business day in config('app.timezone') (Asia/Karachi by default).
 */
class EntryDate
{
    public static function isSuperAdmin(): bool
    {
        $u = auth()->user();
        return $u && ((int) $u->id === 1 || $u->hasAnyRole(['superadmin', 'super-admin', 'Super Admin']));
    }

    public static function today(): string
    {
        return Carbon::now(config('app.timezone'))->toDateString();
    }

    /** A new entry (or a status change) must be dated today. */
    public static function assertNew($date, string $field = 'date'): void
    {
        if (self::isSuperAdmin() || $date === null || $date === '') return;

        $d = self::normalize($date);
        if ($d !== self::today()) {
            self::fail($field, 'Only today\'s date (' . Carbon::parse(self::today())->format('d-m-Y') . ') can be used. '
                . 'Back-dated or future entries can only be made by the super admin.');
        }
    }

    /**
     * Editing / deleting: entries from previous days are locked, and an edit must keep
     * today's date.
     */
    public static function assertEditable(?Model $entry, string $attribute, $newDate = null, string $field = 'date'): void
    {
        if (self::isSuperAdmin() || !$entry) return;

        $current = $entry->{$attribute} ? self::normalize($entry->{$attribute}) : null;
        if ($current !== null && $current < self::today()) {
            self::fail($field, 'This entry is dated ' . Carbon::parse($current)->format('d-m-Y')
                . ' — entries from previous days are locked. Ask the super admin to change it.');
        }
        if ($newDate !== null && $newDate !== '') {
            self::assertNew($newDate, $field);
        }
    }

    private static function normalize($date): string
    {
        return $date instanceof \DateTimeInterface ? Carbon::instance($date)->toDateString() : Carbon::parse((string) $date)->toDateString();
    }

    private static function fail(string $field, string $message): void
    {
        throw new HttpResponseException(
            request()->expectsJson()
                ? response()->json(['message' => $message, 'errors' => [$field => [$message]]], 422)
                : back()->withInput()->withErrors([$field => $message])->with('error', $message)
        );
    }
}
