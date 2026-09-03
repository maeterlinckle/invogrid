<?php

declare(strict_types=1);

use App\Controllers\AccountController;
use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\BrandingController;
use App\Controllers\ClearBooksController;
use App\Controllers\DashboardController;
use App\Controllers\DocumentController;
use App\Controllers\DuplicateController;
use App\Controllers\ExistingInvoiceController;
use App\Controllers\FieldController;
use App\Controllers\PromptController;
use App\Controllers\ReviewController;
use App\Controllers\SettingsController;
use App\Controllers\UploadController;
use App\Controllers\UserController;
use App\Core\Router;

/*
 * The route table.
 *
 * Middleware is named here rather than checked inside a controller, so what a
 * route requires can be read off one line. 'csrf' is on every state-changing
 * route without exception. Every route that accepts input is behind `auth`
 * as well: there is no unauthenticated way into this application and no
 * endpoint waiting to be given a shared secret.
 *
 * Every destination in the navigation is now a real route; nothing is
 * reserved for a later stage.
 */

$router = new Router();

// --- Open ------------------------------------------------------------------

// A liveness probe. Says nothing but "the process answered".
$router->get('/health', [AuthController::class, 'health']);

// The logo, needed by the sign-in page before anyone is signed in.
$router->get('/branding/{variant:light|dark}', [BrandingController::class, 'show']);

// --- Signing in ------------------------------------------------------------

$router->get('/login', [AuthController::class, 'showLogin'], ['guest'], 'login');
$router->post('/login', [AuthController::class, 'login'], ['guest', 'csrf']);

// POST rather than GET: a sign-out link that any page can trigger is a
// cross-site request forgery with extra steps.
$router->post('/logout', [AuthController::class, 'logout'], ['auth', 'csrf'], 'logout');

// --- Signed in -------------------------------------------------------------

