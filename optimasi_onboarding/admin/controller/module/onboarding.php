<?php
/**
 * Optimasi Content Audit — module controller.
 *
 * A free, four-step, read-only content review that a merchant runs before buying
 * anything:
 *
 *   1. Scan           measure up to 1,000 published products in the admin PHP.
 *                     Nothing leaves the store.
 *   2. Brand voice    send a short sample of each description to the Optimasi
 *                     review endpoint. Gated on explicit consent.
 *   3. Report         fetch the server-rendered four-page Sales-Ready report.
 *   4. How it works   an outbound link. No request at all.
 *
 * Plus an email card and the "How Optimasi can help" offer, both revealed with
 * the report because both are about the report.
 *
 * Nothing is written to the store: no tables, no events, no catalogue code, no
 * product data. The only state kept is four settings of our own (the endpoint,
 * the remembered consent, the last report token, and the enabled flag the
 * extension list reads).
 *
 * Every outbound call is made here, server-side. The browser never holds the
 * endpoint, and there is no CORS surface. All scoring happens on Optimasi's
 * servers: no weights, thresholds or heuristics ship in this extension.
 */

namespace Opencart\Admin\Controller\Extension\OptimasiOnboarding\Module;

class Onboarding extends \Opencart\System\Engine\Controller {

    /**
     * Extension version. Keep in sync with install.json "version" — the release
     * tooling moves both together. It is also the asset cache-buster, so a release
     * always ships fresh CSS and JS.
     */
    public const VERSION = '1.0.6';

    /** The extension code, which is also the zip filename and the route segment. */
    private const CODE = 'optimasi_onboarding';

    /** This controller's route, used for permission checks and AJAX links. */
    private const ROUTE = 'extension/optimasi_onboarding/module/onboarding';

    /** Optimasi's own pages, named once so no template hard-codes a URL. */
    public const SERVICE_URL = 'https://optimasi.io';
    public const TERMS_URL   = 'https://optimasi.io/terms';
    public const PRIVACY_URL = 'https://optimasi.io/privacy';

    /**
     * The review service used when the store has configured nothing.
     *
     * A shipped default rather than an empty field: without one every server-side
     * step fails with "Could not reach the Optimasi service", which reads as a
     * transport fault when the truth is that nothing was configured. An explicit
     * endpoint still wins — the advanced field exists for test and agency stores.
     *
     * Deliberately the staging host: the long-term backend domain is not decided
     * (2026-11-03) and marketing already runs on this instance.
     */
    public const DEFAULT_API_URL = 'https://staging.optimasi.dev';

    /**
     * Step 4 links out rather than fetching anything. One canonical page beats a
     * copy of it that would drift.
     */
    public const HOW_IT_WORKS_URL = 'https://optimasi.io/how-it-works/';

    /**
     * Preferred-channel contact options. The KEYS are the backend's contract
     * (`_CONTACT_CHANNELS` in the API) — a value added or changed here without
     * the same change there fails validation at the server.
     */
    private const CONTACT_CHANNELS = ['google_meet', 'ms_teams', 'whatsapp', 'viber', 'email'];

    /** Channels where a phone number is needed to actually reach the merchant. */
    private const CONTACT_NUMBER_CHANNELS = ['whatsapp', 'viber'];

    private const CONTACT_ROLES = ['agency', 'store_owner', 'ecommerce_professional', 'store_manager'];

    /** Scan budget: measure up to this many products… */
    private const SCAN_CAP = 1000;

    /** …and send text for this many, spread evenly across the catalogue. */
    private const VOICE_SAMPLE = 50;

    // ------------------------------------------------------------------
    // Settings keys. All four live in OpenCart's own `setting` table.
    // ------------------------------------------------------------------

    private const SETTING_CODE = 'module_optimasi_onboarding';

    private const KEY_STATUS  = 'module_optimasi_onboarding_status';
    private const KEY_API_URL = 'module_optimasi_onboarding_api_url';
    private const KEY_CONSENT = 'module_optimasi_onboarding_consent_send_samples';
    private const KEY_TOKEN   = 'module_optimasi_onboarding_report_token';
    // When the stored report was produced, so step 3 can say which review it came from.
    // A token without a date is what made a stored report indistinguishable from a
    // fresh one; a token written by a version that did not store this reads as "an
    // earlier review" rather than inventing a date it does not have.
    private const KEY_REPORT_AT = 'module_optimasi_onboarding_report_at';

    // ------------------------------------------------------------------
    // Page
    // ------------------------------------------------------------------

    /**
     * The four-step page.
     */
    public function index(): void {
        if (!$this->user->hasPermission('access', self::ROUTE)) {
            return;
        }

        $this->load->language('extension/optimasi_onboarding/module/onboarding');

        $this->document->setTitle($this->language->get('heading_title'));

        $token = $this->userToken();

        $data['breadcrumbs'] = [];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_home'),
            'href' => $this->url->link('common/dashboard', 'user_token=' . $token),
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('text_extension'),
            'href' => $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module'),
        ];

        $data['breadcrumbs'][] = [
            'text' => $this->language->get('heading_title'),
            'href' => $this->url->link(self::ROUTE, 'user_token=' . $token),
        ];

        $data['back'] = $this->url->link('marketplace/extension', 'user_token=' . $token . '&type=module');

        // One URL per action, built here rather than assembled in the browser:
        // the user_token has to land in the query string before the route's
        // action suffix, and string surgery in JS is how that gets broken.
        //
        // The keys are the action names verbatim. An alias table was considered and
        // rejected: a second vocabulary is one more thing that can disagree with the
        // script, and a disagreement here is silent (the step just says it is
        // unavailable). `tests/action_contract_test.php` asserts the two agree.
        $data['ajax'] = [];

