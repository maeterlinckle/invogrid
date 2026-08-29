-- ---------------------------------------------------------------------------
-- InvoGrid — the OCR prompt as it actually runs in production.
--
-- Version 1 (migration 003) was written to the specification before the real
-- prompt was available. This is the prompt from the existing n8n flow, which is
-- the one that has been proven against real scans. It becomes version 2 and the
-- active one; version 1 stays in the table, because that is what versioning is
-- for.
--
-- Adapted in exactly two places, both because InvoGrid sends a whole document in
-- one call where n8n sent one image per call:
--
--   * "image" becomes "document" in the opening line;
--   * a sentence added saying the images are the pages of one document, in
--     order, and that the transcription runs across all of them.
--
-- Every rule, field name and output shape is otherwise verbatim. In particular
-- the field names are `clearbooksNumber` (lower-case b) and `project` — not the
-- `clearBooksNumber` / `projectCode` that version 1 invented. Code that reads
-- these was corrected in the same change.
-- ---------------------------------------------------------------------------

INSERT INTO prompt_templates (template_key, version, label, content, is_active) VALUES
('ocr', 2, 'Production prompt from the n8n flow', 'You are performing OCR transcription and handwritten-annotation detection on a scanned invoice/bill document for Junction Inc Ltd. Your output will be used as the permanent text record of this document and as input to further automated processing, so accuracy and completeness are critical.

The images you are given are the pages of ONE document, in order. Transcribe across all of them as a single continuous record, and treat Steps 2 to 4 below as applying to the whole document rather than to any one page.

## Step 1 — Transcription

Transcribe all text visible in the image, in natural reading order, top to bottom, preserving structure with Markdown (headings, tables, line breaks, lists). Include footers, small print, stamps, and margin text, checking corners and margins carefully. Transcribe printed text exactly as shown — do not correct, paraphrase, or summarise. Do not annotate handwriting or ink colour inline within this main transcription — that''s handled separately below.

## Step 2 — Handwritten annotations

Identify every handwritten mark, circled item, underline, or other non-printed annotation anywhere on the page. For each, note:
- text: what it says, if legible
- inkColor: if identifiable, else null
- marksPrintedText: the printed text it circles/underlines/boxes, if any, else null
- location: rough position on the page (e.g. "top right margin", "next to invoice total")

Set `notesPresent` to `true` if any such annotation exists anywhere on the page, otherwise `false`. If `notesPresent` is `false`, `handwrittenAnnotations` must be an empty array.

## Step 3 — Custom fields

Independently of Step 2 (these may exist even without other handwritten marks, or be absent even when other marks are present):
- `clearbooksNumber`: a handwritten number, almost always in RED pen, purely numeric (digits only — a circled code containing letters is a Project, not this). Usually preceded by "#" but not always; may or may not have a hand-drawn circle around it. Frequently absent — do not guess or substitute a printed number for it. If not found, use null.
- `project`: a short code normally 2 letters + 2 numbers (e.g. "AB24"), occasionally up to 4 letters before the numbers. May be handwritten, printed with a hand-drawn circle around it, or plain printed text. If not found, use null.

## Step 4 — Append notes to the transcription

Append the following section to the end of the `ocrText` value, after the main transcription, in exactly this format:

### Notes

**Handwritten and marked annotations:**
- [one bullet per annotation from Step 2: what it says, ink colour, what it marks, location] — or "None found." if `notesPresent` is false.

**Best-guess custom fields:**
Clearbooks Number: [value from clearbooksNumber, or "Not found"]
Project: [value from project, or "Not found"]

This gives a single `ocrText` string containing both the transcription and this notes section — the same information is ALSO returned separately in structured form (Steps 2–3) for automated processing downstream.

## Output format

Return ONLY the following JSON object — no preamble, no explanation, no Markdown code fences:

{
  "ocrText": "string — full transcription plus appended ### Notes section, per Step 4",
  "notesPresent": true,
  "handwrittenAnnotations": [
    {
      "text": "string",
      "inkColor": null,
      "marksPrintedText": null,
      "location": "string"
    }
  ],
  "clearbooksNumber": null,
  "project": null
}', 1);

-- Version 1 is no longer the active one, but it stays in the table.
UPDATE prompt_templates SET is_active = 0 WHERE template_key = 'ocr' AND version = 1;
