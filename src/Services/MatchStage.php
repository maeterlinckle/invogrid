<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ClearbooksCache;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\DocumentType;
use App\Models\EntityMatch;
use App\Models\Extraction;
use RuntimeException;

/**
 * Turn what the extraction stage *said* into what the submission can *use*.
 *
 * The matching was largely done a stage ago: the supplier call was handed the
 * cached supplier list and reported whether it found one, and the line-items
 * call chose an account code and a VAT rate per line from lists it was given.
 * This stage is not a second opinion on any of that. It does three things the
 * model cannot:
 *
 *  1. **Checks the ids are real.** An id the model returned is a claim, and a
 *    model given a long list of numbers occasionally returns a number that was
 *    not in it — or one that was in it when the prompt was built and has since
 *    been archived. Every id is looked up in the current cache. A guess that
 *    does not survive that is worth less than no guess at all, because it is
 *    the one kind of error that reaches Clear Books looking correct.
 *  2. **Runs the deterministic fallback** for a supplier the model could not
 *    place: case, punctuation, `&`/`and` and legal suffixes stop counting, on
 *    both sides. This catches "ACME SUPPLIES LTD." against "Acme Supplies
 *    Limited", which is most of what an LLM is not needed for.
 *  3. **Writes down the outcome per entity**, in `entity_matches`, so the
 *    review screen can show a person exactly which thing is unresolved rather
 *    than "this document needs review".
 *
 * The rule at the end is the whole point of the stage: a document goes to
 * `ready_to_submit` only when **every** required entity resolved *and* the
 * extraction flagged nothing. Anything else goes to `needs_review` with the
 * reason attached. Nothing below full confidence is ever auto-created in Clear
 * Books.
 *
 * **This is also where the two flows part.** A document whose `route` is
 * `existing_invoice` is a scan of something already in Clear Books, and goes to
 * `existing_invoice` — the head of the linking stage — instead. It runs
 * everything above first, and for the same reasons: the extraction and the
 * entity matches are the document's record, wanted for search and reporting
 * whether or not anything is ever posted from them.
 *
 * **And it is where a New Invoice document is checked for being one anyway.**
 * The route was decided on whether somebody wrote a Clearbooks Number on the
 * page, which answers a different question from whether Clear Books already
 * holds the invoice. So a document taking the New Invoice exit is compared
 * against the synced purchase documents first (`DuplicateMatcher`, §34), and a
 * plausible match sends it to `possible_duplicate` rather than to either
 * disposition. That check runs here rather than in a stage of its own because
 * it wants `documents.matched_supplier_id`, which the lines above are what
 * produce.
 */
final class MatchStage
{
    /** Notes this stage raised carry a prefix, so a re-run can replace its own. */
    private const NOTE_PREFIX = 'Matching: ';

