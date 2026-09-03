<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * The ways a document can arrive.
 *
 * A registry rather than an enum because `documents.ingest_source` is a
 * VARCHAR: a route that is added later must not require a schema change, and a
 * row written by a route that has since been removed still has to render
 * something sensible on the document page. `label()` falls back to the stored
 * key for exactly that reason.
 *
 * The keys are short and stable. They are written into every row and read back
 * for the rest of the document's life, so renaming one is a migration, not an
 * edit here.
 */
final class IngestSource
{
    /** Somebody chose a file on the upload page. */
    public const UPLOAD = 'upload';

    /**
     * A document that predates native ingest — it arrived by the Paperless
     * webhook, back when there was one.
     *
     * Never written by anything: `017_remove_paperless.sql` stamps it onto the
     * rows that already existed, because attributing them to a person who did
     * not upload them would be a lie the document page would then repeat.
     */
    public const LEGACY = 'legacy';

    /**
     * key => how to describe it on screen.
     *
     * @var array<string,string>
     */
    private const LABELS = [
        self::UPLOAD => 'Uploaded',
        self::LEGACY => 'Imported',
    ];

    /**
     * The routes a document can actually arrive by today, for a filter menu.
     *
     * `legacy` is deliberately absent: it is a historical marker, not a route,
     * and offering it as somewhere documents come from would be wrong.
     *
     * @return array<string,string>
     */
    public static function routes(): array
    {
        return [self::UPLOAD => self::LABELS[self::UPLOAD]];
    }

    /** How to describe a stored source, including one nothing writes any more. */
    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? ucfirst(str_replace('_', ' ', $key));
    }

    public static function isKnown(string $key): bool
    {
        return array_key_exists($key, self::LABELS);
    }
}
