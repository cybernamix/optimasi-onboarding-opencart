/**
 * Optimasi Content Audit - the four-step review UI.
 *
 *   Step 1 - Scan your products     (local: nothing leaves the store)
 *   Step 2 - Analyse brand voice    (server: /api/v1/preview/analyse)
 *   Step 3 - Your Sales-Ready report (server-rendered from the stored snapshot)
 *   Step 4 - See How Optimasi Works (outbound link, no request)
 *
 * Each step unlocks only after the previous one completes, and its results are
 * shown before the next step is offered. No scoring logic lives in this file -
 * the extension collects facts and renders what the Optimasi server returns.
 *
 * Relies on the localized `optimasiAudit` object the template emits:
 *   { ajax: {scan, voice, report, snapshot, email_report, contact, save},
 *     consent_recorded, report_previous, how_it_works_url }
 *
 * This is the WooCommerce plugin's script with two platform changes:
 *
 *   1. OpenCart addresses a request by route, so each action has its own URL in
 *      `ajax`, where WordPress posted an `action` + `subaction` pair to
 *      admin-ajax.php.
 *   2. The entry point retries until jQuery exists. A module's scripts are
 *      collected before the admin emits its own, so this file can be parsed
 *      first; assuming the opposite fails with "jQuery is not defined" on a page
 *      that otherwise looks perfectly fine.
 *
 * Everything else is identical on purpose - the two products have to read the
 * same, and duplicated renderers drift.
 */