        foreach (['scan', 'voice', 'findings', 'explainers', 'report', 'snapshot', 'email_report', 'contact', 'save'] as $action) {
            $data['ajax'][$action] = $this->jsUrl(self::ROUTE . '.' . $action, 'user_token=' . $token);
        }

        // The report is served by this extension for "Open in a new tab", rather
        // than re-wrapped in a blob in the browser — see report_html().
        $data['report_html_url'] = $this->jsUrl(self::ROUTE . '.report_html', 'user_token=' . $token);

        // The offer's channel and role lists, labels from the language file so a
        // translator can reach every string the page shows.
        $data['channels'] = [];

        foreach (self::CONTACT_CHANNELS as $index => $channel) {
            $data['channels'][] = [
                'value'   => $channel,
                'label'   => $this->language->get('text_channel_' . $channel),
                'checked' => $index === 0,
            ];
        }

        $data['roles'] = [];

        foreach (self::CONTACT_ROLES as $role) {
            $data['roles'][] = ['value' => $role, 'label' => $this->language->get('text_role_' . $role)];
        }

        $data['consent_recorded']  = $this->consentRecorded();
        $data['report_previous']   = $this->reportStateForJs();

        // Escaped here as well as in the template. The endpoint is admin-supplied,
        // and an unescaped attribute break in a template whose autoescape is off is
        // a stored XSS — one an admin with only this module's permission could plant
        // for the next person to open the page.
        $data['api_url']           = $this->esc((string)$this->config->get(self::KEY_API_URL));
        $data['default_api_url']   = self::DEFAULT_API_URL;
        $data['show_advanced']     = $this->showAdvancedPanel();
        $data['how_it_works_url']  = self::HOW_IT_WORKS_URL;
        $data['version']           = self::VERSION;

        // Named distinctly from the language keys they are built from. OpenCart
        // merges the language array into the template's variables, and which of the
        // two wins is an implementation detail we should not depend on — a raw '%s'
        // on the page is the kind of thing nobody notices in review.
        $data['settings_help_text'] = sprintf(
            $this->language->get('text_settings_help'),
            self::DEFAULT_API_URL
        );

        $data['help_privacy_html'] = sprintf(
            $this->language->get('text_help_privacy'),
            '<a href="' . $this->esc(self::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">'
                . $this->language->get('text_help_privacy_link') . '</a>'
        );

        // A one-line health read. Cheap, and it is how an admin answers "why does
        // step 2 not work" without opening the journal. Escaped for the same reason
        // as api_url above.
        $data['health'] = [
            'endpoint' => $this->esc($this->apiBaseUrl()),
            'endpoint_is_default' => (string)$this->config->get(self::KEY_API_URL) === '',
            'consent'  => $this->consentRecorded(),
            'token'    => (string)$this->config->get(self::KEY_TOKEN) !== '',
        ];

        $this->document->addStyle($this->asset('stylesheet/onboarding.css'));
        $this->document->addScript($this->asset('javascript/onboarding.js'));

        $data['header']      = $this->load->controller('common/header');
        $data['column_left'] = $this->load->controller('common/column_left');
        $data['footer']      = $this->load->controller('common/footer');

        $this->response->setOutput($this->load->view('extension/optimasi_onboarding/module/onboarding', $data));
    }

    // ------------------------------------------------------------------
    // Step 1 — scan (local; nothing leaves the store)
    // ------------------------------------------------------------------

    /**
     * Measure the catalogue.
     *
     * The whole payload is returned to the browser so step 1 can render, and so
     * the later steps can assemble the report from the same numbers. Nothing is
     * sent off-site from here.
     */
    public function scan(): void {
        if (!$this->guard()) {
            return;
        }

        $this->extendTimeLimit();

        // `load->model()` returns VOID on OpenCart 4.1 — it returned the object on 4.0 —
        // and registers it on the registry instead. The registry property is therefore
        // the only form that works on both; reading the return value is what produced
        // "Call to a member function scan() on null" on 4.1.
        $this->load->model('extension/optimasi_onboarding/module/onboarding');

        try {
            $result = $this->model_extension_optimasi_onboarding_module_onboarding->scan($this->languageId(), self::SCAN_CAP);
        } catch (\Exception $e) {
            // A failing QUERY arrives as an Exception from OpenCart's driver, and this
            // names it: the reason used to reach only the PHP log, while the page said
            // "Server communication failed (HTTP 500)".
            //
            // \Exception, not \Throwable: an \Error here is a defect in this file —
            // calling a method on a model that was never loaded, for instance — and
            // swallowing that as a data failure is how a code bug ships looking like an
            // environment problem. It is left to propagate.
            $this->fail($this->cleanText($e->getMessage(), 500), 'scan_failed');

            return;
        }

        $this->ok($result);
    }

    // ------------------------------------------------------------------
    // Step 2 — brand voice (the only step that sends description text)
    // ------------------------------------------------------------------

    /**
     * Send the description sample for analysis.
     *
     * This is the one step that ships product text off the store, so it is the
     * one step that requires consent. The checkbox records it and it is
     * remembered, so the merchant answers once rather than on every run — and an
     * unticked box is refused here, on the server, not merely in the browser.
     */
    public function voice(): void {
        if (!$this->guard()) {
            return;
        }

        if (!$this->requireConsent()) {
            return;
        }

        $this->extendTimeLimit();

        $this->load->model('extension/optimasi_onboarding/module/onboarding');

        $scan = $this->model_extension_optimasi_onboarding_module_onboarding->scan($this->languageId(), self::SCAN_CAP, true, self::VOICE_SAMPLE);

        // Brand voice is a statistical read of the descriptions, so only products
        // that actually carry text are worth sending. A tiny catalogue whose
        // products all have empty descriptions falls back to what we have, which
        // lets the server report the (real) finding that there is nothing to read.
        $products = [];

        foreach ($scan['products'] as $product) {
            if ((string)($product['original_long'] ?? '') !== '' || (string)($product['original_short'] ?? '') !== '') {
                $products[] = $product;
            }
        }

        if (!$products) {
            $products = $scan['products'];
        }

        $response = $this->api('POST', '/api/v1/preview/analyse', [
            'site_url' => $this->siteUrl(),
            'consent'  => ['send_description_samples' => true],
            'products' => $products,
        ], 30);

        $this->relay($response, $this->language->get('error_analyse_failed'), 'analyse_failed', 'result');
    }

