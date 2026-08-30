<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * The purchase document types InvoGrid processes.
 *
 * Data rather than an enum, so a new type is an insert plus a prompt. Nothing
 * in the pipeline may hard-code a type key: it asks here for the Clear Books
 * resource to post to, the sign of the amounts and the Paperless document type
 * to write back.
 */
final class DocumentType
{
    /** @return array<int,array<string,mixed>> */
    public static function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM document_types';

        if ($activeOnly) {
            $sql .= ' WHERE active = 1';
        }

        return Database::select($sql . ' ORDER BY sort_order, label');
    }

    /** @return array<string,mixed>|null */
    public static function find(string $typeKey): ?array
    {
        return Database::selectOne('SELECT * FROM document_types WHERE type_key = ?', [$typeKey]);
    }

    public static function label(?string $typeKey): string
    {
        if ($typeKey === null || $typeKey === '') {
            return 'Not yet classified';
        }

        $row = self::find($typeKey);

        return $row === null ? $typeKey : (string) $row['label'];
    }

    /** The keys, for a validator's `in:` rule and for a select box. */
    /** @return array<int,string> */
    public static function keys(bool $activeOnly = true): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['type_key'],
            self::all($activeOnly)
        );
    }

    /**
     * Must a person agree this document really is this type before it is sent?
     *
     * True for a credit note and a purchase refund, and the reason is that the
     * two are genuinely hard to tell apart — and get the same document. A credit
     * note is an amount to set against an invoice with **no money moved**; a
     * refund is money that has actually come back. Clear Books treats them
     * completely differently, and the difference is frequently not written down
     * anywhere on the page: it was agreed by telephone.
     *
     * A document headed "Credit Note" that describes a refund payment made is a
     * refund. No amount of prompt engineering settles that reliably, so this is
     * where the machine stops and asks.
     *
     * A column rather than a list here, so the answer for a new type stays a
     * data change — which is what `document_types` exists for.
     */
    public static function requiresConfirmation(?string $typeKey): bool
    {
        if ($typeKey === null || $typeKey === '') {
            // Unclassified is not the same as "no confirmation needed": nobody
            // has said what this is, so nobody has agreed to it either.
            return true;
        }

        $row = self::find($typeKey);

        return $row !== null && (int) $row['requires_confirmation'] === 1;
    }

    /**
     * The types a reviewer chooses between when a document is not an ordinary
     * bill.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function needingConfirmation(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $row): bool => (int) $row['requires_confirmation'] === 1
        ));
    }

    /**
     * Point a document type at a Paperless document type, or at nothing.
     *
     * Null is a legitimate answer, not a missing one: a site that does not use
     * Paperless document types leaves every mapping empty, and the write-back
     * simply does not touch the field. Set from the Settings screen; until
     * that existed this column could only be changed in SQL.
     */
    public static function setPaperlessType(int $id, ?int $paperlessTypeId): void
    {
        Database::update('document_types', ['paperless_document_type_id' => $paperlessTypeId], $id);
    }

    /**
     * The sign applied to a line's unit price when it is submitted.
     *
     * `+1` for a bill (money spent) and for a **credit note** — Clear Books
     * takes a credit note positive at creation and inverts it internally, since
     * it represents an amount available against an invoice rather than a
     * movement of money. `-1` for a purchase refund, which is an ordinary
     * purchase document carrying money that came back.
     *
     * Sending a credit note negative would invert an inversion and put the
     * amount back where it started. InvoGrid did exactly that until this was
     * corrected.
     */
    public static function amountSign(?string $typeKey): int
    {
        $row = $typeKey === null || $typeKey === '' ? null : self::find($typeKey);

        return $row !== null && (int) $row['amount_sign'] < 0 ? -1 : 1;
    }
}