    /**
     * @param array<string,mixed> $document
     * @return string The status the document moves to
     */
    public function run(array $document): string
    {
        $documentId = (int) $document['id'];
        $extraction = Extraction::latest($documentId);

        if ($extraction === null) {
            throw new RuntimeException(
                'This document has nothing extracted to match. Retry it from Read.'
            );
        }

        $extractionId = (int) $extraction['id'];
        $notes        = [];
        $rows         = [];

        $supplier = $this->supplier($extraction, $notes);
        $rows[]   = $supplier;

        foreach ($this->treatment($extraction) as $row) {
            $rows[] = $row;
        }

        foreach ($this->lines($extraction) as $row) {
            $rows[] = $row;
        }

        EntityMatch::replaceAutomatic($extractionId, $rows);

        // The document's own copy of the supplier, so the list page and the
        // submission do not have to walk the extraction to find it.
        Document::setMatchedSupplier(
            $documentId,
            $supplier['status'] === EntityMatch::MATCHED ? (string) $supplier['matched_id'] : null,
            $supplier['status'] === EntityMatch::MATCHED ? (string) $supplier['matched_name'] : null
        );

        // A human may have resolved something by hand since the last pass, and
        // those rows survive replaceAutomatic(). Asking the table rather than
        // the local tally is therefore the only correct question.
        $unresolved = EntityMatch::unresolved($extractionId);

        foreach ($unresolved as $row) {
            $note = self::NOTE_PREFIX . EntityMatch::label((string) $row['entity_type'])
                . ($row['line_index'] === null ? '' : ' on line ' . ((int) $row['line_index'] + 1))
                . ': ' . (string) ($row['note'] ?? 'nothing on file matched "' . $row['raw_value'] . '".');

            if (!in_array($note, $notes, true)) {
                $notes[] = $note;
            }
        }

        // A credit note and a purchase refund look alike, are treated completely
        // differently by Clear Books, and the difference is often not on the
        // page at all — it was agreed on the telephone. So a document of either
        // kind waits for a person to say which it is, however cleanly everything
        // else resolved. This is the one review reason that is not about a
        // failure to match.
        $needsAgreement = DocumentType::requiresConfirmation($extraction['doc_type'] ?? null)
            && !Extraction::typeConfirmed($extraction);

        if ($needsAgreement) {
            $notes[] = self::NOTE_PREFIX . 'this reads as '
                . strtolower(DocumentType::label($extraction['doc_type'] ?? null))
                . '. A credit note and a refund are recorded differently and are easily confused, '
                . 'so somebody has to confirm which this is before it can be submitted.';
        }

        // Anything the extraction stage flagged still stands: a due date the
        // model was unsure of is not made certain by a supplier matching.
        //
        // Note that this stage's *own* notes are not in the test. Some of them
        // are purely informational — "the id the model gave was stale, the name
        // fallback found it anyway" describes a document that is completely
        // resolved — and holding those back would strand documents for no
        // reason. The unresolved set and the agreement are the two things that
        // actually block.
        $flaggedEarlier = $this->earlierNotes($extraction);
        $ready          = $unresolved === [] && $flaggedEarlier === [] && !$needsAgreement;

        Extraction::setMatchOutcome($extractionId, array_merge($flaggedEarlier, $notes), !$ready);

        /*
         * The fork.
         *
         * An existing-invoice document goes to the linking stage **whatever the
         * entities did**, and the difference is not laxness — it is that the
         * things above gate a *creation*. An unresolved account code decides
         * which nominal a new bill is posted to; a VAT rate decides what is
         * reclaimed; the credit-note question decides which way money moves.
         * None of that is asked here, because nothing is posted: the record was
         * entered by a person months ago and InvoGrid is only attaching the
         * scan to it.
         *
         * What was unresolved is still written down — `entity_matches`, the
         * review notes and `needs_review` on the extraction are all set above,
         * a few lines up — so nothing is lost and a person looking at the
         * document can see it. It simply does not hold the document up.
         *
         * The gate on *this* flow is the checksum in `LinkStage`, and it is
         * stricter than anything here: the Clearbooks Number must find exactly
         * one Clear Books record whose date and gross total agree exactly.
         */
        if ((string) ($document['route'] ?? '') === Document::ROUTE_EXISTING) {
            DocumentEvent::record($documentId, 'match', DocumentEvent::SUCCEEDED, sprintf(
                '%d entit%s checked, %d unresolved. This is an existing invoice, so it goes to be '
                . 'matched against the Clear Books record rather than submitted as a new one.',
                count($rows),
                count($rows) === 1 ? 'y' : 'ies',
                count($unresolved)
            ));

            return Document::EXISTING_INVOICE;
        }

        /*
         * The duplicate gate, and it is the *other* branch this stage takes.
         *
         * A New Invoice document is here because nobody wrote a Clearbooks
         * Number on it. That is what routed it, and it is not the same fact as
         * "Clear Books does not already hold this invoice" — a bill entered by
         * hand months ago carries no annotation, and neither does a second scan
         * of one already filed. Submitting it would put the same purchase into
         * somebody's accounts twice, which is found by a payment run rather
         * than by anything here.
         *
         * **Before the ready/needs-review decision, not after it**, and that
         * ordering is the point:
         *
         *  - a document whose entities all resolved would otherwise sit in
         *    `ready_to_submit` inviting exactly the double post this exists to
         *    stop, with a submit button on it;
         *  - a document whose entities did not would otherwise send somebody
         *    off to resolve an account code on a bill they are about to delete.
         *
         * Everything above still ran and is still written down, exactly as for
         * an existing-invoice document: `entity_matches`, the review notes and
         * `needs_review` on the extraction are all set a few lines up. A
         * document confirmed as genuinely new keeps all of it and lands
         * wherever this stage would have sent it, because the re-run comes
         * straight back through here.
         */
        if (($document['duplicate_cleared_at'] ?? null) === null) {
            $duplicates = DuplicateMatcher::against($document, $extraction);

            if ($duplicates['plausible'] !== []) {
                DocumentEvent::record($documentId, 'dedup', DocumentEvent::SKIPPED, $duplicates['summary']);

                return Document::POSSIBLE_DUPLICATE;
            }

            // Recorded even when it finds nothing, because "checked, and there
            // is nothing like it" is the answer somebody asking why a document
            // sailed through wants — and an absent event is indistinguishable
            // from a check that never ran.
            DocumentEvent::record($documentId, 'dedup', DocumentEvent::SUCCEEDED, sprintf(
                '%s %s',
                $duplicates['summary'],
                $duplicates['compared'] === 0
                    ? 'No purchase document in Clear Books shares its total or its reference.'
                    : sprintf(
                        '%d Clear Books record(s) sharing its total or its reference were compared.',
                        $duplicates['compared']
                    )
            ));
        }

        DocumentEvent::record($documentId, 'match', DocumentEvent::SUCCEEDED, sprintf(
            '%d entit%s checked, %d unresolved. %s',
            count($rows),
            count($rows) === 1 ? 'y' : 'ies',
            count($unresolved),
            $ready
                ? 'Nothing outstanding — ready to submit.'
                : count($flaggedEarlier) . ' thing(s) were already flagged; sending it for review.'
        ));

        return $ready ? Document::READY_TO_SUBMIT : Document::NEEDS_REVIEW;
    }