    // ------------------------------------------------------------------
    // Step-1 enrichment: per-block explainer cards
    // ------------------------------------------------------------------

    /**
     * The shared "What this means" cards, with their cited industry stats.
     *
     * Relays the aggregate per-block coverage the scan already measured — counts
     * only, no description text — and renders what comes back verbatim, so the
     * on-screen cards and the PDF report cannot drift apart. Best-effort: if the
     * service is unreachable the local summary stays on screen.
     */
    public function explainers(): void {
        if (!$this->guard()) {
            return;
        }

        $coverage = $this->jsonField('coverage');

        $response = $this->api('POST', '/api/v1/preview/explainers', [
            'site_url'      => $this->siteUrl(),
            'features'      => $this->sanitiseFeatures($coverage['features'] ?? null),
            'scanned_total' => (int)($coverage['scanned_total'] ?? 0),
            'completeness'  => (int)($coverage['completeness'] ?? 0),
            'authority'     => $this->sanitiseAuthority($coverage['authority'] ?? null),
        ], 20);

        $this->relay($response, $this->language->get('error_explainers_failed'), 'explainers_failed', 'explainers');
    }

    // ------------------------------------------------------------------
    // Step-2 capstone: the plain-language audit findings
    // ------------------------------------------------------------------

    /**
     * The written read of the scan.
     *
     * Bounded aggregates only — the numbers the scan already measured — so this
     * pass sends nothing a shopper wrote. Generated and cached server-side.
     */
    public function findings(): void {
        if (!$this->guard()) {
            return;
        }

        $facts = $this->jsonField('facts');

        $response = $this->api('POST', '/api/v1/preview/findings', [
            'site_url'      => $this->siteUrl(),
            'scanned_total' => (int)($facts['scanned_total'] ?? 0),
            'completeness'  => (int)($facts['completeness'] ?? 0),
            'features'      => $this->mapBlock($facts['features'] ?? null),
            'health'        => $this->mapBlock($facts['health'] ?? null),
            'categories'    => $this->mapBlock($facts['categories'] ?? null),
            'worst'         => $this->mapBlock($facts['worst'] ?? null),
            'brand_voice'   => $this->mapBlock($facts['brand_voice'] ?? null),
            'duplicates'    => $this->mapBlock($facts['duplicates'] ?? null),
            'ai_readiness'  => $this->mapBlock($facts['ai_readiness'] ?? null),
        ], 45);

        $this->relay($response, $this->language->get('error_findings_failed'), 'findings_failed', 'text');
    }

    // ------------------------------------------------------------------
    // Step 3 — the report
    // ------------------------------------------------------------------

    /**
     * Store the review so the report can be rendered and re-opened by reference.
     *
     * Called once per run, when step 2 completes and before any email is
     * collected — so marketing has the report even if the merchant never gives an
     * address. Best-effort: a failure here must not block the page, because the
     * email card can still create the request itself.
     */
    public function snapshot(): void {
        if (!$this->guard()) {
            return;
        }

        $this->extendTimeLimit();

        $response = $this->api('POST', '/api/v1/preview/report/snapshot', [
            'site_url' => $this->siteUrl(),
            'platform' => 'opencart4',
            'report'   => $this->reportPayload(),
        ], 30);

        if ($response['error'] !== '' || (int)$response['code'] !== 200 || empty($response['data']['ok'])) {
            // Deliberately quiet: the review UI keeps working, and step 3 will
            // report that the report could not be loaded if nothing was stored.
            return;
        }

        if (!empty($response['data']['report_token'])) {
            $this->persistSetting(self::KEY_TOKEN, $this->cleanToken((string)$response['data']['report_token']));
            // The date is written WITH the token, in the same request, so the two cannot
            // disagree. A report whose date is missing is reported as "an earlier
            // review" rather than being shown as if it were produced just now.
            $this->persistSetting(self::KEY_REPORT_AT, time());
        }

        // Only the request id goes back to the browser. The report token is a
        // capability: it stays in the setting table, because a value in the page is
        // a value any script on that page can read and replay. Every later step falls
        // back to the stored token, so nothing needs it here.
        $this->ok([
            'ok'         => true,
            'request_id' => (string)($response['data']['request_id'] ?? ''),
        ]);
    }