$router->group(['auth'], static function (Router $router): void {
    $router->get('/', [DashboardController::class, 'index'], [], 'dashboard');

    // The raw document list: the precursor to the review queue, and what makes
    // the pipeline visible while the rest of it is being built.
    $router->get('/documents', [DocumentController::class, 'index'], ['can:documents.view'], 'documents');

    /*
     * Ingest: how a document gets into InvoGrid at all.
     *
     * Declared before `/documents/{id}` out of habit rather than necessity —
     * the id pattern is `\d+` and could not match "upload" — but the next route
     * added here may not be numeric, and the order that is already right costs
     * nothing.
     *
     * `documents.upload` rather than `documents.view`: accepting a file starts
     * a pipeline that spends money on every page of it, which is a different
     * question from being allowed to read the result.
     */
    $router->get('/documents/upload', [UploadController::class, 'form'], ['can:documents.upload'], 'documents.upload');
    $router->post('/documents/upload', [UploadController::class, 'store'], ['can:documents.upload', 'csrf']);

    $router->get('/documents/{id:\d+}', [DocumentController::class, 'show'], ['can:documents.view'], 'documents.show');
    $router->get('/documents/{id:\d+}/pdf', [DocumentController::class, 'pdf'], ['can:documents.view'], 'documents.pdf');
    $router->get('/documents/{id:\d+}/page/{page:\d+}', [DocumentController::class, 'page'], ['can:documents.view'], 'documents.page');

    // The one-page summary, for paper. `documents.view`, the same as the screen
    // it summarises — it contains nothing the document page does not.
    $router->get('/documents/{id:\d+}/print', [DocumentController::class, 'printable'], ['can:documents.view'], 'documents.print');

    // Retrying re-runs a stage and costs money once the LLM stages exist, so it
    // is a reviewer action rather than something any viewer can set off.
    $router->post('/documents/{id:\d+}/retry', [DocumentController::class, 'retry'], ['can:documents.retry', 'csrf']);
    $router->post('/documents/{id:\d+}/ignore', [DocumentController::class, 'ignore'], ['can:documents.retry', 'csrf']);

    /*
     * The review queue — what a person actually uses this application for.
     *
     * Viewing is `queue.view`, which a viewer has; everything that changes
     * something is `review.resolve` or higher. The two are split because
     * "who can look at the queue" and "who can alter a bill before it reaches
     * the accounts" are genuinely different questions.
     */
    $router->get('/review', [ReviewController::class, 'index'], ['can:queue.view'], 'review');
    $router->get('/review/{id:\d+}', [ReviewController::class, 'show'], ['can:queue.view'], 'review.show');
    $router->post('/review/{id:\d+}/save', [ReviewController::class, 'save'], ['can:review.resolve', 'csrf']);
    $router->post('/review/{id:\d+}/ignore', [ReviewController::class, 'ignore'], ['can:review.resolve', 'csrf']);

    // Agreeing what kind of document this is. Its own route rather than part of
    // the save, because it is a different question: a reviewer correcting a date
    // has not thereby agreed that this is a refund rather than a credit note,
    // and Clear Books records the two in opposite directions.
    $router->post('/review/{id:\d+}/confirm-type', [ReviewController::class, 'confirmType'], ['can:review.resolve', 'csrf']);
    $router->post('/review/{id:\d+}/entity/{matchId:\d+}/pick', [ReviewController::class, 'pickEntity'], ['can:review.resolve', 'csrf']);

    // Its own capability, because creating a record in somebody's accounts is
    // a different kind of act from correcting a date on a screen.
    $router->post('/review/{id:\d+}/entity/{matchId:\d+}/create', [ReviewController::class, 'createEntity'], ['can:entities.create', 'csrf']);

    $router->post('/review/{id:\d+}/submit', [ReviewController::class, 'submit'], ['can:documents.submit', 'csrf']);

    /*
     * The Existing Invoice queue — the other flow's review screen.
     *
     * Separate from `/review` rather than another tab on it, because the two
     * screens ask different questions of different data. `/review` is a scan
     * beside an extraction with entities to resolve; a document here has no
     * extraction at all, and the question is which Clear Books record its
     * handwritten number refers to.
     *
     * Same split as `/review`: `queue.view` to look, `review.resolve` to act.
     * Deleting is its own capability — see `Auth::CAPABILITIES` — because it is
     * the one action in this application that cannot be undone.
     */
    $router->get('/existing', [ExistingInvoiceController::class, 'index'], ['can:queue.view'], 'existing');
    $router->get('/existing/{id:\d+}', [ExistingInvoiceController::class, 'show'], ['can:queue.view'], 'existing.show');
    $router->post('/existing/{id:\d+}/link', [ExistingInvoiceController::class, 'link'], ['can:review.resolve', 'csrf']);
    $router->post('/existing/{id:\d+}/new-invoice', [ExistingInvoiceController::class, 'pushToNew'], ['can:review.resolve', 'csrf']);
    $router->post('/existing/{id:\d+}/delete', [ExistingInvoiceController::class, 'delete'], ['can:documents.delete', 'csrf']);

    /*
     * The duplicate queue — the New Invoice route's own gate.
     *
     * A third queue rather than a filter on either of the other two, because it
     * asks a third question. `/review` is "is this extraction right"; `/existing`
     * is "which Clear Books record does this number point at"; this one is "is
     * this invoice one Clear Books already has, even though nobody wrote a
     * number on it". A document here has no reference to resolve and nothing
     * wrong with its extraction — it may simply already have been posted.
     *
     * The same split again: `queue.view` to look, `review.resolve` to push a
     * document on, and `documents.delete` for the one action that cannot be
     * undone.
     */
    $router->get('/duplicates', [DuplicateController::class, 'index'], ['can:queue.view'], 'duplicates');
    $router->get('/duplicates/{id:\d+}', [DuplicateController::class, 'show'], ['can:queue.view'], 'duplicates.show');
    $router->post('/duplicates/{id:\d+}/not-duplicate', [DuplicateController::class, 'notDuplicate'], ['can:review.resolve', 'csrf']);
    $router->post('/duplicates/{id:\d+}/delete', [DuplicateController::class, 'delete'], ['can:documents.delete', 'csrf']);

    // The escape hatch, and deliberately not on the ordinary path: a document
    // that has been submitted shows no submit button anywhere. Admin only, and
    // it creates a *second* record in Clear Books — the first is not withdrawn,
    // because InvoGrid has no business deleting from somebody's ledger.
    $router->post('/documents/{id:\d+}/resubmit', [DocumentController::class, 'resubmit'], ['role:admin', 'csrf']);

    /*
     * The settings themselves.
     *
     * One POST per card rather than one for the page, so a rejected Clear Books
     * address does not discard what was typed into the model boxes.
     *
     * Note that the section pattern is `[a-z_]+` and cannot match a hyphen. A
     * section named with one would need declaring above the generic route
     * below, or it would be swallowed by it.
     */
    $router->get('/admin/settings', [SettingsController::class, 'index'], ['can:settings.manage'], 'settings');

    // POST rather than GET, and not because anything is written: a model test
    // is a paid API call, and a GET is something a browser may repeat on its
    // own — a prefetch, a refresh, a link somebody bookmarked.
    $router->post('/admin/settings/test/{target:[a-z_]+}', [SettingsController::class, 'test'], ['can:settings.manage', 'csrf']);

    $router->post('/admin/settings/{section:[a-z_]+}', [SettingsController::class, 'save'], ['can:settings.manage', 'csrf']);

    /*
     * The activity log: what people did.
     *
     * `audit.view`, not `settings.manage` — they happen to be held by the same
     * role today, and they are still different questions. Read-only, with no
     * route that writes: a log a user interface can edit is not a log.
     */
    $router->get('/admin/activity', [ActivityController::class, 'index'], ['can:audit.view'], 'activity');

    /*
     * The Clear Books connection.
     *
     * Ahead of the rest of the administration screens because nothing
     * downstream works without it: until somebody has completed the consent
     * flow the cached lists are empty, every extraction prompt is handed
     * nothing to match against, and every document lands in review saying so.
     *
     * The callback is the one signed-in route without `csrf`, and deliberately
     * so: it is a redirect from Clear Books, which has no token to carry. The
     * `state` parameter checked against the session does the same job.
     */
    $router->get('/admin/clearbooks', [ClearBooksController::class, 'index'], ['can:settings.manage'], 'clearbooks');
    $router->get('/admin/clearbooks/callback', [ClearBooksController::class, 'callback'], ['can:settings.manage']);
    $router->post('/admin/clearbooks/connect', [ClearBooksController::class, 'connect'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/clearbooks/disconnect', [ClearBooksController::class, 'disconnect'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/clearbooks/refresh', [ClearBooksController::class, 'refresh'], ['can:settings.manage', 'csrf']);

    // The purchase-document sync: the local copy of what Clear Books already
    // holds, and the schedule it is fetched on. Both `settings.manage` — the
    // schedule decides how often somebody else's API is walked end to end.
    $router->post('/admin/clearbooks/sync-invoices', [ClearBooksController::class, 'syncInvoices'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/clearbooks/invoice-schedule', [ClearBooksController::class, 'invoiceSchedule'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/clearbooks/supplier-route', [ClearBooksController::class, 'supplierRoute'], ['can:settings.manage', 'csrf']);

    /*
     * Custom fields and prompts: the two things that change what the models are
     * asked, and the two that most want changing without a deploy.
     *
     * Their own capabilities rather than `settings.manage`, because they are a
     * different kind of authority — editing a prompt changes what every future
     * document is read by, which is nearer to changing the code than to setting
     * an address.
     *
     * `/admin/fields/new` is listed before the numeric form so "new" is never
     * read as an id.
     */
    $router->get('/admin/fields', [FieldController::class, 'index'], ['can:fields.manage'], 'fields');
    $router->get('/admin/fields/new', [FieldController::class, 'edit'], ['can:fields.manage']);
    $router->post('/admin/fields', [FieldController::class, 'save'], ['can:fields.manage', 'csrf']);
    $router->get('/admin/fields/{id:\d+}', [FieldController::class, 'edit'], ['can:fields.manage']);
    $router->post('/admin/fields/{id:\d+}', [FieldController::class, 'save'], ['can:fields.manage', 'csrf']);
    $router->post('/admin/fields/{id:\d+}/toggle', [FieldController::class, 'toggle'], ['can:fields.manage', 'csrf']);

    /*
     * Accounts.
     *
     * There is no sign-up route, here or anywhere: every account is created on
     * this screen or by `bin/create-admin.php`. `/admin/users/new` is listed
     * before the numeric form so "new" is never read as an id.
     */
    /*
     * The logo.
     *
     * Serving it is the open `/branding/{variant}` route above — the sign-in
     * page needs it before anybody is signed in. Replacing it is not: these are
     * `settings.manage`, and an upload endpoint is the one place a privileged
     * user can put a file of their choosing on the server.
     */
    $router->get('/admin/branding', [BrandingController::class, 'index'], ['can:settings.manage'], 'branding');
    $router->post('/admin/branding', [BrandingController::class, 'upload'], ['can:settings.manage', 'csrf']);
    $router->post('/admin/branding/{variant:light|dark}/remove', [BrandingController::class, 'remove'], ['can:settings.manage', 'csrf']);

    $router->get('/admin/users', [UserController::class, 'index'], ['can:users.manage'], 'users');
    $router->get('/admin/users/new', [UserController::class, 'edit'], ['can:users.manage']);
    $router->post('/admin/users', [UserController::class, 'save'], ['can:users.manage', 'csrf']);
    $router->get('/admin/users/{id:\d+}', [UserController::class, 'edit'], ['can:users.manage']);
    $router->post('/admin/users/{id:\d+}', [UserController::class, 'save'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/password', [UserController::class, 'password'], ['can:users.manage', 'csrf']);
    $router->post('/admin/users/{id:\d+}/toggle', [UserController::class, 'toggle'], ['can:users.manage', 'csrf']);

    /*
     * Your own password. No capability: every account has one, including the
     * viewer who can do nothing else. This is the counterweight to an
     * administrator being able to set somebody's password — without it, the
     * only way back from a password a colleague knows is asking them for
     * another one.
     */
    $router->get('/account/password', [AccountController::class, 'password'], [], 'account.password');
    $router->post('/account/password', [AccountController::class, 'updatePassword'], ['csrf']);

    $router->get('/admin/prompts', [PromptController::class, 'index'], ['can:prompts.manage'], 'prompts');
    $router->get('/admin/prompts/{key:[a-z_]+}', [PromptController::class, 'edit'], ['can:prompts.manage']);
    $router->post('/admin/prompts/{key:[a-z_]+}', [PromptController::class, 'save'], ['can:prompts.manage', 'csrf']);
    $router->post('/admin/prompts/{key:[a-z_]+}/reset', [PromptController::class, 'reset'], ['can:prompts.manage', 'csrf']);
    $router->post('/admin/prompts/{key:[a-z_]+}/activate/{id:\d+}', [PromptController::class, 'activate'], ['can:prompts.manage', 'csrf']);
});

return $router;
