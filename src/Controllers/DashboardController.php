<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Setting;
use App\Services\Llm\LlmFactory;
use App\Services\PdfRenderer;

final class DashboardController extends Controller
{
    /**
     * The pipeline at a glance.
     *
     * Minimal for now — counts, the newest documents, and an honest word about
     * what is not configured yet. It grows into the real operations view later;
     * what matters here is that the shell, the theme and the numbers are real
     * rather than mocked.
     */
    public function index(): void
    {
        $counts = Document::countsByStatus();

        // The three that need a person, in the order a person cares about them.
        $attention = [
            [
                'status'  => Document::NEEDS_REVIEW,
                'label'   => 'Needs review',
                'count'   => $counts[Document::NEEDS_REVIEW],
                'tone'    => 'warn',
                'caption' => 'Waiting on a decision',
            ],
            [
                'status'  => Document::READY_TO_SUBMIT,
                'label'   => 'Ready to submit',
                'count'   => $counts[Document::READY_TO_SUBMIT],
                'tone'    => 'info',
                'caption' => 'Resolved, not yet sent',
            ],
            [
                'status'  => Document::FAILED,
                'label'   => 'Failed',
                'count'   => $counts[Document::FAILED],
                'tone'    => 'danger',
                'caption' => 'Retryable',
            ],
        ];

        // Everything still moving through the machine, as one number: the
        // individual stages matter to whoever is debugging, not to the person
        // asking whether there is work for them today.
        $inFlight = $counts[Document::RECEIVED]
            + $counts[Document::OCR_PENDING]
            + $counts[Document::OCR_DONE]
            + $counts[Document::EXTRACTING]
            + $counts[Document::EXTRACTED]
            + $counts[Document::MATCHING];

        /*
         * Anything that has sat still longer than it should have.
         *
         * The counts above say how much work there is; they say nothing about
         * a document that entered `extracting` on Tuesday and is still there.
         * That is the failure mode this whole screen exists to catch, because
         * it is the one nothing else complains about: the queue has given up
         * retrying, the status is not `failed`, and the document simply rots.
         */
        // Setting::get() deals in strings, because a settings row is text.
        $pipelineMinutes = max(1, (int) Setting::get('stuck_pipeline_minutes', '30'));
        $reviewDays      = max(1, (int) Setting::get('stuck_review_days', '7'));
        $stuck           = Document::stuck($pipelineMinutes, $reviewDays);

        $this->view('dashboard/index', [
            'pageTitle' => 'Dashboard',
            'counts'    => $counts,
            'attention' => $attention,
            'inFlight'  => $inFlight,
            'recent'    => Document::recent(10),
            'total'     => Document::total(),

            'stuck'           => $stuck,
            'stuckPipeline'   => $pipelineMinutes,
            'stuckReviewDays' => $reviewDays,

            // Who did what, lately. Behind `audit.view` because it names people
            // and what they changed, which is an administrator's business
            // rather than everybody's.
            'activity' => Auth::can('audit.view') ? AuditLog::recent(15) : [],

            // What the machine did, lately — the other half of the same
            // question, and the half that answers "why has nothing moved?".
            'failures' => Auth::can('documents.retry') ? DocumentEvent::recentFailures(8) : [],

            // What still has to be filled in before a document can get all the
            // way through. Shown to administrators only; there is nothing a
            // reviewer could do about it.
            'setupGaps' => $this->setupGaps(),
        ]);
    }

    /**
     * The credentials and settings a working pipeline needs, and whether each
     * one is present.
     *
     * @return array<int,array{label:string,done:bool,hint:string}>
     */
    private function setupGaps(): array
    {
        $checks = [
            [
                'label' => 'PDF page rendering',
                'done'  => PdfRenderer::isAvailable(),
                'hint'  => 'poppler-utils provides pdftoppm. Without it, nothing can be read.',
            ],
            [
                'label' => 'Paperless address and API token',
                'done'  => Setting::isConfigured('paperless_base_url') && Setting::isConfigured('paperless_token'),
                'hint'  => 'Needed to pull document metadata and the source PDF.',
            ],
            [
                'label' => 'Webhook shared secret',
                'done'  => Setting::isConfigured('paperless_webhook_secret'),
                'hint'  => 'The Paperless workflow presents this; anything else is rejected.',
            ],
            [
                'label' => 'Clear Books OAuth2 credentials and business id',
                'done'  => Setting::isConfigured('clearbooks_client_id')
                    && Setting::isConfigured('clearbooks_client_secret')
                    && Setting::isConfigured('clearbooks_business_id'),
                'hint'  => 'Needed to read the supplier and account code lists, and to submit.',
            ],
        ];

        // Only the providers actually selected. Complaining about an OpenAI key
        // on a site that has chosen Anthropic for both stages is noise, and
        // noise in a checklist is what teaches people to stop reading it.
        $seen = [];

        foreach (LlmFactory::STAGES as $stage) {
            $provider = LlmFactory::provider($stage);

            if (isset($seen[$provider])) {
                continue;
            }

            $seen[$provider] = true;

            $checks[] = [
                'label' => ucfirst($provider) . ' API key',
                'done'  => LlmFactory::isConfigured($stage),
                'hint'  => 'Selected for the ' . $stage . ' stage (' . LlmFactory::model($stage) . ').',
            ];
        }

        return $checks;
    }
}
