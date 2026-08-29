-- ---------------------------------------------------------------------------
-- InvoGrid — how Clear Books actually treats the sign, and the third document
-- type that follows from it.
--
-- The rule this corrects, from Clear Books itself:
--
--   * A PurchaseDocument (a bill) is POSITIVE when it represents money spent
--     and NEGATIVE when it represents money refunded. Either way an actual
--     movement of money has happened.
--
--   * A PurchaseCreditNote takes POSITIVE values at the point of creation.
--     Clear Books inverts them internally. A credit note is an amount available
--     to set against an existing invoice — there is no monetary movement.
--
-- InvoGrid had `credit_note` at -1, which was a guess made when the API
-- specification turned out to say nothing about sign, and it was wrong. Sending
-- negative line prices on a credit note would have doubled the inversion and
-- put the amount back the way it started.
--
-- The correction also exposes a document type that was missing: a purchase
-- REFUND, where the supplier has actually paid money back. That is a bill with
-- negative amounts, not a credit note, and it had nowhere to go.
--
--   bill             purchases/bills        +1   money spent
--   credit_note      purchases/creditNotes  +1   an amount to set against an invoice
--   purchase_refund  purchases/bills        -1   money paid back
--
-- Telling the last two apart is genuinely hard and frequently cannot be done
-- from the document at all — the arrangement may have been agreed on the
-- telephone. So both of them require a person to confirm the choice before
-- anything is submitted; see `requires_confirmation` below.
-- ---------------------------------------------------------------------------

UPDATE document_types
   SET amount_sign = 1
 WHERE type_key = 'credit_note';

-- Whether a person must confirm the classification before the document may be
-- submitted. A column rather than a list in code, so the answer for a new type
-- stays a data change — which is the rule the whole `document_types` table
-- exists to keep.
ALTER TABLE document_types
    ADD COLUMN requires_confirmation TINYINT(1) NOT NULL DEFAULT 0 AFTER amount_sign;

UPDATE document_types
   SET requires_confirmation = 1
 WHERE type_key = 'credit_note';

INSERT INTO document_types (type_key, label, clearbooks_resource, amount_sign, requires_confirmation, sort_order, active) VALUES
    ('purchase_refund', 'Purchase refund', 'purchases/bills', -1, 1, 30, 1)
ON DUPLICATE KEY UPDATE
    clearbooks_resource   = VALUES(clearbooks_resource),
    amount_sign           = VALUES(amount_sign),
    requires_confirmation = VALUES(requires_confirmation);

-- A supplier's usual route, when one of their documents turns out to be a
-- credit note or a refund. Some suppliers always do one or the other, and
-- knowing that is the difference between a reviewer confirming a pre-selected
-- answer and working it out again every time.
--
-- Local knowledge, so it survives a cache refresh: `ClearbooksCache::upsert()`
-- writes name, normalised_name, raw_json and active, and touches nothing else —
-- the same reason `paperless_correspondent_id` lives here.
ALTER TABLE clearbooks_cache
    ADD COLUMN default_credit_route VARCHAR(32) NULL AFTER paperless_correspondent_id;

-- Who agreed that this document is what it says it is, and when.
--
-- Separate from `edited_by`: a reviewer may correct a date without ever having
-- looked at whether this is a credit note or a refund, and treating an edit as
-- agreement to the classification is exactly the shortcut that would put a
-- refund into the ledger as a credit note.
ALTER TABLE extractions
    ADD COLUMN doc_type_confirmed_at DATETIME     NULL AFTER edited_by,
    ADD COLUMN doc_type_confirmed_by INT UNSIGNED NULL AFTER doc_type_confirmed_at,
    ADD KEY fk_extractions_confirmer (doc_type_confirmed_by),
    ADD CONSTRAINT fk_extractions_confirmer FOREIGN KEY (doc_type_confirmed_by)
        REFERENCES users (id) ON DELETE SET NULL;

-- The line-items prompt, told how to tell the three apart. Version 2; version 1
-- said a negative or refund total made a document a credit note, which is the
-- error this migration exists to correct. Earlier versions stay in the table.
UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'extract_lines';

INSERT INTO prompt_templates (template_key, version, label, content, is_active)
SELECT 'extract_lines',
       COALESCE(MAX(version), 0) + 1,
       'Credit note vs purchase refund',
       'You are an invoice line-item and VAT extraction assistant for Junction Inc Ltd. You will be given the OCR text of a supplier invoice/bill along with reference lists for VAT treatments, VAT rates, and account codes.

<ocr_text>
{{ ocrText }}
</ocr_text>

<account_codes>
{{ accountCodes }}
</account_codes>

<vat_rates>
{{ vatRates }}
</vat_rates>

<vat_treatments>
{{ vatTreatments }}
</vat_treatments>

## documentType

One of "bill", "creditNote" or "purchaseRefund". This is the most consequential judgement in this call, and the three are genuinely easy to confuse, so read the guidance below rather than going by the document''s title alone.

- **"bill"** — the ordinary case. The supplier is charging Junction for goods or services. Money is owed or has been spent.