    /**
     * Re-run the check on a document a person is working on, outside the queue.
     *
     * The review screen needs this after every edit: a reviewer who corrects an
     * account code should see it turn green immediately, not after the next
     * cron tick. It is the same pass the queue runs — deliberately, because two
     * implementations of "is this document ready" would disagree eventually and
     * the disagreement would be invisible.
     *
     * Only from the statuses where re-matching means something. A submitted
     * document is not re-checked; nor is one somebody has ignored.
     *
     * `existing_invoice` and `needs_link` are on the list because this is also
     * how a route is overruled. The Existing Invoice queue's "treat it as a new
     * invoice" writes `route = new_invoice` and calls this; the stage runs
     * again, takes the other exit, and the document lands in the ordinary review
     * queue with the extraction it already has. Re-deciding through the one
     * implementation is the point — a second copy of "where does this document
     * go now" would disagree with this one eventually, invisibly.
     *
     * `possible_duplicate` is on it for exactly the same reason, and is the one
     * way *off* that status. The duplicate queue's "it is genuinely new" stamps
     * `documents.duplicate_cleared_at` and calls this; the gate below reads
     * that column, so the same run reaches a different exit and the document
     * lands wherever the entities put it.
     *
     * @return string The status the document is now in
     */
    public static function recheck(int $documentId): string
    {
        $document = Document::find($documentId);

        if ($document === null) {
            throw new RuntimeException('No such document.');
        }

        $status = (string) $document['status'];

        if (!in_array($status, [
            Document::NEEDS_REVIEW,
            Document::READY_TO_SUBMIT,
            Document::MATCHING,
            Document::EXISTING_INVOICE,
            Document::NEEDS_LINK,
            Document::POSSIBLE_DUPLICATE,
        ], true)) {
            return $status;
        }

        // `ready_to_submit → matching` is not a legal transition, and should not
        // be: a document that has been declared ready does not go back to being
        // worked on by the machine. It is walked through `needs_review`, which
        // is exactly what a document with a fresh edit is.
        if ($status === Document::READY_TO_SUBMIT) {
            Document::transitionTo($documentId, Document::NEEDS_REVIEW);
            $status = Document::NEEDS_REVIEW;
        }

        if ($status !== Document::MATCHING) {
            Document::transitionTo($documentId, Document::MATCHING);
        }

        $next = (new self())->run(Document::find($documentId) ?? $document);

        Document::transitionTo($documentId, $next);

        return $next;
    }

    // --- Supplier -----------------------------------------------------------