    /**
     * Fetch the rendered four-page report.
     *
     * The token stays server-side — the browser only ever receives the rendered
     * document. Rendering happens on Optimasi's servers from the stored snapshot,
     * so the on-screen report and the emailed PDF are the same document.
     */
    public function report(): void {
        if (!$this->guard()) {
            return;
        }

        $this->extendTimeLimit();

        // The stored token is the only authority. A token accepted from the request
        // would make this action a proxy for any report the caller can name, and there
        // is no UI that needs it — the page never has a token to send.
        $token = $this->cleanToken((string)$this->config->get(self::KEY_TOKEN));

        if ($token === '') {
            $this->fail($this->language->get('error_no_report'), 'no_report');

            return;
        }

        $template = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($this->request->post['template'] ?? '')));

        if ($template === '') {
            $template = 'marketing';
        }

        $response = $this->api(
            'GET',
            '/api/v1/preview/report/view?token=' . rawurlencode($token) . '&template=' . rawurlencode($template),
            [],
            25
        );

        if ($response['error'] !== '') {
            $this->fail($this->language->get('error_unreachable'), 'unreachable');

            return;
        }

        if ((int)$response['code'] !== 200 || $response['raw'] === '') {
            $this->fail($this->language->get('error_report_unavailable'), 'report_unavailable');

            return;
        }

        $this->ok(['html' => $response['raw'], 'template' => $template]);
    }

    /**
     * The report as a standalone document, for "Open in a new tab".
     *
     * Deliberately NOT the browser wrapping the HTML in a blob URL and opening it:
     * a blob inherits this page's origin, so any script in the report would run with
     * the store's admin session — the exact thing the `sandbox` on the embedded frame
     * exists to prevent, and the blob path ignores it.
     *
     * Serving the document with `Content-Security-Policy: sandbox` puts it on an
     * opaque origin instead: it renders and prints, and its scripts do not run.
     */
    public function report_html(): void {
        $this->load->language('extension/optimasi_onboarding/module/onboarding');

        if (!$this->user->hasPermission('access', self::ROUTE)) {
            $this->response->addHeader('HTTP/1.1 403 Forbidden');
            $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
            $this->response->setOutput('Not permitted.');

            return;
        }

        $token = $this->cleanToken((string)$this->config->get(self::KEY_TOKEN));

        if ($token === '') {
            $this->plain('No report is available yet — run the review first.');

            return;
        }

        $response = $this->api(
            'GET',
            '/api/v1/preview/report/view?token=' . rawurlencode($token) . '&template=marketing',
            [],
            25
        );

        if ($response['error'] !== '' || (int)$response['code'] !== 200 || $response['raw'] === '') {
            $this->plain('The report could not be loaded right now.');

            return;
        }

        $this->response->addHeader('Content-Type: text/html; charset=utf-8');
        // No `allow-same-origin`, no `allow-scripts`: an opaque origin that renders.
        $this->response->addHeader('Content-Security-Policy: sandbox; default-src \'none\'; style-src \'unsafe-inline\'; img-src data:; font-src data:');
        $this->response->addHeader('X-Content-Type-Options: nosniff');
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $this->response->addHeader('Cache-Control: no-store');
        $this->response->setOutput($response['raw']);
    }

    // ------------------------------------------------------------------
    // Email card and offer panel
    // ------------------------------------------------------------------

    /**
     * Ask for the report to be emailed.
     *
     * Relays the assembled snapshot plus the address. When the email-less capture
     * already created the lead, `request_id` attaches the address to that same
     * record instead of creating a duplicate.
     */
    public function email_report(): void {
        if (!$this->guard()) {
            return;
        }

        $this->extendTimeLimit();

        $email = $this->cleanEmail((string)($this->request->post['email'] ?? ''));

        if ($email === '') {
            $this->fail($this->language->get('error_invalid_email'), 'invalid_email');

            return;
        }

        $body = [
            'site_url' => $this->siteUrl(),
            'email'    => $email,
            'platform' => 'opencart4',
            'report'   => $this->reportPayload(),
        ];

        $request_id = $this->cleanToken((string)($this->request->post['request_id'] ?? ''));

        if ($request_id !== '') {
            $body['request_id'] = $request_id;
        }

        $response = $this->api('POST', '/api/v1/preview/email-report', $body, 20);

        $this->relay($response, $this->language->get('error_email_failed'), 'email_report_failed');
    }

    /**
     * Record a preferred contact channel on this store's lead.
     *
     * Mirrors the backend's `/api/v1/preview/report/contact` contract, and
     * validates locally first so the merchant reads a useful message instead of a
     * round-trip. The same record is used by the emailed contact page, so a
     * choice made here appears in the same sales-contact list.
     */
    public function contact(): void {
        if (!$this->guard()) {
            return;
        }

        $this->extendTimeLimit();

        // The stored token is the only authority here too — see report().
        $token = $this->cleanToken((string)$this->config->get(self::KEY_TOKEN));

        if ($token === '') {
            $this->fail($this->language->get('error_contact_no_report'), 'no_report');

            return;
        }

        $channel = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($this->request->post['channel'] ?? '')));

        if (!in_array($channel, self::CONTACT_CHANNELS, true)) {
            $this->fail($this->language->get('error_invalid_channel'), 'invalid_channel');

            return;
        }

        $number = $this->cleanText((string)($this->request->post['number'] ?? ''), 40);

        if (in_array($channel, self::CONTACT_NUMBER_CHANNELS, true)
            && strlen((string)preg_replace('/\D+/', '', $number)) < 6) {
            $this->fail($this->language->get('error_number_required'), 'number_required');

            return;
        }

        $role = preg_replace('/[^a-z0-9_]/', '', strtolower((string)($this->request->post['role'] ?? '')));

        if (!in_array($role, self::CONTACT_ROLES, true)) {
            $role = '';
        }

        $email = $this->cleanEmail((string)($this->request->post['email'] ?? ''));

        if ($email === '' && trim((string)($this->request->post['email'] ?? '')) !== '') {
            $this->fail($this->language->get('error_invalid_email'), 'invalid_email');

            return;
        }

        $response = $this->api('POST', '/api/v1/preview/report/contact', [
            'token'   => $token,
            'channel' => $channel,
            'role'    => $role,
            'number'  => $number,
            'email'   => $email,
        ], 20);

        if ($response['error'] !== '' || (int)$response['code'] !== 200 || empty($response['data']['ok'])) {
            $message = (string)($response['data']['detail'] ?? '');

            $this->fail($message !== '' ? $message : $this->language->get('error_contact_failed'), 'contact_failed');

            return;
        }

        $this->ok([
            'channel'       => $channel,
            'channel_label' => $this->language->get('text_channel_' . $channel),
        ]);
    }

    // ------------------------------------------------------------------
    // Settings
    // ------------------------------------------------------------------

    /**
     * Save the review service endpoint.
     *
     * An empty value clears it so the shipped default is used again. Only https
     * is accepted: every request to this endpoint carries a merchant's product
     * descriptions, and coercing a typed `http://` into `https://` would silently
     * send them in clear. The value is left exactly as the admin typed it when it
     * is rejected, so the message quotes their input.
     */
    public function save(): void {
        if (!$this->guard()) {
            return;
        }

        $raw = trim((string)($this->request->post['api_url'] ?? ''));

        if ($raw === '') {
            $this->persistSetting(self::KEY_API_URL, '');

            $this->ok(['message' => $this->language->get('text_endpoint_default'), 'url' => '']);

            return;
        }

        $url = $this->normaliseEndpoint($raw);

        if ($url === null) {
            $this->fail($this->language->get('error_invalid_url'), 'invalid_url');

            return;
        }

        $this->persistSetting(self::KEY_API_URL, $url);

        $this->ok(['message' => $this->language->get('text_endpoint_saved'), 'url' => $url]);
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Install: default the module to enabled.
     *
     * Nothing else to create — this extension has no tables and registers no
     * events, so there is no Repair path and no way for an update to leave it
     * inert. That is the whole reason it is admin-only.
     */
    public function install(): void {
        // Gated on `extension/module`, the route OpenCart's own installer validated
        // before calling this method: it checks modify on that route, then grants this
        // module's own access and modify. The gate stays because this action is also
        // reachable directly by any admin session (`.../module/onboarding.install`),
        // but the ROUTE was wrong — `marketplace/extension` is a different page, and a
        // group holding the module list without that page got an install that silently
        // wrote nothing while OpenCart reported success.
        if (!$this->user->hasPermission('modify', 'extension/module')) {
            return;
        }

        $this->writeSettings([
            self::KEY_STATUS  => 1,
            self::KEY_API_URL => '',
            self::KEY_CONSENT => 0,
            self::KEY_TOKEN   => '',
            // Written on install even though it starts empty: every other path writes
            // the full set of keys, and a key that exists in only some of them is how
            // one of these gets silently dropped the next time another is saved.
            self::KEY_REPORT_AT => 0,
        ]);
    }

    /**
     * Uninstall: remove our own settings and nothing else.
     *
     * There is no data to warn about — the extension never wrote any — and it
     * deliberately does not touch the Optimasi Content Manager's tables, which
     * belong to a different product.
     */
    public function uninstall(): void {
        // Same gate as install(), and for the same reason: this action DELETEs the
        // module's settings, and it must not be reachable by a session that merely
        // knows its own user_token. `extension/module` is the route the installer
        // itself requires for this action.
        if (!$this->user->hasPermission('modify', 'extension/module')) {
            return;
        }

        // Removed directly rather than through the setting model's delete method:
        // a DELETE scoped to our own code is one statement with no behaviour to
        // verify, and it cannot take another extension's rows with it.
        $this->db->query(
            "DELETE FROM `" . DB_PREFIX . "setting` WHERE `code` = '" . $this->db->escape(self::SETTING_CODE) . "'"
        );
    }

    // ------------------------------------------------------------------
    // Consent
    // ------------------------------------------------------------------

    /**
     * Record consent, or refuse the request.
     *
     * Returns true when consent stands (given now, or given on an earlier run —
     * the merchant answers once). When it does not, the caller has already been
     * sent the refusal and must stop.
     */
    private function requireConsent(): bool {
        if ((string)($this->request->post['consent'] ?? '') === '1') {
            $this->persistSetting(self::KEY_CONSENT, 1);

            return true;
        }

        if ($this->consentRecorded()) {
            return true;
        }

        $this->fail($this->language->get('error_consent_required'), 'consent_required');

        return false;
    }

    private function consentRecorded(): bool {
        return (int)$this->config->get(self::KEY_CONSENT) === 1;
    }

    /**
     * What the page should say about the stored report, and how to phrase it.
     *
     * The copy lives in the language file so it is translatable; the date is
     * formatted here. `label` is empty for a token written by a version that did not
     * store its date, and the page then says "an earlier review" rather than
     * inventing one.
     *
     * `config_date_format` is deliberately NOT used: in OpenCart it is a per-language
     * display preference for order dates, and borrowing it here would make the audit's
     * own wording change with an unrelated setting.
     *
     * @return array{has: bool, label: string, with_date: string, without_date: string}
     */
    private function reportStateForJs(): array
    {
        $token = (string)$this->config->get(self::KEY_TOKEN);
        $at    = (int)$this->config->get(self::KEY_REPORT_AT);

        return [
            'has'          => $token !== '',
            'label'        => ($token !== '' && $at > 0) ? date('j F Y', $at) : '',
            'with_date'    => $this->language->get('report_previous_with_date'),
            'without_date' => $this->language->get('report_previous_without_date'),
        ];
    }

    /**
     * Write one of our settings without disturbing the other four.
     *
     * Deliberately NOT `model_setting_setting->editSetting()`, for two reasons:
     *
     *  - it DELETEs every row for the code and then re-inserts, so a failure part-way
     *    through leaves the module holding some of its settings and not others — and
     *    the delete is the dangerous half, because losing consent means asking the
     *    merchant to agree a second time;
     *  - on OpenCart 4.1 its non-array branch writes `serialized` as
     *    `(string)(bool) false`, which is an EMPTY STRING rather than '0'. A
     *    strict-mode MySQL rejects that for the column, so the write throws — inside
     *    install(), that is a 500 after OpenCart has already recorded the extension as
     *    installed, which is exactly the "spinner never finishes, but it is installed"
     *    shape.
     *
     * A per-key upsert has neither property: a key written twice is written once, and a
     * rejected key does not take its siblings with it. The Content Manager writes its
     * settings the same way.
     */
    private function writeSettings(array $values): void {
        foreach ($values as $key => $value) {
            $key      = (string)$key;
            $existing = $this->db->query(
                "SELECT `setting_id` FROM `" . DB_PREFIX . "setting`"
                . " WHERE `store_id` = '0' AND `code` = '" . $this->db->escape(self::SETTING_CODE) . "'"
                . " AND `key` = '" . $this->db->escape($key) . "'"
            );

            if ($existing->num_rows) {
                $this->db->query(
                    "UPDATE `" . DB_PREFIX . "setting` SET `value` = '" . $this->db->escape((string)$value) . "',"
                    . " `serialized` = '0' WHERE `store_id` = '0' AND `code` = '" . $this->db->escape(self::SETTING_CODE) . "'"
                    . " AND `key` = '" . $this->db->escape($key) . "'"
                );
            } else {
                $this->db->query(
                    "INSERT INTO `" . DB_PREFIX . "setting` SET `store_id` = '0',"
                    . " `code` = '" . $this->db->escape(self::SETTING_CODE) . "',"
                    . " `key` = '" . $this->db->escape($key) . "',"
                    . " `value` = '" . $this->db->escape((string)$value) . "', `serialized` = '0'"
                );
            }

            // `$this->config` was populated before this request ran, so without this
            // the rest of the call would still read the old value.
            $this->config->set($key, $value);
        }
    }

    /**
     * Write one key while leaving its siblings as they are.
     *
     * The full set is written every time. Not because a single key would disturb the
     * others — writeSettings() cannot — but because the values come from `config`, and
     * writing only the key that changed would let a row that is missing (a partial
     * install, a hand-edited database) stay missing forever.
     */
    private function persistSetting(string $key, $value): void {
        $values = [
            self::KEY_STATUS    => (int)$this->config->get(self::KEY_STATUS),
            self::KEY_API_URL   => (string)$this->config->get(self::KEY_API_URL),
            self::KEY_CONSENT   => (int)$this->config->get(self::KEY_CONSENT),
            self::KEY_TOKEN     => (string)$this->config->get(self::KEY_TOKEN),
            self::KEY_REPORT_AT => (int)$this->config->get(self::KEY_REPORT_AT),
        ];

        $values[$key] = $value;

        $this->writeSettings($values);
    }

    // ------------------------------------------------------------------
    // Optimasi API client
    // ------------------------------------------------------------------

    /**
     * The endpoint the review runs against.
     *
     * Configured value first, shipped default last — so an explicit setting
     * always wins and an unconfigured store still works.
     */
    private function apiBaseUrl(): string {
        $configured = $this->normaliseEndpoint((string)$this->config->get(self::KEY_API_URL));

        return $configured ?? self::DEFAULT_API_URL;
    }

    /**
     * Validate an endpoint, returning the normalised value or null.
     *
     * https only, a host is required, and no credentials in the URL: a password in a
     * URL leaks through error messages, screenshots and the setting table.
     *
     * Quotes, angle brackets, backslashes and whitespace are refused too. The value
     * is echoed back into an HTML attribute on the settings page, and a template
     * whose autoescape is off would turn `https://x/" onfocus="…` into a stored XSS:
     * an admin holding only this module's permission could plant script for the next
     * person to open the page. Nothing an endpoint legitimately contains needs those
     * characters, so refusing them costs nothing.
     */
    private function normaliseEndpoint(string $url): ?string {
        $url = trim($url);

        if ($url === '' || !preg_match('#^https://#i', $url)) {
            return null;
        }

        if (preg_match('/[\s"\'<>\\`]/', $url)) {
            return null;
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['host'])) {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return rtrim($url, '/');
    }

    /**
     * One outbound request.
     *
     * Returns `code` (0 for a transport failure), the decoded `data` when the
     * body is JSON, the `raw` body, and an `error` string for transport faults.
     * Redirects are not followed: this endpoint is a fixed host we control, and
     * following one would let a wrong setting send product text somewhere else.
     *
     * Protected rather than private so the consent test can stand in for it: what
     * that test has to prove is that nothing leaves the store without consent, and
     * the only way to prove "nothing left" is to watch the one place a request is
     * made. A real socket would make the suite depend on the network and on a
     * server we do not control.
     *
     * @return array{code:int,data:array,raw:string,error:string}
     */
    protected function api(string $method, string $path, array $body = [], int $timeout = 10): array {
        $base = $this->apiBaseUrl();

        if (!function_exists('curl_init')) {
            return ['code' => 0, 'data' => [], 'raw' => '', 'error' => $this->language->get('error_no_curl')];
        }

        $url = $base . $path;

        $ch = curl_init();

        $options = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS      => 0,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'OptimasiContentAudit/' . self::VERSION . ' (OpenCart ' . (defined('VERSION') ? VERSION : '') . ')',
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        ];

        if (strtoupper($method) === 'POST') {
            $options[CURLOPT_POST]       = true;
            $options[CURLOPT_POSTFIELDS] = (string)json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        }

        curl_setopt_array($ch, $options);

        $raw   = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            // The ROUTE is logged, never the query string: the report path carries
            // the report token, and a transport blip is exactly when that would be
            // written to a log file somebody else can read. The body is never logged
            // either — it is the merchant's product text.
            $route = (string)parse_url($path, PHP_URL_PATH);

            $this->log->write('Optimasi Content Audit: ' . strtoupper($method) . ' ' . $route . ' failed: ' . $error);

            return ['code' => 0, 'data' => [], 'raw' => '', 'error' => $error !== '' ? $error : 'transport'];
        }

        $decoded = json_decode((string)$raw, true, 64);

        return [
            'code'  => $code,
            'data'  => is_array($decoded) ? $decoded : [],
            'raw'   => (string)$raw,
            'error' => '',
        ];
    }

    /**
     * Turn an API response into the page's answer, echoing the service's own
     * message when it has one.
     *
     * The service's `detail`/`message` is shown to the admin because it is more
     * useful than anything we could invent, and it is the only way a validation
     * complaint reaches them. It is plain text out of JSON, never rendered as
     * markup by the script.
     *
     * @param array  $response Result of api().
     * @param string $fallback Message to use when the service gave none.
     * @param string $error    Machine-readable code for the browser.
     * @param string $ok_key   Field that must be present for the call to count as
     *                         a success ('' accepts any 200).
     */
    private function relay(array $response, string $fallback, string $error, string $ok_key = ''): void {
        if ($response['error'] !== '') {
            $this->fail($this->language->get('error_unreachable'), 'unreachable');

            return;
        }

        $data  = $response['data'];
        $ok    = (int)$response['code'] === 200 && ($ok_key === '' ? !empty($data['ok']) : !empty($data[$ok_key]));

        if ($ok) {
            $this->ok($data);

            return;
        }

        $message = '';

        foreach (['message', 'detail', 'error'] as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $message = $data[$field];

                break;
            }
        }

        if ((int)$response['code'] !== 200 && (int)$response['code'] !== 0) {
            $message = trim($message . ' (Optimasi API HTTP ' . (int)$response['code'] . ')');
        }

        $this->fail($message !== '' ? $message : $fallback, $error);
    }

    // ------------------------------------------------------------------
    // Request/response plumbing
    // ------------------------------------------------------------------

    /**
     * Shared gate for every JSON action: permission, language, and a JSON
     * response either way.
     */
    private function guard(): bool {
        $this->load->language('extension/optimasi_onboarding/module/onboarding');

        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            $this->fail($this->language->get('error_permission'), 'permission');

            return false;
        }

        return true;
    }

    /**
     * Success, in the one envelope the page's script understands.
     *
     * Deliberately HTTP 200 for successes and failures alike. A failed step is a
     * normal part of this flow (no consent yet, no report yet, service down), and
     * carrying it as an HTTP error as well would mean every failure has two
     * shapes to handle in the browser and two to test. The `error` code is what
     * distinguishes them, and it is stable.
     */
    private function ok(array $data): void {
        $this->json(['success' => true, 'data' => $data]);
    }

    private function fail(string $message, string $error): void {
        $this->json(['success' => false, 'data' => ['message' => $message, 'error' => $error]]);
    }

    /**
     * Answer an action, as the one envelope the page's script understands.
     *
     * The hardening is not decoration. A warning, notice or deprecation echoed by
     * anything earlier in the request -- this extension, OpenCart core, or a host's
     * prepend file -- lands in FRONT of this body, and the browser's `dataType: 'json'`
     * then reports the whole exchange as "Server communication failed (HTTP 200)": a
     * failure carrying neither a status nor a cause. Diagnostics go to the log instead,
     * which is where they belong for a machine-read response.
     */
    private function json(array $payload): void {
        ini_set('display_errors', '0');

        // Anything already echoed is removed where a buffer exists; where it does not,
        // the output has already gone out and no PHP setting can retract it.
        if (!headers_sent() && ob_get_level() > 0) {
            @ob_clean();
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            // An empty body is indistinguishable, in the browser, from a request that
            // never came back -- so say what went wrong in the same envelope instead.
            $body = json_encode([
                'success' => false,
                'data'    => [
                    'message' => 'The response could not be encoded: ' . json_last_error_msg(),
                    'error'   => 'encode_failed',
                ],
            ]);
        }

        $this->response->addHeader('Content-Type: application/json');
        $this->response->setOutput((string)$body);
    }

    /**
     * The report body the browser assembled, decoded, bounded and scrubbed.
     *
     * Two things happen here that are not about size:
     *
     *  - `edit_url` is removed from every product row. On OpenCart that URL carries
     *    the admin `user_token`, and this object is POSTED TO OPTIMASI — it lands in
     *    the stored snapshot, the rendered PDF and (via the email card) an emailed
     *    document that gets forwarded. A live admin session token has no business in
     *    any of those, and the report does not need the link.
     *  - the size cap keeps a malformed or enormous body from spending this request's
     *    memory. It is not a trust boundary — only an authenticated admin with modify
     *    permission reaches this action.
     */
    private function reportPayload(): array {
        $report = $this->jsonField('report');

        if (isset($report['products']) && is_array($report['products'])) {
            foreach ($report['products'] as $index => $product) {
                if (is_array($product)) {
                    unset($report['products'][$index]['edit_url']);
                }
            }
        }

        return $report;
    }

    /**
     * One JSON field from the request body.
     *
     * Decoded with a depth limit and a size limit, and anything that is not an array
     * becomes an empty one — a malformed body should produce an empty report, not a
     * half-applied one.
     */
    private function jsonField(string $key): array {
        $raw = (string)($this->request->post[$key] ?? '');

        if ($raw === '' || strlen($raw) > 8388608) {
            return [];
        }

        $decoded = json_decode($raw, true, 64);

        return is_array($decoded) ? $decoded : [];
    }

    /** A sub-array of a decoded body, or [] — never null, so callers do not repeat the check. */
    private function mapBlock($value): array {
        return is_array($value) ? $value : [];
    }

    /**
     * Bound the per-block coverage to the seven known blocks with sane pass/total.
     *
     * Keeps the aggregate this extension forwards tiny and predictable: the same
     * blocks the scan measures, with `pass` never above `total`.
     */
    private function sanitiseFeatures($features): array {
        if (!is_array($features)) {
            return [];
        }

        $known = ['narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images'];
        $out   = [];

        foreach ($known as $key) {
            if (!isset($features[$key]) || !is_array($features[$key])) {
                continue;
            }

            $total = max(0, (int)($features[$key]['total'] ?? 0));

            if ($total === 0) {
                continue;
            }

            $out[$key] = [
                'pass'  => max(0, min((int)($features[$key]['pass'] ?? 0), $total)),
                'total' => $total,
            ];
        }

        return $out;
    }

    /**
     * Bound the authority block to the facts the explainer endpoint reads.
     *
     * Facts only: post counts plus the catalogue's top category names. No keyword
     * lists, no thresholds and no classification — those live on the server.
     */
    private function sanitiseAuthority($authority): array {
        if (!is_array($authority)) {
            return [];
        }

        $catalog    = is_array($authority['catalog'] ?? null) ? $authority['catalog'] : [];
        $categories = [];

        foreach ((is_array($catalog['categories'] ?? null) ? $catalog['categories'] : []) as $row) {
            if (count($categories) >= 12) {
                break;
            }

            if (!is_array($row)) {
                continue;
            }

            $name = $this->cleanText((string)($row['name'] ?? ''), 120);

            if ($name === '') {
                continue;
            }

            $categories[] = ['name' => $name, 'products' => (int)($row['products'] ?? 0)];
        }

        return [
            'platform'         => preg_replace('/[^a-z0-9_]/', '', strtolower((string)($authority['platform'] ?? ''))),
            'blog_posts'       => (int)($authority['blog_posts'] ?? 0),
            'recent_posts_90d' => (int)($authority['recent_posts_90d'] ?? 0),
            'in_depth_posts'   => (int)($authority['in_depth_posts'] ?? 0),
            'catalog'          => [
                'total_products' => (int)($catalog['total_products'] ?? 0),
                'categories'     => $categories,
            ],
        ];
    }

    /** A plain-text response, for the one action a browser navigates to rather than posts to. */
    private function plain(string $message): void {
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($message);
    }

    // ------------------------------------------------------------------
    // Small helpers
    // ------------------------------------------------------------------

    private function userToken(): string {
        return (string)($this->session->data['user_token'] ?? '');
    }

    private function languageId(): int {
        return (int)$this->config->get('config_language_id');
    }

    /**
     * This store's public URL, as the funnel records it.
     *
     * `config_url` is the configured canonical store URL; the build-time
     * constant is the fallback for a store that has never set one.
     */
    private function siteUrl(): string {
        $url = trim((string)$this->config->get('config_url'));

        if ($url === '') {
            $url = defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTP_SERVER') ? HTTP_SERVER : '');
        }

        return rtrim($url, '/') . '/';
    }

    /**
     * URL of one of this extension's own files.
     *
     * Absolute, and rooted at the store rather than the admin: the extension is
     * installed under `<store>/extension/<code>/`, while an admin page's relative
     * paths resolve against the admin directory.
     */
    private function asset(string $path): string {
        $base = defined('HTTP_CATALOG') ? HTTP_CATALOG : (defined('HTTP_SERVER') ? HTTP_SERVER : '');

        return rtrim($base, '/') . '/extension/' . self::CODE . '/admin/view/' . ltrim($path, '/')
            . '?v=' . rawurlencode(self::VERSION);
    }

    /**
     * A URL to embed in JavaScript, rather than to write into an HTML attribute.
     *
     * `url->link()` returns an `&amp;`-separated query string, which is correct in an
     * `href` and wrong here: this value is emitted inside a <script> block, where HTML
     * entities are NOT decoded. Embedded as-is, the browser sends `amp;user_token=…`,
     * PHP receives no `user_token` at all, OpenCart answers with the login page, and the
     * page reports "Server communication failed (HTTP 200)" — a 200 whose body the
     * browser cannot parse, which names neither the cause nor the fault.
     *
     * `html_entity_decode` rather than the third argument to `link()`, because that
     * argument is `$secure` in OpenCart 4.0 and `$js` in 4.1: using it would fix 4.1 and
     * quietly mean something else on 4.0. Decoding produces the right string on both.
     */
    private function jsUrl(string $route, string $args = ''): string {
        return html_entity_decode($this->url->link($route, $args), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Whether to render the Advanced endpoint panel.
     *
     * Hidden by default, and deliberately the same decision the WooCommerce plugin
     * made: the extension ships a working default endpoint, so this is a support and
     * agency tool rather than something a merchant needs — and a wrong value in it
     * silently breaks steps 2, 3 and the email card, with no error that names the
     * cause. Opt in per store, in `admin/config.php`:
     *
     *     define('OPTIMASI_ONBOARDING_SHOW_ADVANCED', true);
     *
     * The setting, the `save` action and normaliseEndpoint() all stay, so nothing
     * needs a migration and support can still reach the field on a store that needs
     * it. Hidden from the page is not the same as removed from the code.
     */
    private function showAdvancedPanel(): bool {
        return defined('OPTIMASI_ONBOARDING_SHOW_ADVANCED') && OPTIMASI_ONBOARDING_SHOW_ADVANCED;
    }

    /**
     * Give the request room for a long step.
     *
     * Called only by the actions that do the work — a scan of 1,000 products, or a
     * call to the review service that is allowed 30–45 seconds on a host whose
     * default `max_execution_time` is often exactly that. `save` does neither, so it
     * does not raise the limit: a limit raised for no reason is a limit that hides
     * somebody else's slow endpoint. Ignored where the host disallows it.
     */
    private function extendTimeLimit(int $seconds = 120): void {
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds);
        }
    }

    /** Strip control characters and bound the length. */
    private function cleanText(string $value, int $max = 255): string {
        $value = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
    }

    /** A token from the funnel: opaque, but bounded and restricted to URL- and SQL-safe characters. */
    private function cleanToken(string $value): string {
        return (string)preg_replace('/[^A-Za-z0-9_.:-]/', '', $value);
    }

    /** A validated address, or '' when it is empty or invalid. */
    private function cleanEmail(string $value): string {
        $value = $this->cleanText($value, 254);

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    /** Escape for an HTML attribute. */
    private function esc(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