- **"creditNote"** — the supplier is giving Junction an amount that can be set against an invoice, reducing or clearing what is owed. **No money has moved.** Typically titled "Credit Note", and typically references the original invoice it applies to.

- **"purchaseRefund"** — money has actually come back to Junction. Look for wording about a refund being *made*, a payment or repayment *issued*, a card or bank transfer being credited, or a cheque sent.

**The trap:** a document headed "Credit Note" that goes on to describe a refund payment actually made is a **"purchaseRefund"**, not a "creditNote". The title is the weaker signal; what happened to the money is the stronger one. Words like "refund", "refunded", "repayment", "payment issued" or a named payment method point to purchaseRefund even under a credit-note heading.

Where the document does not settle it — and it often will not, because the arrangement may have been agreed by telephone and never written down — **still give your single best answer** and add a note to reviewNotes saying what is ambiguous and which way you leaned. Never leave it empty and never invent certainty. A person confirms this choice before anything is submitted, and a clear note is what makes that confirmation quick.

## vatTreatment

Pick the single best-matching entry from <vat_treatments> for the invoice as a whole (e.g. standard-rated UK purchase vs EU/reverse-charge vs no-VAT). Return both key and name from that list — never invent a key that is not present. Most Junction purchases are ordinary UK domestic purchases; only pick a reverse-charge/EU/import treatment if the invoice clearly indicates one (non-UK supplier address, "reverse charge" wording, no VAT charged on an EU invoice). If unsure, use the standard UK purchase treatment and add a note to reviewNotes.

## lineItems

All fields on every line item are required — never return null for any of them, accountCode included. If you are uncertain about a field (most often accountCode), still make your single best guess and add a note to reviewNotes explaining the uncertainty, rather than leaving it empty. A best guess that might need a human''s second look is more useful downstream than a gap that silently breaks the record.

For each distinct item or service billed, return:
- description: include ALL information available for this line — completeness comes first, tidiness second. Reformat and clean up spacing/structure where that makes it more readable, but never drop information just because it does not fit neatly into a single tidy line. Use multiple lines within the field where that makes the result clearer (e.g. product code on one line, full description on the next, any additional spec/notes on further lines) — there is no requirement to compress everything into one line. For example, OCR text reading "XLG-200-12-A" on one line and "200W Constant Power LED Driver" on the next can become a single line like "XLG-200-12-A — 200W Constant Power LED Driver" when that is a clean fit, but if there is more detail on the invoice (model variants, specs, part numbers, notes), keep it rather than trimming it out for brevity. If in doubt, a fuller, rawer version of the OCR text for that line is better than a tidier one that leaves something out. Do not invent details that are not on the invoice — only reformat and combine what is genuinely there.
- quantity: numeric.
- unitPrice: the net (VAT-exclusive) unit price. Use the printed net price directly if shown; if only VAT-inclusive prices are given, back out the net price using the identified rate. If you cannot reliably determine net vs gross, use the price as shown and note it in reviewNotes.
- lineTotal: the net line total as shown (or calculated if not shown).
- Sanity check: quantity * unitPrice should equal lineTotal. If it does not, prefer the invoice''s own printed line total and adjust unitPrice to reconcile (lineTotal / quantity) rather than the reverse. Note any material discrepancy in reviewNotes.
- accountCode: the single best match from <account_codes> for the nature of the goods/services on this line. Only use a code that appears in the list. Some specific cases to apply consistently:
  - **Discount lines**: work out what the discount most likely relates to (e.g. it is tied to a specific item or category elsewhere on the invoice) and use that item''s account code. If nothing on the invoice indicates what the discount relates to, fall back to the "Materials - General" account code.
  - **Delivery/shipping/postage lines**: use "Shipping Charges - Internet/Mail Order" for standard delivery, shipping, or postage charges on anything sent to Junction (parcel, courier delivery of goods, mail order shipping, etc.).
  - **Dedicated vehicle hire**: use "Couriers and Trucking" only for a dedicated van or truck booked specifically for transport — these typically appear as their own line without accompanying materials/goods lines on the same invoice, distinct from a standard parcel/mail shipping charge (which should use "Shipping Charges - Internet/Mail Order" instead, even if the invoice happens to use the word "courier").
  - If none of the above apply and you are still not confident in the best match, make your best guess anyway and add a note to reviewNotes — never return null.
- vatRateKey: the single best match from <vat_rates> for this line (most commonly UK standard 20%, reduced 5%, or zero-rated 0%). Only use a key that appears in the list. Make your best guess even if uncertain, and note it in reviewNotes — never return null.

## reviewNotes

Array of short strings flagging anything uncertain above. Empty array if nothing to flag.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences:

{
  "documentType": "bill" | "creditNote" | "purchaseRefund",
  "vatTreatment": {
    "key": "string",
    "name": "string"
  },
  "lineItems": [
    {
      "description": "string",
      "quantity": 0,
      "unitPrice": 0,
      "lineTotal": 0,
      "accountCode": 0,
      "vatRateKey": "string"
    }
  ],
  "reviewNotes": []
}',
       1
  FROM prompt_templates
 WHERE template_key = 'extract_lines';