    /**
     * Resolve the supplier: confirm the model's match, or fall back to names.
     *
     * @param array<string,mixed> $extraction
     * @param array<int,string>   $notes
     * @return array<string,mixed> An entity_matches row
     */
    private function supplier(array $extraction, array &$notes): array
    {
        $match = Extraction::decode($extraction, 'supplier_match');
        $raw   = (string) ($extraction['supplier_name_raw'] ?? ($match['name'] ?? ''));

        $row = [
            'entity_type' => EntityMatch::SUPPLIER,
            'line_index'  => null,
            'raw_value'   => $raw === '' ? '(no name was read off the document)' : $raw,
            'matched_id'  => null,
            'matched_name' => null,
            'matched_via' => null,
            'confidence'  => null,
            'status'      => EntityMatch::UNMATCHED,
            'note'        => null,
        ];

        // 1. The model says it matched. Believe the *judgement*, verify the id.
        if (!empty($match['supplierMatched'])) {
            $claimed = $match['cbId'] ?? null;
            $cached  = is_scalar($claimed) && (string) $claimed !== ''
                ? ClearbooksCache::find(ClearbooksCache::SUPPLIER, (string) $claimed)
                : null;

            if ($cached !== null && (int) $cached['active'] === 1) {
                return [
                    'matched_id'   => (string) $cached['remote_id'],
                    'matched_name' => (string) $cached['name'],
                    'matched_via'  => EntityMatch::VIA_LLM,
                    'confidence'   => 1.0,
                    'status'       => EntityMatch::MATCHED,
                ] + $row;
            }

            // Not a reason to stop: the name fallback below may well place it,
            // and often does. But it is worth saying out loud, because a
            // recurring one means the cache is being read stale.
            $notes[] = self::NOTE_PREFIX . 'the extraction claimed Clear Books supplier '
                . (is_scalar($claimed) ? '"' . (string) $claimed . '"' : 'an unnamed id')
                . ', which is not in the current cache. Falling back to matching on the name.';
        }

        // 2. The deterministic pass, over the name and any trading names.
        $trading = array_values(array_filter(
            is_array($match['tradingNames'] ?? null) ? $match['tradingNames'] : [],
            'is_string'
        ));

        if ($raw === '' && $trading === []) {
            $row['note'] = 'no supplier name was read off the document.';

            return $row;
        }

        $found = ClearbooksCache::matchByName(ClearbooksCache::SUPPLIER, $raw, $trading);

        if ($found['ambiguous']) {
            // Two records on file reduce to the same name. Picking one is a
            // coin toss, and the cost of losing it is a bill posted against the
            // wrong supplier — so it goes to a person.
            $row['note'] = $found['candidates'] . ' suppliers on file have effectively this name. '
                . 'Pick the right one.';

            return $row;
        }

        if ($found['row'] === null) {
            $row['note'] = 'nothing on file matches "' . $raw . '". It will need creating in Clear Books.';

            return $row;
        }

        return [
            'matched_id'   => (string) $found['row']['remote_id'],
            'matched_name' => (string) $found['row']['name'],
            'matched_via'  => EntityMatch::VIA_FALLBACK,

            // The looser pass ignores word boundaries as well, so "A B C
            // Supplies" and "ABC Supplies" agree. Right often enough to use,
            // uncertain enough to record as less than certain.
            'confidence'   => $found['via'] === 'exact' ? 1.0 : 0.9,
            'status'       => EntityMatch::MATCHED,
        ] + $row;
    }

    // --- VAT treatment ------------------------------------------------------

    /**
     * The document-level VAT treatment.
     *
     * Absent from the extraction is not an error here — a document with no
     * treatment at all is caught by the line rates instead, and inventing an
     * unmatched row for it would put every pre-cache document into review
     * twice over.
     *
     * @param array<string,mixed> $extraction
     * @return array<int,array<string,mixed>>
     */
    private function treatment(array $extraction): array
    {
        $treatment = Extraction::decode($extraction, 'vat_treatment');
        $key       = (string) ($treatment['key'] ?? '');

        if ($key === '') {
            return [];
        }

        $cached = ClearbooksCache::find(ClearbooksCache::VAT_TREATMENT, $key);
        $valid  = $cached !== null && (int) $cached['active'] === 1;

        return [[
            'entity_type'  => EntityMatch::VAT_TREATMENT,
            'line_index'   => null,
            'raw_value'    => $key,
            'matched_id'   => $valid ? (string) $cached['remote_id'] : null,
            'matched_name' => $valid ? (string) $cached['name'] : null,
            'matched_via'  => $valid ? EntityMatch::VIA_LLM : null,
            'confidence'   => $valid ? 1.0 : null,
            'status'       => $valid ? EntityMatch::MATCHED : EntityMatch::UNMATCHED,
            'note'         => $valid ? null : '"' . $key . '" is not a purchase VAT treatment Clear Books offers.',
        ]];
    }

