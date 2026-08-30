<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Flash;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\AuditLog;
use App\Models\ClearbooksCache;
use App\Models\DocumentType;
use App\Models\Setting;
use App\Services\CacheRefresh;
use App\Services\ClearBooksClient;
use App\Services\SupplierSync;
use Throwable;

/**
 * The Clear Books connection: authorise it, see what is cached, refresh it now.
 *
 * The credentials and addresses live on the Settings screen. What is here is
 * what is not a setting: the consent flow, which nothing downstream works
 * without — until it is done the cache is empty, every extraction prompt is
 * handed empty lists, and every document lands in review saying so.
 */
final class ClearBooksController extends Controller
{
    /** Where the PKCE verifier and the state token live between the two legs. */
    private const SESSION_KEY = 'clearbooks.oauth';

    public function index(): void
    {
        $connection = ['ok' => false, 'message' => ''];

        if (ClearBooksClient::isConfigured() && ClearBooksClient::isConnected()) {
            try {
                $connection = (new ClearBooksClient())->ping();
            } catch (Throwable $e) {
                $connection = ['ok' => false, 'message' => $e->getMessage()];
            }
        }

        $this->view('admin/clearbooks', [
            'pageTitle'   => 'Clear Books',
            'configured'  => ClearBooksClient::isConfigured(),
            'connected'   => ClearBooksClient::isConnected(),
            'expiresAt'   => ClearBooksClient::expiresAt(),
            'redirectUri' => ClearBooksClient::redirectUri(),
            'businessId'  => (string) Setting::get('clearbooks_business_id', ''),
            'scopes'      => (string) Setting::get('clearbooks_scopes', ''),
            'connection'  => $connection,
            'cache'       => ClearbooksCache::summary(),

            // The per-supplier credit-note default, settable here as well as
            // from a review: a pattern somebody already knows should not have
            // to wait for a document before it can be written down.
            'suppliers'   => ClearbooksCache::all(ClearbooksCache::SUPPLIER),
            'creditTypes' => DocumentType::all(),
            'syncOn'      => Setting::bool('clearbooks_sync_correspondents', true),
            'deleteOn'    => Setting::bool('clearbooks_delete_correspondents', true),
        ]);
    }

    /**
     * Leg one of the consent flow: send the browser to Clear Books.
     *
     * The PKCE verifier and an anti-forgery `state` are generated here and kept
     * in the session, which is the only place they exist. Clear Books sees a
     * hash of the verifier and echoes the state back; a callback carrying
     * neither, or a different one, is not a callback this request started.
     */
    public function connect(): void
    {
        if (!ClearBooksClient::isConfigured()) {
            Flash::error('Add the Clear Books application credentials first — client id and client secret.');
            Response::redirect('/admin/clearbooks');
        }

        $verifier = rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
        $state    = bin2hex(random_bytes(16));

        Session::put(self::SESSION_KEY, ['verifier' => $verifier, 'state' => $state, 'at' => time()]);

        try {
            $url = (new ClearBooksClient())->authorisationUrl($state, $verifier);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/clearbooks');
        }

        AuditLog::record('clearbooks.connect_started', null, 'Sent to Clear Books to authorise InvoGrid.');

        Response::redirect($url);
    }

    /**
     * Leg two: Clear Books sends the browser back with a code.
     *
     * A GET that changes state, which is what OAuth is — so `state` does the
     * work CSRF middleware would otherwise do, and the pending request is
     * discarded whether the exchange succeeds or fails. A code is single use;
     * leaving a stale verifier in the session invites a replay.
     */
    public function callback(): void
    {
        /** @var array{verifier:string,state:string,at:int}|null $pending */
        $pending = Session::pull(self::SESSION_KEY);

        $error = (string) Request::query('error', '');

        if ($error !== '') {
            Flash::error('Clear Books did not authorise InvoGrid: ' . $error
                . ((string) Request::query('error_description', '') === ''
                    ? '' : ' — ' . (string) Request::query('error_description', '')));
            Response::redirect('/admin/clearbooks');
        }

        $code  = (string) Request::query('code', '');
        $state = (string) Request::query('state', '');

        if ($pending === null || $code === '' || !hash_equals((string) $pending['state'], $state)) {
            Flash::error(
                'That Clear Books callback did not match a request from this browser. Start the connection again.'
            );
            Response::redirect('/admin/clearbooks');
        }

        // Fifteen minutes is far longer than the flow takes and far shorter
        // than a session lasts.
        if (time() - (int) $pending['at'] > 900) {
            Flash::error('That connection attempt took too long and has expired. Start it again.');
            Response::redirect('/admin/clearbooks');
        }

        try {
            (new ClearBooksClient())->exchangeCode($code, (string) $pending['verifier']);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/clearbooks');
        }

        AuditLog::record('clearbooks.connected', null, 'Authorised by ' . Auth::displayName() . '.');
        Flash::success('Connected to Clear Books. Refresh the cache to fill the supplier and account lists.');

        Response::redirect('/admin/clearbooks');
    }

