<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

/**
 * One rendered page image.
 *
 * Paths are stored relative to the storage root, so moving the storage
 * directory or restoring onto another machine does not invalidate every row.
 */
final class DocumentPage
{
    /**
     * Replace a document's pages with a freshly rendered set.
     *
     * Replace rather than add: a re-render after a settings change produces
     * different files, and leaving the old rows behind would give the OCR stage
     * two versions of page 1 to send.
     *
     * @param array<int,array{page:int,relative:string,width:int,height:int}> $pages
     */
    public static function replaceAll(int $documentId, array $pages): void
    {
        Database::beginTransaction();

        try {
            Database::run('DELETE FROM document_pages WHERE document_id = ?', [$documentId]);

            foreach ($pages as $page) {
                Database::insert('document_pages', [
                    'document_id' => $documentId,
                    'page_number' => $page['page'],
                    'image_path'  => $page['relative'],
                    'width'       => $page['width'],
                    'height'      => $page['height'],
                ]);
            }

            Database::update('documents', ['page_count' => count($pages)], $documentId);

            Database::commit();
        } catch (\Throwable $e) {
            Database::rollBack();

            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    public static function forDocument(int $documentId): array
    {
        return Database::select(
            'SELECT * FROM document_pages WHERE document_id = ? ORDER BY page_number',
            [$documentId]
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $documentId, int $pageNumber): ?array
    {
        return Database::selectOne(
            'SELECT * FROM document_pages WHERE document_id = ? AND page_number = ?',
            [$documentId, $pageNumber]
        );
    }

    public static function count(int $documentId): int
    {
        return (int) Database::scalar('SELECT COUNT(*) FROM document_pages WHERE document_id = ?', [$documentId]);
    }

    /** Where a document's page images live, absolute. */
    public static function directory(int $documentId): string
    {
        return rtrim((string) Config::get('storage.pages'), '/' . chr(92))
            . DIRECTORY_SEPARATOR . $documentId;
    }

    /** The same directory as stored in the database: relative to the storage root. */
    public static function relativeDirectory(int $documentId): string
    {
        return 'pages/' . $documentId;
    }
}