(function () {
    'use strict';

    function start($) {
    const $run = $('#optimasi-run-audit');
    const $voice = $('#optimasi-analyse-voice');
    const $howItWorks = $('#optimasi-how-it-works');

    if (!$run.length) return;

    // Latest results, kept so the end-of-flow report can be assembled.
    let scanData = null;
    let voiceResult = null;
    // Plain-language LLM findings shown at the end of Step 2 — captured so the
    // emailed report tells the same story as the on-site UI.
    let findingsData = null;

    // Email-less capture: fired once when Step 2 completes so the review is
    // stored server-side (Summary + Full markdown — no PDF is generated here)
    // before any email is collected. The returned request_id is attached when
    // the visitor later adds an email, and the full report is emailed to that
    // address as a PDF once they confirm.
    // The report token is deliberately absent: it never leaves the admin, so there
    // is nothing here to keep. Every later step uses the stored one server-side.
    let reportRequestId = null;
    let snapshotSent = false;
    // The rendered report document, kept so Print does not need a second round-trip.
    // There is no token variable: the report token never leaves the admin, so every
    // later step falls back to the stored one server-side.
    let reportDocHtml = '';

    // Scan cap: review the first SCAN_CAP published products — enough to judge
    // the state of the store without shipping an unbounded payload (Step 2 and
    // Step 3 send per-product description text to the Optimasi servers).
    const SCAN_CAP = 1000;
    // Per-product metric rows that travel with the emailed report (≤ SCAN_CAP).
    const REPORT_MAX_PRODUCTS = 1000;
    // "Show the evidence" table page size.
    const EVIDENCE_PAGE = 50;

    const STEP_BUTTONS = { 1: $run, 2: $voice, 4: $howItWorks };
    const STEP_LABELS = { 1: 'Scan my products', 2: 'Analyse brand voice', 4: 'See how it works' };

    // Shared labels/order for step-1 coverage + the evidence table marks.
    const FEATURE_LABELS = {
        narrative: 'Rich description', brand_story: 'Brand storytelling', reviews: 'Review summaries',
        trust: 'Trust & disclaimers', faq: 'FAQ content', specs: 'Specs & attributes', images: 'Images'
    };
    const FEATURE_ORDER = ['narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images'];

    // Pagination state for the step-1 "Show the evidence" table.
    const evState = { products: [], page: 1 };

    // Pager buttons (delegated so they survive re-renders of the step-1 box).
    $(document).on('click', '.optimasi-ev-page', function (e) {
        e.preventDefault();
        const $btn = $(this);
        if ($btn.prop('disabled')) return;
        const dir = parseInt($btn.data('dir'), 10) || 0;
        const last = Math.max(1, Math.ceil(evState.products.length / EVIDENCE_PAGE));
        evState.page = Math.min(last, Math.max(1, evState.page + dir));
        renderEvidence();
    });

    // ------------------------------------------------------------------
    // Step 1 — scan up to SCAN_CAP products (local, nothing leaves the site)
    // ------------------------------------------------------------------
    $run.on('click', function (e) {
        e.preventDefault();
        runStep(1, 'scan', null,
            'Scanning your products…',
            'Reading metadata for up to the first ' + SCAN_CAP + ' published products. Nothing is sent to Optimasi in this step.')
            .then(function (res) {
                if (res.ok) {
                    scanData = res.data;
                    renderStep1(res.data);
                    completeStep(1);
                }
            });
    });

    // ------------------------------------------------------------------
    // Step 2 — analyse brand voice (server review analysis, no key)
    // ------------------------------------------------------------------
    $voice.on('click', function (e) {
        e.preventDefault();
        runStep(2, 'voice', { consent: $consent.prop('checked') ? '1' : '0' },
            'Analysing your brand voice…',
            'Sending a short sample of each product description to Optimasi’s servers to measure tone and vocabulary consistency.')
            .then(function (res) {
                if (res.ok) {
                    const result = (res.data && res.data.result) ? res.data.result : res.data;
                    voiceResult = result;
                    renderStep2(result);
                    completeStep(2);
                    // Capture the review as soon as the online review (Step 2)
                    // finishes — email-less, so marketing always has the
                    // Summary + Full report before an email is collected. When
                    // the capture lands, load the new 4-page report in place of
                    // the old sectioned on-site report.
                    maybeSendSnapshot().then(loadReportDocument);
                    // Capstone: plain-language audit findings (bounded facts,
                    // LLM-written + cached on the server). Best-effort — never
                    // blocks the flow.
                    loadFindings();
                }
            });
    });

    // ------------------------------------------------------------------
    // Step 4 — See How Optimasi Works. An outbound link, not a fetch: the
    // explanation lives on optimasi.io, so there is nothing to relay and
    // nothing leaves the store.
    //
    // It opens in a NEW TAB rather than navigating away, which is the one
    // deviation from "redirect": the report above it is the thing being
    // explained, and replacing it with a marketing page loses the context the
    // visitor clicked from.
    // ------------------------------------------------------------------
    $howItWorks.on('click', function (e) {
        e.preventDefault();
        const url = (window.optimasiAudit && optimasiAudit.how_it_works_url)
            ? String(optimasiAudit.how_it_works_url)
            : '';
        if (!url) {
            setStepState(4, 'Unavailable');
            return;
        }
        const win = window.open(url, '_blank', 'noopener');
        // A blocked pop-up would otherwise look like a button that does nothing.
        setStepState(4, win ? 'Opened in a new tab' : 'Pop-up blocked — allow pop-ups');
    });

    // ------------------------------------------------------------------
    // Step orchestration
    // ------------------------------------------------------------------
    function runStep(step, subaction, extra, loaderTitle, loaderDesc) {
        const $step = $('#optimasi-step-' + step);
        const $btn = STEP_BUTTONS[step];
        const $box = $('#optimasi-result-' + step);
        $btn.prop('disabled', true);
        $step.addClass('is-running');
        setStepState(step, 'Working…');
        $box.html(
            '<div class="optimasi-audit-loader-wrap">' +
            '<h3>' + escapeHtml(loaderTitle) + '</h3>' +
            '<div class="optimasi-progress-bar"><div id="optimasi-progress-fill"></div></div>' +
            '<p class="description">' + escapeHtml(loaderDesc) + '</p>' +
            '</div>'
        );
        $('#optimasi-progress-fill').css('width', '18%');
        return post(subaction, extra)
            .then(function (data) {
                $step.removeClass('is-running');
                setStepState(step, '');
                return { ok: true, data: data };
            })
            .catch(function (err) {
                $step.removeClass('is-running');
                setStepState(step, '');
                $btn.prop('disabled', false).text(STEP_LABELS[step]);
                const msg = (err && err.message) ? err.message : 'Unknown error.';
                $box.html('<div class="notice notice-error"><p><strong>Step ' + step + ' failed:</strong> ' + escapeHtml(msg) + '</p></div>');
                return { ok: false };
            });
    }

    function completeStep(step) {
        $('#optimasi-step-' + step).addClass('is-done').removeClass('is-active');
        const $btn = STEP_BUTTONS[step];
        if ($btn && $btn.length) {
            // The action has already run. A disabled "Done" button tells the
            // visitor nothing the step state ("Complete") does not, so the row
            // is taken away rather than left as a dead control.
            $btn.closest('.optimasi-step-actions').hide();
        }
        setStepState(step, 'Complete');
        // Unlock the next step so the visitor can move on when ready — but do
        // NOT auto-scroll to it. The report for this step renders in place and
        // there is a lot to read (coverage, health, explainers), so jumping
        // away would hide exactly what they came to see.
        if (step === 1) {
            unlockStep(2);
        } else if (step === 2) {
            // Step 3 is the report itself, revealed by loadReportDocument (which
            // is still waiting on the email-less snapshot), so only the example
            // pages are unlocked here. The email capture sits between the two and
            // is revealed with the report it sends, not here.
            unlockStep(4);
        }
    }

    // Make a step visible and usable. Step 3 (the report) has no action button —
    // it is rendered for the visitor, not run by them.
    function unlockStep(step) {
        $('#optimasi-step-' + step).removeClass('is-locked');

        const $btn = STEP_BUTTONS[step];
        if ($btn && $btn.length) {
            $('#optimasi-step-' + step).addClass('is-active');
            $btn.prop('disabled', false).text(STEP_LABELS[step]);
            syncVoiceButton();
        }
    }

    function setStepState(step, text) {
        $('#optimasi-step-' + step + ' .optimasi-step-state').text(text);
    }

    // ------------------------------------------------------------------
    // Email-less capture — persist the review the moment Step 2 completes
    // ------------------------------------------------------------------
    function maybeSendSnapshot() {
        if (snapshotSent || !scanData) { return Promise.resolve(null); }
        snapshotSent = true;  // one snapshot per review run
        return post('snapshot', { report: JSON.stringify(buildReport('')) })
            .then(function (data) {
                if (data && data.request_id) { reportRequestId = data.request_id; }
                return data || null;
            })
            .catch(function () { return null; /* best-effort: email_report still works */ });
    }

    // ------------------------------------------------------------------
    // Step 3 — the new 4-page Sales-Ready report (server-rendered, and the
    // document the store keeps; the example pages are illustrative only).
    // ------------------------------------------------------------------
    const $reportStep = $('#optimasi-step-3');
    const $reportFrame = $('#optimasi-report-frame');
    const $reportStatus = $('#optimasi-report-status');
    const $reportOpen = $('#optimasi-report-open');
    // Provenance for a stored report from an earlier review (see showPreviousReport).
    const $previousReport = $('#optimasi-report-previous');
    const $previousReportText = $('#optimasi-report-previous-text');
    const $previousReportShow = $('#optimasi-report-previous-show');
    // The offer panel sits with the report (and is revealed by it), so it is
    // resolved here rather than down in the contact-form block.
    const $help = $('#optimasi-help');

    function reportDocStatus(text) {
        if ($reportStatus.length) { $reportStatus.text(text || ''); }
    }

    // Fetch the rendered 4-page report and embed it. Called once the snapshot
    // capture returns a token, and again on page load when a previous run's
    // token is stored server-side.
    function loadReportDocument() {
        // The visitor asked for a report, so the notice about an older one has done its
        // job. Hiding it here is what keeps a fresh run from showing a stale offer.
        hidePreviousReport();
        // The email capture and the offer both sit with the report they are
        // about (between steps 3 and 4), so they are revealed here rather than
        // after the example pages — and the email card must appear even when the
        // report fails, because the failure message points the visitor at it.
        //
        // EXCEPT on a report restored from an earlier visit. The full report is built
        // from the review payload THIS session sent, so a restored document has nothing
        // to email; offering it there invites a request that would arrive empty.
        if (snapshotSent) {
            $('#optimasi-report-extra').removeClass('is-hidden');
        }
        $help.removeClass('is-hidden');
        if (!$reportStep.length) { return; }
        // The report is the deliverable, so its step opens before the example
        // pages rather than at the bottom of the page.
        $reportStep.removeClass('is-locked');
        setStepState(3, 'Preparing…');
        reportDocStatus('Preparing your report…');
        post('report', { template: 'marketing' })
            .then(function (data) {
                const html = (data && data.html) ? String(data.html) : '';
                if (!html) {
                    setStepState(3, 'Unavailable');
                    reportDocStatus('Your report could not be loaded right now — please try again in a moment.');
                    return;
                }
                reportDocHtml = html;
                // srcdoc keeps the report same-origin (the content is generated
                // server-side and fully escaped) so Print works directly.
                $reportFrame.attr('srcdoc', html).css('display', 'block');
                $reportOpen.prop('disabled', false);
                $reportStep.addClass('is-done');
                setStepState(3, 'Ready');
                reportDocStatus('');
            })
            .catch(function (err) {
                setStepState(3, 'Unavailable');
                reportDocStatus((err && err.message) ? err.message : 'Your report could not be loaded right now.');
            });
    }

    /**
     * Offer the report from an EARLIER review, with its date, without pretending it is
     * this run's result.
     *
     * This is the whole difference from what the page used to do: it rendered the stored
     * report outright on page load, so step 3 appeared complete — findings and all —
     * while steps 1 and 2 had not run. The document was real; the implication was not.
     * Here the step opens so the offer is visible, but its state says "Previous
     * report", its action button stays disabled, and nothing is fetched until the
     * visitor presses the button.
     */
    function showPreviousReport(report) {
        if (!$previousReport.length) { return; }
        const label = (report && report.label) ? String(report.label) : '';
        const copy = label
            ? String(report.with_date || '').replace('%s', label)
            : String(report.without_date || '');
        if (!copy) { return; }
        $previousReportText.text(copy);
        $previousReport.removeClass('is-hidden');
        $reportStep.removeClass('is-locked');
        setStepState(3, 'Previous report');
    }

    function hidePreviousReport() {
        $previousReport.addClass('is-hidden');
    }

    $previousReportShow.on('click', function (e) {
        e.preventDefault();
        $previousReportShow.prop('disabled', true);
        loadReportDocument();
    });

    $reportOpen.on('click', function (e) {
        e.preventDefault();
        openReportInNewTab();
    });

    function openReportInNewTab() {
        if (!reportDocHtml) { return; }
        // The report is served by this extension as a standalone document with
        // `Content-Security-Policy: sandbox`, NOT re-wrapped in a blob here: a blob
        // inherits this page's origin, so a script in the report would run with the
        // store's admin session. The endpoint puts the document on an opaque origin,
        // where it renders and prints but cannot run a line of JavaScript.
        const url = (window.optimasiAudit && optimasiAudit.report_html)
            ? String(optimasiAudit.report_html)
            : '';
        if (!url) {
            reportDocStatus('The report could not be opened — please try again in a moment.');
            return;
        }
        const win = window.open(url, '_blank', 'noopener');
        if (!win) {
            reportDocStatus('Pop-up blocked — allow pop-ups for this site to open the report.');
        }
    }

    // A report from a previous run is still available after a page reload — but it is
    // NOT this run's report, so it is OFFERED with its date rather than rendered as if
    // the visitor had just finished. Rendering it here unconditionally is what made
    // step 3 look complete before steps 1 and 2 had run.
    if (optimasiAudit && optimasiAudit.report_previous && optimasiAudit.report_previous.has) {
        showPreviousReport(optimasiAudit.report_previous);
    }

    // ------------------------------------------------------------------
    // Consent — the brand-voice step is the only one that sends description
    // text off the site, so it waits for an explicit tick (WP.org guideline 7).
    // Consent is remembered server-side once given, so the visitor is not
    // asked again on the next run.
    // ------------------------------------------------------------------
    const $consent = $('#optimasi-consent-send');

    function syncVoiceButton() {
        if (!$voice.length || !$consent.length || !$consent.prop('checked')) {
            if ($voice.length && $consent.length) { $voice.prop('disabled', true); }
            return;
        }
        // Before the step is unlocked its own locked state owns the button.
        if ($('#optimasi-step-2').hasClass('is-locked')) { return; }
        $voice.prop('disabled', false);
    }

    $consent.on('change', function () {
        syncVoiceButton();
    });

    // ------------------------------------------------------------------
    // The offer — pick a preferred channel and ask for a one-on-one. Mirrors
    // the emailed contact page's contract exactly (same channel values the
    // backend validates), so a choice made here lands in the same
    // sales-contact record as one made from an email.
    // ------------------------------------------------------------------
    const $contactForm = $('#optimasi-contact-form');
    const $contactNumberWrap = $('#optimasi-contact-number-wrap');

    function contactChannel() {
        return String($contactForm.find('input[name=optimasi_channel]:checked').val() || '');
    }

    function syncContactNumberField() {
        const channel = contactChannel();
        $contactNumberWrap.toggleClass('is-hidden', !(channel === 'whatsapp' || channel === 'viber'));
    }

    $contactForm.on('change', 'input[name=optimasi_channel]', syncContactNumberField);
    syncContactNumberField();

    $contactForm.on('submit', function (e) {
        e.preventDefault();
        const $btn = $('#optimasi-contact-submit');
        const $spin = $('#optimasi-contact-spinner');
        const channel = contactChannel();
        if (!channel) {
            showInlineMessage('#optimasi-contact-message', 'Choose how you’d like us to reach you.', true);
            return;
        }
        $btn.prop('disabled', true);
        $spin.addClass('is-active');
        post('contact', {
            channel: channel,
            number: String($('#optimasi-contact-number').val() || ''),
            role: String($('#optimasi-contact-role').val() || ''),
            email: String($('#optimasi-contact-email').val() || ''),
        })
            .then(function (data) {
                const label = (data && data.channel_label) ? String(data.channel_label) : 'your preferred channel';
                showInlineMessage('#optimasi-contact-message', 'Thanks — we’ll be in touch over ' + label + ' to arrange a one-on-one.');
                // The request is recorded; lock the form so a second submit
                // cannot overwrite the choice with a different channel.
                $contactForm.find('input, select, button').prop('disabled', true);
                $btn.text('Requested');
            })
            .catch(function (err) {
                showInlineMessage('#optimasi-contact-message', (err && err.message) ? err.message : 'Could not send your request — please try again.', true);
                $btn.prop('disabled', false);
            })
            .finally(function () {
                $spin.removeClass('is-active');
            });
    });

    // ------------------------------------------------------------------
    // End-of-flow extras: email report
    // ------------------------------------------------------------------

    $('#optimasi-email-submit').on('click', function (e) {
        e.preventDefault();
        const $email = $('#optimasi-report-email');
        const email = String($email.val() || '').trim();
        if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
            showInlineMessage('#optimasi-email-message', 'Please enter a valid email address.', true);
            return;
        }
        const $btn = $('#optimasi-email-submit');
        const $spin = $('#optimasi-email-spinner');
        $btn.prop('disabled', true);
        $spin.addClass('is-active');
        const report = buildReport(email);
        const payload = { email: email, report: JSON.stringify(report) };
        if (reportRequestId) { payload.request_id = reportRequestId; }
        post('email_report', payload)
            .then(function (data) {
                showInlineMessage('#optimasi-email-message', (data && data.message) || 'Request received — we’ll email you when your full report is ready.');
                $btn.text('Requested');
                // Carry the address into the offer below, so asking for a
                // one-on-one does not make them type it again.
                const $contactEmail = $('#optimasi-contact-email');
                if ($contactEmail.length && !$contactEmail.val()) { $contactEmail.val(email); }
            })
            .catch(function (err) {
                showInlineMessage('#optimasi-email-message', (err && err.message) ? err.message : 'Could not send the request.', true);
                $btn.prop('disabled', false);
            })
            .finally(function () {
                $spin.removeClass('is-active');
            });
    });

    // Advanced: point the free review at a specific service endpoint (per-site
    // config for beta / regional testing — never baked into the plugin).
    $('#optimasi-api-url-save').on('click', function (e) {
        e.preventDefault();
        const url = String($('#optimasi-api-url').val() || '').trim();
        const $btn = $('#optimasi-api-url-save');
        const $spin = $('#optimasi-api-url-spinner');
        $btn.prop('disabled', true);
        $spin.addClass('is-active');
        post('save', { url: url })
            .then(function (data) {
                showInlineMessage('#optimasi-api-url-message', (data && data.message) || (url ? 'Review service endpoint saved.' : 'Endpoint cleared.'));
            })
            .catch(function (err) {
                showInlineMessage('#optimasi-api-url-message', (err && err.message) ? err.message : 'Could not save the endpoint.', true);
                $btn.prop('disabled', false);
            })
            .finally(function () {
                $spin.removeClass('is-active');
            });
    });

    function catalogMetrics() {
        const products = (scanData && scanData.products) || [];
        return products.slice(0, REPORT_MAX_PRODUCTS).map(function (p) {
            return {
                product_id: p.product_id,
                title: String(p.title || '').slice(0, 120),
                chars_short_desc: p.chars_short_desc || 0,
                chars_long_desc: p.chars_long_desc || 0,
                image_count: p.image_count || 0,
                review_count: p.review_count || 0,
                average_rating: typeof p.average_rating === 'number' ? p.average_rating : 0,
                has_brand: !!p.has_brand,
                brand_terms: Array.isArray(p.brand_terms) ? p.brand_terms.slice(0, 3) : [],
                features: p.features || {},
                // Evidence-table extras the Full report renders (words estimate,
                // weakest-categories, category name). NOT `edit_url`: on OpenCart that
                // URL carries the admin user_token, and this object is posted to
                // Optimasi — it would put a live admin credential in the stored
                // snapshot, the PDF and any emailed copy of the report.
                words_long_desc: p.words_long_desc || 0,
                category: (Array.isArray(p.categories) && p.categories[0])
                    ? String(p.categories[0]).slice(0, 80)
                    : String(p.category || '').slice(0, 80),
            };
        });
    }

    function buildReport(email) {
        const brandVoice = (voiceResult && voiceResult.brand_voice) || {};
        const dups = (voiceResult && voiceResult.duplicates) || {};
        const catalog = (voiceResult && voiceResult.catalog) || {};
        // `previews` stays in the payload, empty, because the report shape is
        // shared with the backend: the rewrite-preview action that filled it was
        // removed in 1.2.21 (it was unreachable from this page).
        const previewList = [];
        return {
            requested_by: email,
            generated_at: new Date().toISOString(),
            scanned_total: scanData ? scanData.total : 0,
            completeness: scanData ? scanData.completeness : 0,
            features: (scanData && scanData.features) || {},
            // Knowledge-content / authority measurement (blog usage) — surfaced
            // in the Summary + Full reports as a missed-authority opportunity.
            authority: (scanData && scanData.authority) || null,
            // Whole-catalog per-product metrics — powers the comprehensive Full
            // content report (the LLM can name real products and cite real stats).
            products: catalogMetrics(),
            brand_voice: {
                status: brandVoice.status || '',
                consistency_score: typeof brandVoice.consistency_score === 'number' ? brandVoice.consistency_score : null,
                summary: brandVoice.summary || '',
                sample_valid: brandVoice.sample_valid || 0,
                aligned: brandVoice.aligned || 0,
                deviating: brandVoice.deviating || 0,
            },
            duplicates: { flagged: dups.flagged || 0, sample_size: dups.checked || 0 },
            // Weighted-completeness context (server-side Optimasi scoring) so the
            // emailed Summary PDF can explain "what this means for your store".
            catalog: {
                sample_size: catalog.sample_size || 0,
                overall_score: typeof catalog.overall_score === 'number' ? catalog.overall_score : 0,
                score_breakdown: (catalog.score_breakdown || []).slice(0, 12).map(function (b) {
                    return {
                        feature: b.feature || '',
                        label: b.label || '',
                        pass: b.pass || 0,
                        total: b.total || 0,
                        weight: b.weight || 0,
                    };
                }),
            },
            // The plain-language audit-findings narrative shown on-site — the
            // Summary PDF renders this verbatim so UI and report match.
            findings: findingsData,
            previews: previewList,
            previews_generated: previewList.length,
        };
    }

    function showInlineMessage(selector, text, isError) {
        const $el = $(selector);
        $el.text(text);
        $el.toggleClass('is-error', !!isError);
    }

    // ------------------------------------------------------------------
    // AJAX helper (resolves with response.data on success)
    // ------------------------------------------------------------------
    function post(subaction, extra) {
        const url = (window.optimasiAudit && optimasiAudit.ajax && optimasiAudit.ajax[subaction])
            ? optimasiAudit.ajax[subaction]
            : '';
        if (!url) {
            // A missing URL is a build fault, not a server fault, and it would
            // otherwise fail as an opaque 404 on the wrong action.
            const missing = new Error('This step is not available - the page was built without a URL for "' + subaction + '".');
            missing.code = 'no_route';
            return Promise.reject(missing);
        }
        return new Promise(function (resolve, reject) {
            $.ajax({
                url: url,
                type: 'POST',
                dataType: 'json',
                data: Object.assign({}, extra || {}),
                success: function (response) {
                    if (response && response.success) {
                        resolve(response.data);
                        return;
                    }
                    const d = (response && response.data) ? response.data : {};
                    const msg = (d && typeof d === 'object' && d.message) ? d.message : 'Invalid server response.';
                    const err = new Error(msg);
                    err.code = (d && typeof d === 'object' && d.error) ? d.error : 'unknown';
                    reject(err);
                },
                error: function (xhr) {
                    let msg = 'Server communication failed.';
                    let code = 'unknown';
                    const res = (xhr && xhr.responseJSON) ? xhr.responseJSON : null;
                    if (res && typeof res === 'object') {
                        const payload = (res.data && typeof res.data === 'object') ? res.data : null;
                        if (payload && typeof payload.message === 'string' && payload.message) msg = payload.message;
                        if (payload && typeof payload.error === 'string' && payload.error) code = payload.error;
                        if (typeof res.message === 'string' && res.message) msg = res.message;
                        if (typeof res.detail === 'string' && res.detail) msg = res.detail;
                    }
                    const status = (xhr && typeof xhr.status === 'number') ? xhr.status : 0;
                    // `dataType: 'json'` rejects a body jQuery cannot parse, so a 200 here
                    // means the server answered with something that was not JSON -- almost
                    // always output printed before the payload. The head of it turns
                    // "HTTP 200" into something that can be acted on without a shell on
                    // the store.
                    if (!res) {
                        const raw = String((xhr && xhr.responseText) || '').replace(/\s+/g, ' ').trim();
                        if (raw) {
                            msg += ' Server said: ' + (raw.length > 300 ? raw.slice(0, 300) + '...' : raw);
                        }
                    }
                    if (status) {
                        msg = msg.replace(/\.?$/, '') + ' (HTTP ' + status + ')';
                    }
                    const err = new Error(msg);
                    err.code = code;
                    reject(err);
                },
            });
        });
    }

    // (Legacy connection / deep-audit UI helpers removed — this is now a
    // review-only flow. Connect-and-apply lives in the full plugin.)

    // ------------------------------------------------------------------
    // Report shell helpers — sectioned layout, sticky TOC, heading levels
    // ------------------------------------------------------------------
    // The audit report is rendered as one "web page": a sticky section nav, a
    // report header, then five numbered sections (h4) with an eyebrow kicker so
    // it is easy to scan and easy to traverse. Heading order on the page is
    // h1 (screen title) > h2 (step) > h3 (report title) > h4 (section).
    function reportToc(items) {
        const links = items.map(function (it, i) {
            const n = (i + 1) < 10 ? '0' + (i + 1) : String(i + 1);
            return '<a class="optimasi-toc-link" href="#' + it.id + '">' +
                '<span class="optimasi-toc-num" aria-hidden="true">' + n + '</span>' +
                escapeHtml(it.label) + '</a>';
        }).join('');
        return '<nav class="optimasi-report-toc" aria-label="Report sections">' +
            '<span class="optimasi-toc-label">In this report</span>' +
            '<div class="optimasi-toc-links">' + links + '</div>' +
            '</nav>';
    }

    function reportSection(num, id, kicker, title, lede, body, extraClass) {
        return '<section class="optimasi-report-section' + (extraClass ? ' ' + extraClass : '') +
            '" id="' + id + '">' +
            '<p class="optimasi-eyebrow">' +
            '<span class="optimasi-eyebrow-num" aria-hidden="true">' + escapeHtml(num) + '</span>' +
            escapeHtml(kicker) + '</p>' +
            '<h4 class="optimasi-section-title">' + escapeHtml(title) + '</h4>' +
            (lede ? '<p class="optimasi-section-lede">' + escapeHtml(lede) + '</p>' : '') +
            '<div class="optimasi-section-body">' + body + '</div>' +
            '</section>';
    }

    // Progressive enhancement: highlight the TOC link for the section currently
    // in view. The anchors work with or without it.
    function initReportToc() {
        const root = document.querySelector('.optimasi-report');
        if (!root || typeof IntersectionObserver === 'undefined') return;
        const links = Array.prototype.slice.call(root.querySelectorAll('.optimasi-toc-link'));
        if (!links.length) return;
        const byId = {};
        links.forEach(function (l) { byId[l.getAttribute('href').slice(1)] = l; });
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (!entry.isIntersecting) return;
                const link = byId[entry.target.id];
                if (!link) return;
                links.forEach(function (l) { l.classList.remove('is-active'); });
                link.classList.add('is-active');
            });
        }, { rootMargin: '-15% 0px -70% 0px', threshold: 0 });
        Array.prototype.forEach.call(root.querySelectorAll('.optimasi-report-section'), function (s) {
            observer.observe(s);
        });
    }

    // ------------------------------------------------------------------
    // Step 1 render — factual coverage + evidence + plain-language summary
    // ------------------------------------------------------------------
    function renderStep1(data) {
        const $box = $('#optimasi-result-1');
        const products = data.products || [];
        if (!products.length) {
            $box.html('<div class="notice notice-info"><p><strong>Scan complete:</strong> no published products were found.</p></div>');
            return;
        }
        const total = data.total || products.length;
        const completeness = data.completeness || 0;
        const scoreClass = completeness > 70 ? 'optimasi-score-good' : (completeness > 40 ? 'optimasi-score-warning' : 'optimasi-score-critical');

        let coverageRows = '';
        Object.keys(data.features || {}).forEach(function (key) {
            const f = data.features[key];
            const pct = f.total ? Math.round((f.pass / f.total) * 100) : 0;
            const cls = pct > 70 ? 'optimasi-ok' : (pct > 40 ? 'optimasi-warn' : 'optimasi-bad');
            coverageRows +=
                '<div class="optimasi-feature-row ' + cls + '">' +
                '<span class="optimasi-feature-label">' + escapeHtml(FEATURE_LABELS[key] || key) + '</span>' +
                '<span class="optimasi-coverage-bar"><span class="optimasi-coverage-fill" style="width:' + pct + '%"></span></span>' +
                '<span class="optimasi-feature-count"><b>' + pct + '%</b><small>' + f.pass + '/' + f.total + '</small></span>' +
                '</div>';
        });

        evState.products = products;
        evState.page = 1;

        const auditedAt = String(data.audited_at || '').replace('T', ' ').replace('Z', ' UTC');
        $box.html(
            '<div class="optimasi-report">' +
            reportToc([
                { id: 'opt-coverage', label: 'Content coverage' },
                { id: 'opt-health', label: 'Catalog health' },
                { id: 'opt-evidence', label: 'The evidence' },
                { id: 'opt-authority', label: 'Website authority' },
                { id: 'opt-meaning', label: 'What this means' }
            ]) +
            '<div class="optimasi-report-dashboard">' +
            '<div class="optimasi-report-header">' +
            '<div class="optimasi-report-header-main">' +
            '<p class="optimasi-eyebrow">Content completeness audit</p>' +
            '<h3 class="optimasi-report-title">Sales readiness</h3>' +
            '<p class="optimasi-report-meta">Audited <strong>' + total + '</strong> published products on ' +
            escapeHtml(auditedAt) + ' — how ready each one is to help shoppers find, trust and buy.</p>' +
            '</div>' +
            '<div class="optimasi-score-block">' +
            '<div class="optimasi-score-badge ' + scoreClass + '" style="--pct:' + completeness +
            '" role="img" aria-label="Sales readiness score ' + completeness + ' out of 100">' +
            '<span class="optimasi-score-value">' + completeness + '<small>/100</small></span>' +
            '</div>' +
            '<p class="optimasi-score-label">Sales readiness</p>' +
            '</div>' +
            '</div>' +
            reportSection('01', 'opt-coverage', 'Measured across your catalog', 'Content coverage',
                'How many of your products carry each content block that helps shoppers find, trust and buy.',
                '<div class="optimasi-coverage-list">' + coverageRows + '</div>') +
            reportSection('02', 'opt-health', 'Diagnostics', 'Catalog health',
                'Where the catalog is thin, which categories lag, and the products to fix first.',
                catalogHealthPanel(products)) +
            reportSection('03', 'opt-evidence', 'Product-level detail', 'The evidence',
                'Every product we measured, with the content blocks each one carries.',
                '<details class="optimasi-evidence">' +
                '<summary>Show the evidence — every product we measured (' + products.length + ')</summary>' +
                '<div class="optimasi-evidence-legend">Content-block marks: ' + FEATURE_ORDER.map(function (k) { return FEATURE_LABELS[k]; }).join(' · ') +
                ' — <span class="optimasi-mark optimasi-mark-yes">✓</span> present &nbsp;<span class="optimasi-mark optimasi-mark-no">✗</span> missing</div>' +
                '<div id="optimasi-evidence-wrap"></div>' +
                '</details>') +
            '</div>' +
            authorityPanel(data.authority, total) +
            reportSection('05', 'opt-meaning', 'Cited research & next steps', 'What this means',
                'The plain-language read of your scan, backed by the industry benchmarks you also get in your report.',
                summaryPanel(data)) +
            '</div>' +
            nextHint(2, 'Analyse your brand voice — are your product descriptions consistent?')
        );
        renderEvidence();
        initReportToc();
        // Step-1 enrichment: fetch the shared per-block "What this means" cards
        // (with cited industry stats) from the Optimasi service. Best-effort —
        // the local summary above stays until/unless the cards arrive, so an
        // unreachable service never blocks the scan.
        loadExplainers();
    }

    // ------------------------------------------------------------------
    // Evidence table helpers (step 1) — paginated, word counts, tooltips.
    // ------------------------------------------------------------------
    function evidenceWordCount(p) {
        if (typeof p.words_long_desc === 'number') return p.words_long_desc;
        const c = parseInt(p.chars_long_desc, 10) || 0;
        return c ? Math.round(c / 6) : 0; // fallback estimate for pre-1.1.1/mock data
    }
    function evidenceBrandName(p) {
        const terms = p.brand_terms;
        return (Array.isArray(terms) && terms.length) ? String(terms[0]) : '';
    }
    function evidenceRowHtml(p) {
        const feats = p.features || {};
        let marks = '';
        FEATURE_ORDER.forEach(function (k) {
            const label = FEATURE_LABELS[k] || k;
            marks += feats[k]
                ? '<span class="optimasi-mark optimasi-mark-yes" title="' + escapeHtml(label) + ' — present">✓</span>'
                : '<span class="optimasi-mark optimasi-mark-no" title="' + escapeHtml(label) + ' — missing">✗</span>';
        });
        const words = evidenceWordCount(p);
        const chars = parseInt(p.chars_long_desc, 10) || 0;
        const descTitle = chars ? '≈ ' + chars + ' characters in the main description' : 'Description length';
        const brand = p.has_brand ? evidenceBrandName(p) : '';
        const brandCell = brand
            ? '<span class="optimasi-evidence-yes" title="' + escapeHtml('Brand: ' + brand) + '">Yes</span>'
            : '<span title="No brand taxonomy assigned to this product">No</span>';
        return '<tr>' +
            '<td><a href="' + escapeHtml(p.edit_url || '') + '" target="_blank">' + escapeHtml(p.title) + '</a></td>' +
            '<td title="' + descTitle + '">' + words + '</td>' +
            '<td>' + (p.image_count || 0) + '</td>' +
            '<td>' + (p.review_count || 0) + '</td>' +
            '<td>' + brandCell + '</td>' +
            '<td class="optimasi-mark-cell">' + marks + '</td>' +
            '</tr>';
    }
    function evidenceBodyHtml() {
        const total = evState.products.length;
        const last = Math.max(1, Math.ceil(total / EVIDENCE_PAGE));
        const page = Math.min(evState.page, last);
        const start = (page - 1) * EVIDENCE_PAGE;
        const slice = evState.products.slice(start, start + EVIDENCE_PAGE);
        let rows = '';
        slice.forEach(function (p) { rows += evidenceRowHtml(p); });
        return '<div class="optimasi-evidence-pager">' +
            '<button type="button" class="optimasi-page-btn optimasi-ev-page" data-dir="-1"' + (page <= 1 ? ' disabled' : '') + '>← Prev</button>' +
            '<span class="optimasi-evidence-count">Page ' + page + ' of ' + last + ' — showing ' + slice.length + ' of ' + total + ' products</span>' +
            '<button type="button" class="optimasi-page-btn optimasi-ev-page" data-dir="1"' + (page >= last ? ' disabled' : '') + '>Next →</button>' +
            '</div>' +
            '<div class="optimasi-evidence-scroll"><table class="optimasi-evidence-table">' +
            '<thead><tr>' +
            '<th>Product</th>' +
            '<th title="Words in the main description — the standard content-length measure">Desc words</th>' +
            '<th title="Images on the product (featured + gallery)">Images</th>' +
            '<th title="Customer reviews on the product">Reviews</th>' +
            '<th title="A brand taxonomy assigned to the product (e.g. WooCommerce Brands). Shown separately from the Brand storytelling coverage block above.">Brand tag</th>' +
            '<th title="Content blocks present on this product — see the legend above for the order">Content blocks</th>' +
            '</tr></thead>' +
            '<tbody>' + rows + '</tbody>' +
            '</table></div>';
    }
    function renderEvidence() {
        const $w = $('#optimasi-evidence-wrap');
        if ($w.length) { $w.html(evidenceBodyHtml()); }
    }

    // ------------------------------------------------------------------
    // Catalog health (step 1) — richer analysis from the measured products
    // ------------------------------------------------------------------
    function productPrimaryCategory(p) {
        const cats = p.categories;
        if (Array.isArray(cats) && cats.length) return String(cats[0]);
        return p.category ? String(p.category) : '';
    }
    function productMissingCount(p) {
        const feats = p.features || {};
        let n = 0;
        FEATURE_ORDER.forEach(function (k) { if (!feats[k]) n++; });
        return n;
    }
    function healthCounts(products) {
        let detailed = 0, thin = 0, noImages = 0, withReviews = 0, noBrand = 0;
        products.forEach(function (p) {
            const feats = p.features || {};
            const chars = parseInt(p.chars_long_desc, 10) || 0;
            if (feats.narrative) detailed++;
            if (chars < 300) thin++; // empty or very thin main description
            if ((parseInt(p.image_count, 10) || 0) < 3) noImages++;
            if ((parseInt(p.review_count, 10) || 0) > 0) withReviews++;
            if (!p.has_brand) noBrand++;
        });
        return { total: products.length, detailed: detailed, thin: thin, noImages: noImages, noReviews: products.length - withReviews, noBrand: noBrand };
    }
    function healthChips(products) {
        const s = healthCounts(products);
        const items = [
            { label: 'detailed descriptions', value: s.detailed, hint: 'Products with a rich, structured main description' },
            { label: 'thin or no description', value: s.thin, bad: s.thin > 0, hint: 'Products whose main description is empty or under ~300 characters' },
            { label: 'fewer than 3 images', value: s.noImages, bad: s.noImages > 0, hint: 'Products with fewer than 3 images' },
            { label: 'no customer reviews', value: s.noReviews, bad: s.noReviews > 0, hint: 'Products with no reviews captured' },
            { label: 'no brand defined', value: s.noBrand, bad: s.noBrand > 0, hint: 'Products with no brand taxonomy assigned' },
        ];
        return '<div class="optimasi-health-chips">' + items.map(function (it) {
            return '<span class="optimasi-health-chip' + (it.bad ? ' is-bad' : '') + '" title="' + escapeHtml(it.hint) + '">' +
                '<b>' + it.value + '</b> ' + escapeHtml(it.label) + '</span>';
        }).join('') + '</div>';
    }
    // Data rows for the "products that need the most work" block (shared by the
    // step-1 panel and the LLM-findings summary payload).
    function worstProductRows(products, n) {
        const limit = n || 5;
        const ranked = products.slice().sort(function (a, b) {
            const d = productMissingCount(b) - productMissingCount(a);
            if (d !== 0) return d;
            return (parseInt(a.chars_long_desc, 10) || 0) - (parseInt(b.chars_long_desc, 10) || 0);
        });
        return ranked.slice(0, limit).filter(function (p) { return productMissingCount(p) > 0; }).map(function (p) {
            return {
                title: String(p.title || '').slice(0, 120),
                edit_url: p.edit_url || '',
                missing: FEATURE_ORDER.filter(function (k) { return !(p.features || {})[k]; })
                    .map(function (k) { return FEATURE_LABELS[k]; }),
            };
        });
    }
    function worstProductsHtml(products, n) {
        const worst = worstProductRows(products, n);
        if (!worst.length) return '';
        const lis = worst.map(function (p) {
            return '<li><a href="' + escapeHtml(p.edit_url) + '" target="_blank">' + escapeHtml(p.title) + '</a> ' +
                '<span class="optimasi-worst-missing">missing ' + escapeHtml(p.missing.join(', ')) + '</span></li>';
        }).join('');
        return '<div class="optimasi-health-block"><h5>Start here — products that need the most work</h5>' +
            '<ul class="optimasi-worst-list">' + lis + '</ul></div>';
    }
    // Weakest categories by content completeness (shared by the step-1 panel and
    // the LLM-findings summary payload). Only meaningful with ≥2 categories.
    function weakestCategoryRows(products) {
        const byCat = {};
        products.forEach(function (p) {
            const c = productPrimaryCategory(p);
            if (!c) return;
            byCat[c] = byCat[c] || { total: 0, missing: 0, missingBy: {} };
            const g = byCat[c];
            g.total++;
            const feats = p.features || {};
            FEATURE_ORDER.forEach(function (k) {
                if (!feats[k]) { g.missing++; g.missingBy[k] = (g.missingBy[k] || 0) + 1; }
            });
        });
        const cats = Object.keys(byCat);
        if (!cats.length || cats.length < 2) return [];
        return cats.map(function (c) {
            const g = byCat[c];
            const pct = g.total ? Math.round((1 - g.missing / (g.total * FEATURE_ORDER.length)) * 100) : 0;
            const top = FEATURE_ORDER.map(function (k) { return { k: k, n: g.missingBy[k] || 0 }; })
                .filter(function (x) { return x.n > 0; })
                .sort(function (a, b) { return b.n - a.n; }).slice(0, 2)
                .map(function (x) { return FEATURE_LABELS[x.k]; });
            return { name: String(c).slice(0, 80), total: g.total, pct: pct, top: top };
        }).sort(function (a, b) { return a.pct - b.pct; }).slice(0, 3);
    }
    function categoryGapsHtml(products) {
        const ranked = weakestCategoryRows(products);
        if (!ranked.length) return '';
        const lis = ranked.map(function (r) {
            const missing = r.top.length ? ' — mainly ' + escapeHtml(r.top.join(' and ')) : '';
            return '<li><b>' + escapeHtml(r.name) + '</b> <span class="optimasi-cat-pct">' + r.pct + '% complete</span> across ' + r.total + ' product(s)' + missing + '</li>';
        }).join('');
        return '<div class="optimasi-health-block"><h5>Weakest categories</h5>' +
            '<ul class="optimasi-worst-list">' + lis + '</ul></div>';
    }
    function catalogHealthPanel(products) {
        let inner = healthChips(products);
        const parts = [];
        const cats = categoryGapsHtml(products);
        const worst = worstProductsHtml(products, 5);
        if (cats) parts.push(cats);
        if (worst) parts.push(worst);
        if (parts.length) inner += '<div class="optimasi-health-cols">' + parts.join('') + '</div>';
        return '<div class="optimasi-catalog-health">' + inner + '</div>';
    }

    // Website authority — is the store using its platform's blog for knowledge
    // content (buyer guides, how-tos, comparisons)? This is a SEPARATE read from
    // the product scan above: it measures articles, not product pages, and is
    // rendered as its own section so the two are never confused. Facts come from
    // the local scan (Step 1); the full explainer lives in the reports.
    function authorityPanel(a, catalogTotal) {
        if (!a) return '';
        const posts = parseInt(a.blog_posts, 10) || 0;
        const recent = parseInt(a.recent_posts_90d, 10) || 0;
        const inDepth = parseInt(a.in_depth_posts, 10) || 0;
        const total = parseInt(catalogTotal, 10) || 0;
        const cls = posts === 0 ? 'optimasi-bad' : (recent > 0 ? 'optimasi-ok' : 'optimasi-warn');
        let measured, note;
        if (posts === 0) {
            measured = 'No published blog or knowledge-content posts found.';
            note = (total ? 'Your ' + total + ' product pages are doing all the work. ' : 'Your product pages are doing all the work. ') +
                'Articles that answer buyer questions (how to choose, sizing, care, comparisons) build ' +
                'the authority search engines and AI assistants look for — most stores publish none, ' +
                'which makes this one of the biggest untapped wins you have.';
        } else if (recent === 0) {
            measured = posts + ' published post' + (posts === 1 ? '' : 's') + ' — none in the last 90 days.';
            note = 'The site has a blog but it looks dormant. Search engines reward stores that publish ' +
                'consistently, and every buyer question you answer becomes content that can rank ' +
                'and get cited.';
        } else {
            measured = posts + ' published post' + (posts === 1 ? '' : 's') + ', ' + recent +
                ' in the last 90 days' + (inDepth ? ' (' + inDepth + ' in-depth)' : '') + '.';
            const ratio = total ? posts / total : 0;
            if (total && ratio < 0.05) {
                const per = Math.max(1, Math.round(total / posts));
                note = 'That\'s roughly one article per ' + per + ' products across a ' + total +
                    '-product catalog — substantial scope to build the topical coverage search engines ' +
                    'and AI assistants reward. Map your top buyer questions (how to choose, sizing, care, ' +
                    'comparisons) to articles and link each one to the products it answers.';
            } else {
                note = 'You are already publishing — the opportunity is coverage: make sure every top ' +
                    'buyer question has an article, and link each one to the products it answers.';
            }
        }
        return '<section class="optimasi-report-section optimasi-authority ' + cls + '" id="opt-authority">' +
            '<div class="optimasi-authority-band">' +
            '<p class="optimasi-eyebrow"><span class="optimasi-eyebrow-num" aria-hidden="true">04</span>Blog &amp; knowledge content</p>' +
            '<h4 class="optimasi-section-title">Website authority</h4>' +
            '<p class="optimasi-section-lede">Blog articles and knowledge content — a separate measurement ' +
            'from the product scan above.</p>' +
            '<p class="optimasi-authority-measured">' + escapeHtml(measured) + '</p>' +
            '<p class="optimasi-authority-note">' + escapeHtml(note) + '</p>' +
            '<div class="optimasi-authority-detail" id="optimasi-authority-detail"></div>' +
            '</div>' +
            '</section>';
    }

    // Minimal inline-bold renderer for the shared server copy ("**20** posts").
    function inlineBold(text) {
        return escapeHtml(String(text || '')).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    }

    // Personalised Website-authority read, rendered from the shared server
    // explainer: the measured figure, the gap, the "what good looks like for
    // your store" paragraph (tailored to the vertical + buying model the
    // catalogue implies), the concrete article types and the cited benchmarks.
    // Deliberately server-side: the plugin never classifies the business. When
    // the service is unreachable the local measured/note above stays on screen.
    function renderAuthorityDetail(auth) {
        const $slot = $('#optimasi-authority-detail');
        if (!$slot.length || !auth) return;
        const $panel = $slot.closest('.optimasi-authority');
        const measured = String(auth.measured || '');
        const note = String(auth.note || '');
        const ideal = String(auth.ideal || '');
        const types = Array.isArray(auth.article_types) ? auth.article_types : [];

        if (measured) $panel.find('.optimasi-authority-measured').html(inlineBold(measured));
        if (note) $panel.find('.optimasi-authority-note').html(inlineBold(note));

        let html = '';
        if (ideal) {
            html += '<p class="optimasi-authority-ideal">' +
                '<strong>What good looks like for your store:</strong> ' + inlineBold(ideal) + '</p>';
        }
        if (types.length) {
            html += '<div class="optimasi-authority-types">' +
                '<p class="optimasi-authority-types-head">Article types that build authority for your store</p><ul>';
            types.forEach(function (t) { html += '<li>' + escapeHtml(String(t)) + '</li>'; });
            html += '</ul></div>';
        }
        (auth.stats || []).forEach(function (s) {
            const line = (s && typeof s === 'object' && s.line) ? s.line : (s ? String(s) : '');
            if (!line) return;
            html += '<p class="optimasi-authority-stat"><span class="optimasi-benchmark-tag">Industry benchmark</span> ' +
                escapeHtml(line) + '</p>';
        });
        $slot.html(html);
    }

    // ------------------------------------------------------------------
    // Audit findings (step 2 capstone) — LLM plain-language narrative.
    // Bounded payload: aggregate counts only (no description text). Loaded
    // after the Step-2 brand-voice dashboard renders; cached server-side.
    // ------------------------------------------------------------------
    function findingsPayload() {
        const products = (scanData && scanData.products) || [];
        if (!products.length) return null;
        const features = {};
        Object.keys(scanData.features || {}).forEach(function (k) {
            const f = scanData.features[k];
            if (f && f.total) features[k] = { pass: f.pass, total: f.total };
        });
        const h = healthCounts(products);
        const health = {
            detailed: h.detailed, thin: h.thin, no_images: h.noImages,
            no_reviews: h.noReviews, no_brand: h.noBrand,
        };
        const categories = weakestCategoryRows(products).map(function (r) {
            return { name: r.name, total: r.total, pct: r.pct };
        });
        const worst = worstProductRows(products, 5).map(function (p) {
            return { title: p.title, missing: p.missing };
        });
        const voice = (voiceResult && voiceResult.brand_voice) || {};
        const dups = (voiceResult && voiceResult.duplicates) || {};
        const readiness = (voiceResult && voiceResult.ai_readiness) || {};
        // Aggregate the per-product AI-search-readiness rubric (reassessed
        // metric: structured main description + direct answers — not short-desc
        // length). Bounded counts only; the explainer stays server-side.
        let aiGood = 0, aiWeak = 0, aiMissing = 0;
        (readiness.per_product || []).forEach(function (r) {
            const s = r.ai_ready;
            if (s === 'good') aiGood++;
            else if (s === 'weak') aiWeak++;
            else aiMissing++;
        });
        const aiTotal = (readiness.per_product || []).length;
        const aiReadiness = aiTotal > 0 ? {
            good: aiGood, weak: aiWeak, missing: aiMissing, total: aiTotal,
            summary: readiness.summary || '',
        } : {};
        return {
            scanned_total: scanData.total || products.length,
            completeness: scanData.completeness || 0,
            features: features,
            health: health,
            categories: categories,
            worst: worst,
            brand_voice: {
                status: voice.status || '',
                consistency_score: typeof voice.consistency_score === 'number' ? voice.consistency_score : null,
                summary: voice.summary || '',
            },
            duplicates: { flagged: dups.flagged || 0, sample_size: dups.checked || 0 },
            ai_readiness: aiReadiness,
        };
    }
    function findingsHtml(text) {
        if (!text) return '';
        const paragraphs = String(text).split(/\n{2,}/);
        return paragraphs.map(function (block) {
            const lines = block.split('\n').map(function (l) { return l.trim(); }).filter(Boolean);
            if (!lines.length) return '';
            // Bullet block -> <ul>; otherwise -> <p>.
            const isList = lines.every(function (l) { return l.indexOf('- ') === 0 || l.indexOf('•') === 0; });
            if (isList) {
                const lis = lines.map(function (l) {
                    const body = l.indexOf('- ') === 0 ? l.slice(2) : l.replace(/^•\s*/, '');
                    return '<li>' + escapeHtml(body) + '</li>';
                }).join('');
                return '<ul class="optimasi-findings-list">' + lis + '</ul>';
            }
            return '<p>' + escapeHtml(lines.join(' ')) + '</p>';
        }).join('');
    }
    function loadFindings() {
        const payload = findingsPayload();
        if (!payload) return;
        const $box = $('#optimasi-result-2');
        if ($box.find('.optimasi-findings').length) return; // already shown
        const panel =
            '<div class="optimasi-findings">' +
            '<p class="optimasi-findings-kicker">Optimasi consultant&rsquo;s read</p>' +
            '<h3>Audit findings</h3>' +
            '<div id="optimasi-findings-body"><div class="optimasi-findings-loader">' +
            '<span class="spinner is-active"></span> Writing the plain-language audit findings…' +
            '</div></div>' +
            '</div>';
        // Show the findings as the conclusion of the analysis, before the
        // "next step" call-to-action.
        const $hint = $box.find('.optimasi-next-hint').first();
        if ($hint.length) {
            $hint.before(panel);
        } else {
            $box.append(panel);
        }
        post('findings', { facts: JSON.stringify(payload) })
            .then(function (data) {
                const text = (data && data.text) ? data.text : '';
                if (text) {
                    findingsData = {
                        text: text.slice(0, 3000),
                        generated_by: (data && data.generated_by) || 'fallback',
                    };
                }
                const $body = $('#optimasi-findings-body');
                if ($body.length) {
                    $body.html(findingsHtml(text) || '<p class="description">Audit findings could not be generated for this catalog.</p>');
                }
            })
            .catch(function (err) {
                // `post()` rejects with an Error whose message already carries the
                // server's own reason, relayed from the service's `detail`/`message`.
                // Reading `err.responseJSON` here — as this did — always found nothing,
                // because that property only exists on a jQuery jqXHR, so a real
                // failure was reported as a generic line and the cause was lost.
                const why = (err && err.message) ? String(err.message) : '';
                const $body = $('#optimasi-findings-body');
                if ($body.length) {
                    $body.html('<p class="description">The plain-language findings are unavailable right now' +
                        (why ? ' — ' + escapeHtml(why) : '') +
                        '. The numbers above still tell the story.</p>');
                }
            });
    }

    // Step-2 takeaway — why the voice/duplicate signals matter.
    function step2FocusHtml(result) {
        const voice = result.brand_voice || {};
        const dups = result.duplicates || {};
        const notes = [];
        if (voice.status === 'inconsistent' || voice.status === 'absent') {
            notes.push('A ' + (voice.status === 'absent' ? 'missing' : 'mixed') + ' brand voice makes the catalog feel like several different stores — consistent tone is one of the cheapest trust and SEO wins.');
        }
        if (dups.flagged > 0) {
            notes.push(dups.flagged + ' product(s) repeat the same copy in the short and long description, which search engines can read as thin duplicate content.');
        }
        if (!notes.length) {
            notes.push('Your sampled products show a consistent voice and no duplicate-content flags — the next win is filling the missing content blocks from Step 1.');
        }
        return '<p class="optimasi-stage2-note"><strong>Why it matters:</strong> ' + escapeHtml(notes.join(' ')) + '</p>';
    }

    // Render the shared Step-2 brand-voice explainer card (measured read + why
    // it matters + cited stat + path). Same text the Summary PDF uses.
    function voiceInsightHtml(insight) {
        if (!insight) return '';
        let html = '<div class="optimasi-voice-insight">';
        if (insight.measured) {
            html += '<p class="optimasi-voice-measured">' + escapeHtml(insight.measured) + '</p>';
        }
        if (insight.why) {
            html += '<p class="optimasi-voice-why">' + escapeHtml(insight.why) + '</p>';
        }
        if (insight.stat) {
            html += '<p class="optimasi-issue-stat"><span class="optimasi-benchmark-tag">Industry benchmark</span> ' +
                escapeHtml(insight.stat) + '</p>';
        }
        if (insight.path) {
            html += '<p class="optimasi-voice-path"><strong>What we’d do:</strong> ' + escapeHtml(insight.path) + '</p>';
        }
        html += '</div>';
        return html;
    }

    // Shared duplicate-content insight card (measured + why + how + fix).
    function duplicateInsightHtml(insight) {
        if (!insight || !insight.measured) return '';
        let html = '<div class="optimasi-voice-insight">';
        html += '<p class="optimasi-voice-measured">' + escapeHtml(insight.measured) + '</p>';
        if (insight.why) html += '<p class="optimasi-voice-why">' + escapeHtml(insight.why) + '</p>';
        if (insight.how) html += '<p class="optimasi-issue-fix"><strong>How we detect it:</strong> ' + escapeHtml(insight.how) + '</p>';
        if (insight.fix) html += '<p class="optimasi-voice-path"><strong>What we’d do:</strong> ' + escapeHtml(insight.fix) + '</p>';
        html += '</div>';
        return html;
    }

    // Shared weighted-completeness "What this means for your store" panel.
    function catalogInsightHtml(insight) {
        if (!insight || !insight.measured) return '';
        return '<div class="optimasi-catalog-insight">' +
            '<p class="optimasi-voice-measured">' + escapeHtml(insight.measured) + '</p>' +
            (insight.note ? '<p class="optimasi-voice-why">' + escapeHtml(insight.note) + '</p>' : '') +
            '</div>';
    }

    // ------------------------------------------------------------------
    // Small shared fragments
    // ------------------------------------------------------------------
    function summaryPanel(data) {
        return '<div class="optimasi-analysis-summary" id="optimasi-summary-panel">' +
            summaryLocalHtml(data) +
            '</div>';
    }
    // Six-step severity scale for the "What this means" cards. Two buckets
    // (weak/medium) turned the section into one wall of red — and on a thin
    // catalog nearly every block lands under 10%, so a single "critical" bucket
    // would do the same. Resolution is therefore weighted to the bottom of the
    // range, where this metric actually lives. The top two steps still nest
    // inside the server's banding (WEAK_PCT 50 / STRONG_PCT 80).
    const SEVERITY = [
        { key: 'critical', label: 'Critical', range: '<2%', max: 2 },
        { key: 'severe', label: 'Severe', range: '2–9%', max: 10 },
        { key: 'weak', label: 'Weak', range: '10–29%', max: 30 },
        { key: 'fair', label: 'Fair', range: '30–49%', max: 50 },
        { key: 'good', label: 'Good', range: '50–79%', max: 80 },
        { key: 'strong', label: 'Strong', range: '80%+', max: Infinity }
    ];
    function severityTier(pct) {
        const n = Number(pct);
        const v = isNaN(n) ? 0 : n;
        for (let i = 0; i < SEVERITY.length; i++) {
            if (v < SEVERITY[i].max) return SEVERITY[i];
        }
        return SEVERITY[SEVERITY.length - 1];
    }
    // Legend so the colours are readable without relying on colour alone.
    function severityLegendHtml() {
        const chips = SEVERITY.map(function (s) {
            return '<span class="optimasi-sev-chip optimasi-sev-' + s.key + '">' +
                '<span class="optimasi-sev-dot" aria-hidden="true"></span>' +
                escapeHtml(s.label) + ' <span class="optimasi-sev-range">' + escapeHtml(s.range) + '</span></span>';
        }).join('');
        return '<p class="optimasi-sev-legend">' +
            '<span class="optimasi-sev-legend-label">Colour scale</span>' + chips + '</p>';
    }

    // Local (offline-safe) fallback body for the "What this means" panel. It is
    // replaced by the shared server explainers when loadExplainers() succeeds.
    // Deliberately per-block (not the old one-line "biggest gaps" summary) so
    // Step 1 still reads differently from v1.1.9 even when the Optimasi service
    // can't be reached — cited industry stats just won't appear.
    function summaryLocalHtml(data) {
        const feats = data.features || {};
        const total = data.total || 0;
        if (!total) return '';
        const featureLabels = {
            narrative: 'Rich description', brand_story: 'Brand storytelling', reviews: 'Review summaries',
            trust: 'Trust & disclaimers', faq: 'FAQ content', specs: 'Specs & attributes', images: 'Images'
        };
        const FEATURE_KEYS = ['narrative', 'brand_story', 'reviews', 'trust', 'faq', 'specs', 'images'];
        const rows = FEATURE_KEYS.filter(function (k) {
            return feats[k] && feats[k].total;
        }).map(function (key) {
            const f = feats[key];
            return { key: key, pct: f.pass / f.total, pass: f.pass, total: f.total };
        });
        const weak = rows.filter(function (r) { return r.pct < 0.5; });
        const strong = rows.filter(function (r) { return r.pct >= 0.8; });

        let html = severityLegendHtml();
        // Per-block issue cards (local, no cited stats) so the panel is clearly
        // the richer Step-1 even offline. Replaced with cited cards when the
        // explainer service responds.
        if (weak.length) {
            weak.forEach(function (r) {
                const label = featureLabels[r.key] || r.key;
                const pct = Math.round(r.pct * 100);
                const sev = severityTier(pct);
                html += '<div class="optimasi-issue-card is-' + sev.key + '">' +
                    '<div class="optimasi-issue-head">' +
                    '<span class="optimasi-issue-label">' + escapeHtml(label) +
                    '<span class="optimasi-sev-badge optimasi-sev-' + sev.key + '">' + escapeHtml(sev.label) + '</span></span>' +
                    '<span class="optimasi-issue-count">' + r.pass + ' of ' + r.total + ' products (' + pct + '%)</span>' +
                    '</div>' +
                    '</div>';
            });
        } else {
            html += '<p>Most content blocks are in place — <strong class="ok">little low-hanging fruit here</strong>.</p>';
        }
        if (strong.length) {
            html += '<div class="optimasi-issue-strong"><p class="optimasi-issue-strong-head">You’re doing well on</p><ul>';
            strong.forEach(function (r) {
                const label = featureLabels[r.key] || r.key;
                html += '<li>' + escapeHtml(label) + ' — in place across ' + r.pass + ' of ' + r.total + ' products.</li>';
            });
            html += '</ul></div>';
        }
        html += '<div class="optimasi-explainers-status is-loading" id="optimasi-explainers-status">' +
            '<span class="optimasi-status-spinner" aria-hidden="true"></span>' +
            '<div>' +
            '<p class="optimasi-status-title">Adding cited research to your findings…</p>' +
            '<p class="optimasi-status-sub">Optimasi is checking each content block and attaching real industry benchmarks to your report. This usually takes a few seconds.</p>' +
            '</div>' +
            '</div>';
        return html;
    }

    // Aggregate per-block coverage used by the explainer service (pass/total
    // counts only — no description text leaves the site in this pass).
    function explainerPayload() {
        const products = (scanData && scanData.products) || [];
        if (!products.length) return null;
        const features = {};
        Object.keys(scanData.features || {}).forEach(function (k) {
            const f = scanData.features[k];
            if (f && f.total) features[k] = { pass: f.pass, total: f.total };
        });
        return {
            features: features,
            scanned_total: scanData.total || products.length,
            completeness: scanData.completeness || 0,
            // Website-authority facts (published-post counts + catalogue facts).
            // Facts only — the personalised read is written by the Optimasi
            // service, which is also where the store classification lives.
            authority: (scanData && scanData.authority) ? scanData.authority : null,
        };
    }
    function loadExplainers() {
        const payload = explainerPayload();
        if (!payload || !Object.keys(payload.features).length) return;
        const $panel = $('#optimasi-summary-panel');
        if (!$panel.length) return;
        setExplainersStatus(
            'is-loading',
            '<span class="optimasi-status-spinner" aria-hidden="true"></span>' +
            '<div>' +
            '<p class="optimasi-status-title">Adding cited research to your findings…</p>' +
            '<p class="optimasi-status-sub">Optimasi is checking each content block and attaching real industry benchmarks to your report. This usually takes a few seconds.</p>' +
            '</div>'
        );
        post('explainers', { coverage: JSON.stringify(payload) })
            .then(function (data) {
                const cards = (data && data.explainers) ? data.explainers : null;
                if (cards) { renderExplainers(cards); return; }
                // Service reachable but returned no explainers — note it.
                noteExplainersUnavailable('The review service didn’t return detailed explainers.');
            })
            .catch(function (err) {
                // `post()` rejects with an Error that already carries the server's own
                // reason. This read `err.responseJSON`, which only exists on a jQuery
                // jqXHR — so it was always empty and every failure showed the generic
                // line below, with the cause thrown away.
                const serverMsg = (err && err.message) ? String(err.message) : '';
                noteExplainersUnavailable(serverMsg || 'Detailed explainers with cited stats couldn’t be loaded.');
            });
    }

    // Swap the summary panel's status block (spinner/loading vs error + retry).
    function setExplainersStatus(kind, innerHtml) {
        const $status = $('#optimasi-explainers-status');
        if (!$status.length) return;
        $status.removeClass('is-loading is-error').addClass(kind === 'is-error' ? 'is-error' : 'is-loading');
        $status.html(innerHtml);
    }

    // The per-block summary above is always rendered from the local scan; these
    // notes only explain why the server-authored cards aren't shown — clearly,
    // with the actual reason and a way to try again.
    function noteExplainersUnavailable(message) {
        setExplainersStatus('is-error',
            '<div>' +
            '<p class="optimasi-status-title">We couldn’t add the research</p>' +
            '<p class="optimasi-status-sub">' + escapeHtml(message) + '</p>' +
            '<button type="button" class="optimasi-retry-btn">Try again</button>' +
            '</div>'
        );
        const $btn = $('#optimasi-explainers-status .optimasi-retry-btn');
        if ($btn.length) { $btn.on('click', function () { loadExplainers(); }); }
    }
    // Render the shared explainer cards (deterministic text, verbatim from the
    // Optimasi service — the same text the Summary PDF uses).
    function renderExplainers(cards) {
        const $panel = $('#optimasi-summary-panel');
        if (!$panel.length) return;
        // The section heading is supplied by the report shell — no local title.
        let html = '';
        html += '<p class="optimasi-summary-overall">' + escapeHtml(cards.overall || '') + '</p>';
        html += severityLegendHtml();
        const issues = (cards.weak || []).concat(cards.medium || []);
        issues.forEach(function (card) {
            const sev = severityTier(card.pct);
            // Complementary cited benchmarks (server may return several).
            let stats = (Array.isArray(card.stats) && card.stats.length) ? card.stats : [];
            if (!stats.length && card.stat) stats = [{ line: card.stat }];
            let statsHtml = '';
            stats.forEach(function (s) {
                const line = (s && typeof s === 'object' && s.line) ? s.line : (s ? String(s) : '');
                if (!line) return;
                statsHtml += '<p class="optimasi-issue-stat"><span class="optimasi-benchmark-tag">Industry benchmark</span> ' +
                    escapeHtml(line) + '</p>';
            });
            html += '<div class="optimasi-issue-card is-' + sev.key + '">' +
                '<div class="optimasi-issue-head">' +
                '<span class="optimasi-issue-label">' + escapeHtml(card.label || '') +
                '<span class="optimasi-sev-badge optimasi-sev-' + sev.key + '">' + escapeHtml(sev.label) + '</span></span>' +
                '<span class="optimasi-issue-count">' + escapeHtml(card.measured || '') + '</span>' +
                '</div>' +
                (card.why ? '<p class="optimasi-issue-why">' + escapeHtml(card.why) + '</p>' : '') +
                statsHtml +
                (card.fix ? '<p class="optimasi-issue-fix"><strong>What we’d do:</strong> ' + escapeHtml(card.fix) + '</p>' : '') +
                '</div>';
        });
        const strong = cards.strong || [];
        if (strong.length) {
            html += '<div class="optimasi-issue-strong"><p class="optimasi-issue-strong-head">You’re doing well on</p><ul>';
            strong.forEach(function (line) {
                html += '<li>' + escapeHtml(line.line || '') + '</li>';
            });
            html += '</ul></div>';
        }
        if (!issues.length && !strong.length) {
            html += '<p class="description">No per-block breakdown available — the scan above still tells the story.</p>';
        }
        $panel.html(html);
        // The authority read is written server-side (personalised to the kind of
        // store the catalogue implies) — fill it in once the cards arrive.
        renderAuthorityDetail(cards.authority);
    }

    function nextHint(nextStep, text) {
        return '<div class="optimasi-next-hint"><span class="arrow">→</span> <span><strong>Next step:</strong> ' + escapeHtml(text) + '</span></div>';
    }

    // ------------------------------------------------------------------
    // Step 2 render — brand-voice report + AI-readiness (server insight)
    // ------------------------------------------------------------------
    function renderStep2(result) {
        const $box = $('#optimasi-result-2');
        const voice = result.brand_voice || {};
        const readiness = result.ai_readiness || {};
        const catalog = result.catalog || {};
        const dups = result.duplicates || {};
        // Brand voice is read from a representative sample (see analyse_voice);
        // make that explicit in the UI so numbers are never read as full-catalog.
        const scanTotal = (scanData && scanData.total) || 0;
        const sampleN = catalog.sample_size || 0;
        const sampled = sampleN > 0 && scanTotal > 0 && sampleN < scanTotal;
        const sampleLabel = sampled
            ? 'a representative sample of ' + sampleN + ' of your ' + scanTotal + ' products'
            : (scanTotal ? 'all ' + scanTotal + ' of your products' : 'your products');
        const voiceStatus = voice.status || 'absent';
        const voicePct = Math.max(4, Math.round((voice.consistency_score || 0) * 100));
        const voiceLabel = { consistent: 'Consistent', inconsistent: 'Mixed', absent: 'No consistent voice' }[voiceStatus] || voiceStatus;

        const score = catalog.overall_score || 0;
        const scoreClass = score > 70 ? 'optimasi-score-good' : (score > 40 ? 'optimasi-score-warning' : 'optimasi-score-critical');

        let readinessRows = '';
        (readiness.per_product || []).forEach(function (r) {
            readinessRows +=
                '<tr><td>' + escapeHtml(String(r.product_id)) + '</td>' +
                '<td>' + (r.has_summary ? 'Yes' : 'No') + '</td>' +
                '<td><span class="optimasi-ai-' + escapeHtml(r.ai_ready || 'missing') + '">' + escapeHtml(r.ai_ready || 'missing') + '</span></td>' +
                '<td>' + escapeHtml(r.reason || '') + '</td></tr>';
        });

        let dupRows = '';
        (dups.per_product || []).forEach(function (d) {
            if (d.status !== 'duplicate') return;
            dupRows +=
                '<tr><td>' + escapeHtml(d.title || ('#' + d.product_id)) + '</td>' +
                '<td>' + escapeHtml(d.reason || '') + '</td></tr>';
        });
        const dupSection = dups.checked > 0
            ? '<div class="optimasi-stage2-section">' +
              '<h3>Duplicate content</h3>' +
              (dups.flagged > 0
                  ? '<p><span class="optimasi-voice-pill optimasi-voice-absent">' + dups.flagged + ' flagged</span> ' +
                    (dups.flagged === 1 ? 'A product repeats' : dups.flagged + ' products repeat') +
                    ' the short description inside the main description — shoppers read the same copy twice and it is a duplicate-content risk' +
                    (sampled ? ' in the products we analysed' : '') + '.</p>' +
                    duplicateInsightHtml(result.duplicate_insight) +
                    '<details><summary>Which products</summary><div class="optimasi-evidence-scroll"><table class="optimasi-evidence-table">' +
                    '<thead><tr><th>Product</th><th>Issue</th></tr></thead><tbody>' + dupRows + '</tbody></table></div></details>'
                  : '<p><strong class="ok">No products repeat</strong> the short description inside the main description' +
                    (sampled ? ' in the products we analysed' : '') + '.</p>' +
                    duplicateInsightHtml(result.duplicate_insight)) +
              '</div>'
            : '';

        // Weighted-completeness "What this means for your store" panel.
        const catalogNote = catalogInsightHtml(result.catalog_insight) ||
            '<p class="description">Weighted completeness (Optimasi scoring, server-side): <strong>' + score + '/100</strong> across ' +
            (sampled ? 'a ' + sampleN + '-product sample of your ' + scanTotal + '-product catalog' : ((catalog.sample_size || scanTotal) + ' products')) + '.</p>';

        $box.html(
            '<div class="optimasi-report-dashboard">' +
            '<div class="optimasi-report-header">' +
            '<div class="optimasi-report-header-main">' +
            '<p class="optimasi-eyebrow">Brand &amp; duplicate analysis</p>' +
            '<h2 class="optimasi-report-title">Brand voice</h2>' +
            '<p class="optimasi-report-meta">Analysed from ' + sampleLabel + ' on Optimasi’s servers for a consistent tone and vocabulary.</p>' +
            '</div>' +
            '<div class="optimasi-score-block">' +
            '<div class="optimasi-score-badge ' + scoreClass + '" style="--pct:' + voicePct +
            '" role="img" aria-label="Brand voice consistency ' + voicePct + ' percent">' +
            '<span class="optimasi-score-value">' + voicePct + '<small>%</small></span>' +
            '</div>' +
            '<p class="optimasi-score-label">Consistency</p>' +
            '</div>' +
            '</div>' +
            '<div class="optimasi-stage2-section">' +
            '<h3>Is your copy consistent?</h3>' +
            '<div class="optimasi-voice-meter"><span class="optimasi-voice-fill optimasi-voice-' + escapeHtml(voiceStatus) + '" style="width:' + voicePct + '%"></span></div>' +
            '<p><span class="optimasi-voice-pill optimasi-voice-' + escapeHtml(voiceStatus) + '">' + escapeHtml(voiceLabel) + '</span> ' + escapeHtml(voice.summary || '') + '</p>' +
            voiceInsightHtml(result.voice_insight) +
            '</div>' +

            '<div class="optimasi-stage2-section">' +
            '<h3>AI-search ready content</h3>' +
            '<p>' + escapeHtml(readiness.summary || '') + '</p>' +
            (readiness.explainer
                ? '<p class="description optimasi-ai-explainer"><strong>How we scored this:</strong> ' +
                  escapeHtml(readiness.explainer) + '</p>'
                : '') +
            '<details><summary>Per product</summary><div class="optimasi-evidence-scroll"><table class="optimasi-evidence-table">' +
            '<thead><tr><th>Product</th><th>Has summary</th><th>AI-search ready</th><th>Reason</th></tr></thead>' +
            '<tbody>' + readinessRows + '</tbody></table></div></details>' +
            '</div>' +

            dupSection +
            '<div class="optimasi-stage2-section">' +
            '<h3>What this means for your store</h3>' +
            catalogNote +
            step2FocusHtml(result) +
            '</div>' +
            '</div>' +
            nextHint(3, 'Your Sales-Ready report — the full four-page inspection is just below')
        );
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
    }

    // ------------------------------------------------------------------
    }

    function boot() {
        if (!window.jQuery) {
            return false;
        }

        window.jQuery(document).ready(function ($) {
            start($);
        });

        return true;
    }

    if (!boot()) {
        // The script was parsed before jQuery. Retry once the document is ready;
        // by then every synchronous header script has run.
        document.addEventListener('DOMContentLoaded', boot);
    }
})();