    /** Fetch the reference lists now, rather than waiting for the cron run. */
    public function refresh(): void
    {
        try {
            $tally = CacheRefresh::run();
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/clearbooks');
        }

        Flash::success('Refreshed from Clear Books. ' . self::summarise($tally));
        Response::redirect('/admin/clearbooks');
    }

    /**
     * Mirror suppliers into Paperless correspondents now.
     *
     * The dry run is the default offered on the screen, because this is the one
     * action in InvoGrid that changes somebody else's system.
     */
    public function sync(): void
    {
        $dryRun = Request::boolean('dry_run');

        try {
            $tally = SupplierSync::run($dryRun);
        } catch (Throwable $e) {
            Flash::error($e->getMessage());
            Response::redirect('/admin/clearbooks');
        }

        Flash::success(($dryRun ? 'Dry run — nothing was changed. ' : 'Correspondents synced. ')
            . SupplierSync::describe($tally));

        Response::redirect('/admin/clearbooks');
    }

    /**
     * Record what a supplier usually does when a document is not a plain bill.
     *
     * Set here rather than only on the review screen because the two are found
     * out differently. A reviewer learns the pattern *while* reviewing and ticks
     * the box there; an administrator who already knows — because they set the
     * account up, or because it came up on the telephone — should not have to
     * wait for a document to arrive before they can write it down.
     *
     * Clearing it puts the supplier back to being asked about every time, which
     * is the safe default and what an unset one means.
     */
    public function supplierRoute(): void
    {
        $remoteId = (string) Request::post('remote_id', '');
        $route    = (string) Request::post('route', '');
        $cached   = ClearbooksCache::find(ClearbooksCache::SUPPLIER, $remoteId);

        if ($cached === null) {
            Flash::error('That supplier is not in the cached list.');
            Response::redirect('/admin/clearbooks');
        }

        if ($route !== '' && !in_array($route, DocumentType::keys(false), true)) {
            Flash::error('That is not a document type InvoGrid knows about.');
            Response::redirect('/admin/clearbooks');
        }

        ClearbooksCache::setDefaultCreditRoute((int) $cached['id'], $route === '' ? null : $route);

        AuditLog::record('clearbooks.supplier_route_set', null, sprintf(
            '%s set "%s" to %s for credit documents.',
            Auth::displayName(),
            $cached['name'],
            $route === '' ? 'ask every time' : 'default to ' . DocumentType::label($route)
        ));

        Flash::success($route === ''
            ? '"' . $cached['name'] . '" will be asked about every time.'
            : '"' . $cached['name'] . '" now defaults to ' . DocumentType::label($route)
                . '. It is still a question on every document — this only changes which answer is offered first.');

        Response::redirect('/admin/clearbooks');
    }

    /** Forget the authorisation. The cached lists are left alone. */
    public function disconnect(): void
    {
        ClearBooksClient::disconnect();

        AuditLog::record('clearbooks.disconnected', null, 'Disconnected by ' . Auth::displayName() . '.');
        Flash::success('Disconnected. The cached lists are still here; nothing will be refreshed until you reconnect.');

        Response::redirect('/admin/clearbooks');
    }

    /** @param array<string,array{fetched:int,created:int,updated:int,deactivated:int}> $tally */
    private static function summarise(array $tally): string
    {
        $parts = [];

        foreach ($tally as $entityType => $counts) {
            $parts[] = sprintf(
                '%s: %d new, %d changed, %d retired',
                str_replace('_', ' ', $entityType),
                $counts['created'],
                $counts['updated'],
                $counts['deactivated']
            );
        }

        return implode('. ', $parts) . '.';
    }
}
