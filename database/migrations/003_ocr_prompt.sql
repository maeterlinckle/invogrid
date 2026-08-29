-- ---------------------------------------------------------------------------
-- InvoGrid — the default OCR prompt.
--
-- Seeded as version 1 of template key `ocr`. Editing it in the application
-- writes version 2 and deactivates this one, so the original is always
-- recoverable and every ocr_results row can say which version produced it.
--
-- NOTE FOR REVIEW: this is written to the behaviour specified for the build —
-- clean transcription plus structured output, the appended `### Notes` section,
-- and the Clear Books Number / Project code rules — but it is **not** a verbatim
-- copy of the prompt running in the existing n8n flow, which was not available.
-- Diff it against the original and adjust wording where the production one has
-- earned a particular phrasing.
-- ---------------------------------------------------------------------------

INSERT INTO prompt_templates (template_key, version, label, content, is_active) VALUES
('ocr', 1, 'Default transcription and annotation reading', 'You are transcribing a scanned purchase document for an accounts-payable system. It is a supplier invoice, bill, or credit note that has been scanned or photographed, and it may carry handwritten annotations added by staff after it arrived.

The images you are given are the pages of ONE document, in order.

Return a single JSON object and nothing else. No preamble, no explanation, no markdown fence.

{
  "ocrText": "the full transcription, see below",
  "notesPresent": true,
  "handwrittenAnnotations": [
    {
      "text": "what it says",
      "form": "handwritten | circled-print | printed",
      "colour": "red | blue | black | pencil | other",
      "location": "where on the page, in plain words",
      "page": 1,
      "confidence": 0.0
    }
  ],
  "clearBooksNumber": null,
  "clearBooksNumberConfidence": 0.0,
  "projectCode": null,
  "projectCodeConfidence": 0.0,
  "reviewNotes": []
}

## ocrText

Transcribe everything on every page, in reading order.

- Keep the wording exactly as printed. Do not correct spelling, expand
  abbreviations, translate, normalise, tidy or summarise anything.
- Keep every number exactly as it appears, including currency symbols,
  thousands separators, decimal marks, percentage signs and minus signs or
  brackets. 1.234,56 stays 1.234,56.
- Keep line items one per line, with their columns separated by spaces, so the
  rows can still be read as rows.
- Begin each page with a line reading: --- Page N ---
- Where something genuinely cannot be read, write [illegible] in its place
  rather than guessing at it.
- Transcribe handwriting inline where it sits, in addition to listing it in
  handwrittenAnnotations.

Then append this section to the very end of ocrText, after the last page:

### Notes
- one line per handwritten annotation, in the form: [colour, form] text (page N)

If there are no handwritten annotations, the section still appears, with the
single line: - none

The `### Notes` section is inside the ocrText string, not a separate field. It
is there so later stages can find the annotations by reading the text alone.

## handwrittenAnnotations

Every mark somebody added by hand after the document was produced: writing,
circling, ticks, arrows, stamps filled in by hand. Not the printed content of
the document, and not a supplier''s pre-printed signature block.

`form` distinguishes writing (handwritten) from something printed that has been
ringed by hand (circled-print) from plain printed text (printed). Only use
printed for a value you are reporting in a field below that turned out not to be
handwritten at all.

## clearBooksNumber

A reference written on the document by hand when it was processed.

- It is **digits only**. No letters, no dashes, no spaces, no slashes.
- It is almost always in **red pen**.
- It is **usually, but not always, preceded by a # symbol**. Report the digits
  only — drop the #.
- It is **occasionally circled**.

**Never substitute a printed number for a missing Clear Books Number.** If there
is no handwritten digits-only annotation on the document, `clearBooksNumber` is
null. It is not the supplier''s invoice number, not an account number, not a
purchase-order number, not a customer reference, and not any other number that
was printed by the supplier. A null here is a correct and expected answer, and a
wrong number here is far worse than none.

If digits are written by hand but you cannot read them all confidently, give
your best reading, set the confidence below 0.8, and add a reviewNote saying
which characters were unclear.

## projectCode

An internal job reference.

- Normally **two letters followed by two digits**, for example AB12.
- Occasionally the letter part runs to three or four, for example ABCD12.
- It may be **handwritten**, **printed and circled by hand**, or **plain printed
  text** on the document.
- Where there are several candidates, prefer a handwritten or circled one over
  plain printed text, and say in a reviewNote that there was more than one.

If nothing on the document matches that shape, `projectCode` is null.

## Confidence and reviewNotes

- Confidence is 0.0 to 1.0. Above 0.9 means you would be surprised to be wrong.
- Add a short reviewNote for **every judgement you are not fully confident in**:
  an ambiguous character, two possible readings, an annotation you could not
  place, a page that is skewed or cut off, a total that does not appear to add
  up.
- A note costs a person five seconds to read. A silent guess costs an hour to
  find. Write the note.
- Where you are unsure between two readings, give your best one in the field and
  put the alternative in the note. Do not leave a field null merely because you
  are uncertain — null means genuinely absent.', 1);

-- --- Rendering settings -----------------------------------------------------
-- 200 DPI puts an A4 page at 1654 x 2339, inside the 2576-pixel long edge the
-- current vision models accept without downscaling — so the detail we pay to
-- send is detail the model actually sees. It is also enough to read a biro
-- annotation, which 150 DPI is marginal for.
INSERT INTO settings (setting_key, setting_value, is_secret) VALUES
    ('pdf_render_dpi',    '200',  0),
    ('pdf_max_edge_px',   '2576', 0),
    ('pdf_render_format', 'jpeg', 0);

-- --- Model defaults ---------------------------------------------------------
-- Only where the value is still the one seeded in 002: a site that has already
-- chosen a model in Settings keeps its choice.
UPDATE settings SET setting_value = 'claude-opus-5'
 WHERE setting_key IN ('llm_ocr_model', 'llm_extraction_model')
   AND setting_value = 'claude-sonnet-5';
