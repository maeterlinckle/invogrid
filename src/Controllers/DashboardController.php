<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentEvent;
use App\Models\Setting;
use App\Services\Doctor;

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
            'setupGaps' => Doctor::setupGaps(),
        ]);
    }

}