    // --- Line items ---------------------------------------------------------

    /**
     * An account code and a VAT rate for every line.
     *
     * Both are validated the same way and for the same reason: the extraction
     * chose from a list it was given, and this confirms the choice is still in
     * the list. `line_index` is what keeps two lines with different guesses
     * apart — without it the review screen could only say "an account code is
     * wrong".
     *
     * @param array<string,mixed> $extraction
     * @return array<int,array<string,mixed>>
     */
    private function lines(array $extraction): array
    {
        $lines = Extraction::decode($extraction, 'line_items');
        $rows  = [];

        if ($lines === []) {
            // The extraction stage already raised this; repeating it here would
            // put the same sentence in front of a person twice.
            return [];
        }

        foreach (array_values($lines) as $index => $line) {
            if (!is_array($line)) {
                continue;
            }

            $rows[] = $this->reference(
                EntityMatch::ACCOUNT_CODE,
                ClearbooksCache::ACCOUNT_CODE,
                $index,
                $line['accountCode'] ?? null,
                'no account code was chosen for this line.',
                'is not a purchase account code in the current chart of accounts.'
            );

            $rows[] = $this->reference(
                EntityMatch::VAT_RATE,
                ClearbooksCache::VAT_RATE,
                $index,
                $line['vatRateKey'] ?? null,
                'no VAT rate was chosen for this line.',
                'is not a purchase VAT rate Clear Books offers.'
            );
        }

        return $rows;
    }

    /**
     * One per-line reference, checked against the cache.
     *
     * @return array<string,mixed>
     */
    private function reference(
        string $entityType,
        string $cacheType,
        int $lineIndex,
        mixed $guess,
        string $missingNote,
        string $unknownNote,
    ): array {
        $value = is_scalar($guess) ? trim((string) $guess) : '';

        if ($value === '') {
            return [
                'entity_type'  => $entityType,
                'line_index'   => $lineIndex,
                'raw_value'    => '(none)',
                'matched_id'   => null,
                'matched_name' => null,
                'matched_via'  => null,
                'confidence'   => null,
                'status'       => EntityMatch::UNMATCHED,
                'note'         => $missingNote,
            ];
        }

        $cached = ClearbooksCache::find($cacheType, $value);
        $valid  = $cached !== null && (int) $cached['active'] === 1;

        return [
            'entity_type'  => $entityType,
            'line_index'   => $lineIndex,
            'raw_value'    => $value,
            'matched_id'   => $valid ? (string) $cached['remote_id'] : null,
            'matched_name' => $valid ? (string) $cached['name'] : null,
            'matched_via'  => $valid ? EntityMatch::VIA_LLM : null,
            'confidence'   => $valid ? 1.0 : null,
            'status'       => $valid ? EntityMatch::MATCHED : EntityMatch::UNMATCHED,
            'note'         => $valid ? null : '"' . $value . '" ' . $unknownNote,
        ];
    }

    /**
     * The extraction's own notes, with this stage's previous ones removed.
     *
     * A re-match must replace what it said last time rather than append to it,
     * or a document resolved and re-matched three times shows the same
     * complaint three times. Everything raised by an earlier *stage* is kept
     * exactly as it was — those judgements are not this stage's to overturn.
     *
     * @param array<string,mixed> $extraction
     * @return array<int,string>
     */
    private function earlierNotes(array $extraction): array
    {
        return array_values(array_filter(
            Extraction::reviewNotes($extraction),
            static fn (string $note): bool => !str_starts_with($note, self::NOTE_PREFIX)
        ));
    }
}
