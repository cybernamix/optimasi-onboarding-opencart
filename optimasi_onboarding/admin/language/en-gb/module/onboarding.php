<?php
/**
 * Optimasi Content Audit — language strings.
 *
 * Every string the page shows lives here, including the offer panel's channel
 * and role labels, because nothing is hard-coded in the template or the script
 * (a hard-coded label is a string a translator cannot reach, and the two
 * platforms are supposed to read identically).
 *
 * The wording matches the WooCommerce plugin of the same name, with the platform
 * named in the body copy rather than the heading — the heading is deliberately
 * platform-neutral so both products read as one product.
 */

// Heading.
$_['heading_title']       = 'Optimasi Content Audit';
$_['heading_audit']       = 'Optimasi Ecommerce Content Completeness Audit';
$_['text_lead']           = 'A free 4-step review of your product content — nothing is written to your store and no registration is required.';
$_['text_version']        = 'Version';

// OpenCart chrome.
$_['text_edit']           = 'Content Audit settings';
$_['text_extension']      = 'Extensions';
$_['text_home']           = 'Home';
$_['text_success']        = 'Success: You have modified the Optimasi Content Audit settings.';
$_['error_permission']    = 'Warning: You do not have permission to modify the Optimasi Content Audit.';

// Step 1 — scan (local, nothing leaves the store).
$_['step1_title']         = 'Scan your products';
$_['step1_desc']          = 'We measure up to the first 1,000 of your published products right on your store — description length, image count, reviews, and which content blocks each product has. Nothing is sent to Optimasi during this step.';
$_['step1_button']        = 'Scan my products';

// Step 2 — brand voice (the only step that sends description text).
$_['step2_title']         = 'Analyse your brand voice';
$_['step2_desc']          = 'We send a short sample of each scanned product description to Optimasi\'s servers and check whether your tone and vocabulary are consistent across the catalogue.';
$_['step2_button']        = 'Analyse brand voice';
$_['step2_consent']       = 'I agree to send a short sample of my product descriptions to Optimasi for this analysis.';

// Step 3 — the report.
$_['step3_title']         = 'Your Sales-Ready Standard Inspection Report';
$_['step3_desc']          = 'Your full four-page report — key findings, results by category and a prioritised optimisation roadmap. Print or save it as a PDF, or open it in a new tab.';
$_['step3_open']          = 'Open or print in a new tab';
$_['step3_frame_title']   = 'Sales-Ready Standard Inspection Report';
// Provenance for a report kept from an earlier review. The date is substituted by the
// script, which is why only the sentence lives here.
$_['report_previous_button']       = 'Show that report';
$_['report_previous_with_date']    = 'You have a report from your review on %s. Run steps 1 and 2 again for one that reflects your catalogue today.';
$_['report_previous_without_date'] = 'You have a report from an earlier review. Run steps 1 and 2 again for one that reflects your catalogue today.';

// Step 4 — the how-it-works link (no request is made).
$_['step4_title']         = 'See How Optimasi Works';
$_['step4_desc']          = 'Watch a video explaining how Optimasi can help your OpenCart store with Sales-ready pages optimised for AI search.';
$_['step4_button']        = 'See how it works';

// Email card (revealed with the report).
$_['text_email_heading']       = 'Email the full report';
$_['text_email_desc']          = 'The full report is the document worth keeping, and it is not the four-page summary on this page. It opens with an executive summary you can forward to whoever signs off on content, then the consultant\'s read: what is working, where the opportunity is, what the brand-voice and duplicate-copy analysis found, and the recommended plan, set out in full rather than summarised.';
$_['text_email_how']           = 'Enter your email and we will send you a confirmation link, then the PDF. No spam, and you can unsubscribe at any time.';
$_['text_email_placeholder']   = 'you@yourstore.com';
$_['text_email_button']        = 'Email me the full report';

// Offer panel.
$_['text_help_heading']        = 'How Optimasi can help';
$_['text_help_copy1']          = 'Your report shows what is missing. Optimasi closes the gap: we write the descriptions, brand storytelling, FAQs, review summaries and trust content your catalogue needs, in your brand voice, and apply it to your product pages. Most stores see that as minutes per product rather than the hours it takes to write from scratch — and because every block is grounded in your own product facts and spec sheets, nothing is invented.';
$_['text_help_copy2']          = 'We can work through the whole roadmap above, or start with the one category costing you the most. Either way you keep control: nothing is published without your review.';
$_['text_help_legend']         = 'I\'d prefer to talk it through on';
$_['text_help_number']         = 'Your number (international format)';
$_['text_help_number_ph']      = '+61 4xx xxx xxx';
$_['text_help_email']          = 'Your email (so we can reach you)';
$_['text_help_role']           = 'I am (optional)';
$_['text_help_role_none']      = 'Select…';
$_['text_help_submit']         = 'Request a one-on-one';
$_['text_help_privacy']        = 'We use what you enter here only to contact you about your store, as described in the %s.';
$_['text_help_privacy_link']   = 'Optimasi privacy policy';

// Contact channels. The KEYS are the backend's contract (see _CONTACT_CHANNELS
// in the API) — changing one here without changing it there makes the request
// fail validation.
$_['text_channel_google_meet'] = 'Google Meet';
$_['text_channel_ms_teams']    = 'Microsoft Teams call';
$_['text_channel_whatsapp']    = 'WhatsApp';
$_['text_channel_viber']       = 'Viber';
$_['text_channel_email']       = 'Email is fine — just send me a link';

// Roles.
$_['text_role_agency']                 = 'An agency';
$_['text_role_store_owner']            = 'A store owner';
$_['text_role_ecommerce_professional'] = 'An ecommerce professional';
$_['text_role_store_manager']          = 'A store manager';

// Settings.
$_['text_settings_heading']  = 'Review service endpoint';
$_['text_settings_help']     = 'Where this free review runs. Leave blank to use the default service (%s). Set this only to point a test or agency store at a different instance — a wrong value here breaks steps 2, 3 and the email card, and nothing on the page will say why.';
$_['entry_api_url']          = 'Review service endpoint';
$_['button_save_endpoint']   = 'Save endpoint';
$_['button_save']            = 'Save';
$_['button_back']            = 'Back';

// Shown on the settings page so an admin can answer "why does step 2 not work"
// without reading the journal. The endpoint is not among these: a merchant cannot
// act on it, so nothing on the page names it.
$_['text_health_heading']    = 'Status';
$_['text_health_consent']    = 'Description samples';
$_['text_health_consent_yes'] = 'Consent recorded';
$_['text_health_consent_no'] = 'Not yet given';
$_['text_health_token']      = 'Last report';
$_['text_health_token_yes']  = 'Available — the report re-opens on reload';
$_['text_health_token_no']   = 'None yet';

// Endpoint save confirmations.
$_['text_endpoint_saved']    = 'Review service endpoint saved.';
$_['text_endpoint_default']  = 'Using the default Optimasi endpoint.';

// Errors. These are the messages the merchant reads on the page, so they say
// what to do next rather than naming an internal state.
$_['error_permission']          = 'Warning: You do not have permission to modify the Optimasi Content Audit.';
$_['error_consent_required']    = 'Please tick the box to allow a short sample of your product descriptions to be sent to Optimasi for analysis.';
$_['error_unreachable']         = 'Could not reach the Optimasi service.';
$_['error_analyse_failed']      = 'Could not analyse your brand voice.';
$_['error_explainers_failed']   = 'Could not load the content explainers.';
$_['error_findings_failed']     = 'Could not write the audit findings.';
$_['error_report_unavailable']  = 'Your report could not be loaded right now.';
$_['error_no_report']           = 'No report is available yet — run the review first.';
$_['error_contact_no_report']   = 'Run the review first — there is no report to talk about yet.';
$_['error_invalid_channel']     = 'Choose how you\'d like us to reach you.';
$_['error_number_required']     = 'Please add your number in international format, e.g. +61 4xx xxx xxx.';
$_['error_invalid_email']       = 'Please enter a valid email address.';
$_['error_email_failed']        = 'Could not save your report request.';
$_['error_contact_failed']      = 'We could not record your request — please try again.';
$_['error_invalid_url']         = 'Enter a full URL starting with https://';
$_['error_no_curl']             = 'The PHP cURL extension is not enabled on this server, so the Optimasi review service cannot be reached.';
$_['error_no_endpoint']         = 'This store has no review service configured, so the review cannot run. Please contact Optimasi support.';
