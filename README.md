# Optimasi Content Audit — OpenCart 4

A free, four-step, **read-only** content review for an OpenCart catalogue. Upload one
`.ocmod.zip`, open one page, and get a four-page Sales-Ready report about your own
product content — then have the full executive report emailed to you — without writing
anything to the store and without an account.

| Step | What happens | Where it runs |
|---|---|---|
| 1. Scan your products | measures **every** published product — description length and headings, images, reviews, brand, categories, attributes, and which content blocks each one carries | **your own store** — nothing leaves it |
| 2. Analyse brand voice | sends a short sample of descriptions to measure tone/vocabulary consistency, AI-readiness and duplicate copy | Optimasi's servers — **only after you tick the consent box** |
| 3. Your Sales-Ready report | the four-page Sales-Ready Standard Inspection Report: key findings, results by category, prioritised roadmap | Optimasi's servers |
| **Email the executive report** | from the report page: confirm your email address and receive the **10–11 page executive report** as a PDF — the whole-catalogue analysis, the evidence sample, what each finding costs you, the consultant's read and the full roadmap. A longer, more detailed document than the four-page report above — not a variant of it | Optimasi's servers — the address you enter, and nothing else |
| 4. See How Optimasi Works | opens a video page in a new tab | nothing is sent |

**Nothing is written to your store.** No tables, no events, no catalogue code, no product
data, nothing on the storefront. The extension keeps five settings of its own — status,
endpoint, the remembered consent decision, the last report's reference and its date — and
reads everything else.

## How Optimasi works

**Watch the walkthrough: <https://optimasi.io/how-it-works/>**

Step 4 of the audit opens this page. The walkthrough explains how Optimasi closes the
gaps the audit measures, so you can see what comes after the report before deciding
whether you want any of it.

## Install

1. Download `optimasi_onboarding.ocmod.zip` from the [latest release](https://github.com/cybernamix/optimasi-onboarding-opencart/releases/latest).
2. **Extensions → Installer → Upload** the zip, then press the install button.
3. **Extensions → Extensions → Modules** → find **Optimasi Content Audit** → **Install** (+).
4. Open its settings page. The page *is* the review; there is nothing else to configure.

The display name and the extension *code* are deliberately different things. The code is
`optimasi_onboarding`, and it is derived from the zip's filename — which is why the asset
name must not be changed.

Upload through the Installer rather than extracting the zip on the server: the Installer
sets correct file permissions and registers the extension, while a raw `unzip` does
neither.

## Requirements

- OpenCart 4.0.x, PHP 8.0 or later with the `curl` and `mbstring` extensions.
- `modify` permission on the module for whoever runs the review.
- Outbound HTTPS to Optimasi's review service for steps 2 and 3. Step 1 needs no network
  at all.

`install()` grants no permissions of its own, so a user who is not a superadmin needs
**access and modify** on `extension/optimasi_onboarding/module/onboarding`.

## What it sends, and when

Step 1 sends nothing at all. Step 2 sends a short sample (up to 50 products) of
description text, and is **refused server-side** without the consent checkbox on that
step — unticking it later, or calling the action directly, cannot bypass it. Step 3 sends
the review snapshot once and then fetches the report by reference. Step 4 opens a link
and sends nothing.

Customer records, orders, addresses, payment details and customer email addresses are
never read.

## Uninstall

**Extensions → Extensions → Modules → Optimasi Content Audit → Uninstall** (−). That
removes the extension's own five settings and nothing else. There is no other data to
lose, because the extension never wrote any.

Upgrading is the same uninstall/install pair OpenCart performs for any extension, so the
remembered consent is asked for again (once) and the last report's reference is gone.

## Licence

GPL-2.0-or-later. The full licence text is in [`LICENSE`](LICENSE) at the root of this
repository.

## Release history

## 1.0.15

- **Step 1 says what it measured, so a fast scan reads as a measurement.** The scan is a
  local read of the store's own tables — no page is crawled — so a small catalogue really
  does finish in about a second. The result now states the work beside the time: how many
  products, how many content checks across how many blocks, and how long the read took
  (`640 content checks across 8 blocks, read from your store's own database in 1.4 s`).
  The duration is not padded to look busier; that would be the one dishonest fix and it
  would stop being true on a large catalogue.
- **The email card names the report it sends.** *Email the full report* is now *Email me a
  comprehensive Executive Store Analysis report for deeper insights*, and the description
  says what is inside — the readiness score, findings by category, the significant content
  gaps and the prioritised roadmap.
- **The emailed document is the executive report, not the four-page one.** The two are now
  distinct artefacts by design: four pages on the page for the free read, and 10–11 pages
  by email — whole-catalogue health, the evidence sample behind each finding, the
  consultant's read and the full roadmap. Previously the download/email path preferred the
  four-page template and fell back to the full report only when the host had no browser
  engine, so the same link delivered a 4-page summary on one machine and an 11-page report
  on another. Both are rendered from the same stored snapshot, so they cannot contradict
  each other.
- **Step 3 no longer tells you to print.** The line under the report offered "Print or save
  it as a PDF" — a control that was removed because it never worked: printing from the
  sandboxed frame is blocked and the frame has no layout to paginate. The PDF comes from
  the email card; the line now says so, and names the executive report.

## 1.0.14

- **The email card works, and says why when it cannot.** The card submitted a button that
  was not a submit button, under a form that cancelled submission, so the handler never
  ran and clicking did nothing. It is a submit button now.
- **An absent report is refused by name instead of by status code.** A card that sent no
  review data was answered with a bare `Optimasi API HTTP 422`; the page now says the
  review data was not included and to run the review again. The service also accepts the
  `[]` a PHP plugin sends when it means "nothing", rather than reading it as a wrong shape.
- **"Request a one-on-one" answers.** Its click was bound to a submit path that no longer
  fired, and its contact call read the report token from a cache that does not hold
  extension settings — so the request went out with no token and was dropped. Both fixed.
- **The email field steps aside for a number.** Choosing WhatsApp or Viber hides the email
  field rather than asking for an address that will not be used; the value is kept if the
  visitor changes their mind.
- **Copy:** "Email is fine — just send me a link" is now "Email is fine — send me a message".

## 1.0.13

- **Step 3 no longer fails with an HTML `400 Bad Request`.** The capture put the whole
  catalogue's product rows in the request body — a few hundred kilobytes on a large
  store — which is past the request-body limit a shared host sets, so Apache refused the
  request before OpenCart ever saw it. The rows sent are now the worst forty; the figures
  that describe the whole catalogue travel beside them as aggregates.
- **The report's numbers stay whole-catalogue numbers.** The health chips, weakest
  categories, worst products and AI-readiness counts are computed over every product, so a
  short row list can never be reported as a short catalogue. The service prefers those
  aggregates over re-deriving them from the sample.
- **A capture that failed now says so.** The previous version reported the service's own
  refusal instead of the "no report yet" it caused, which is how this one became visible.

## 1.0.12

- **Step 3 no longer says "run the review first" to a merchant who has just run it.** The
  report token was written to the `setting` table and then read back from `config` — a
  per-request cache that this controller never fills — so the write was correct, the row
  was correct, and the step still found nothing. Every settings read now asks the row, with
  the cache as a fallback, so a token survives from the capture to the request that asks
  for it.
- **The same read path fixes remembered consent and the "previous report" offer.** Both are
  read on a request of their own, and both were reading the same empty cache: consent was
  asked for again on every run, and a report stored by an earlier review was never offered.
- **Writing one setting can no longer blank the others.** A write re-sends the whole group,
  and the siblings were being taken from that cache too — so a store whose endpoint and
  consent were only in the table had them overwritten with an empty value and a zero.
- **The report itself now travels in the request body**, not as one form field: a full
  catalogue is up to a thousand product rows, which as a single value depends on whatever
  ceiling the host sets and as nested fields runs past `max_input_vars`.
- **A capture that the service refused is reported, not swallowed.** Step 3 shows the
  service's own reason instead of the "no report yet" it caused, which is the difference
  between a diagnosis and a guess.

## 1.0.11

- The two report sections no longer fail with a 422 that reads **"run the scan first"**.
  The page built each payload from the scan it had just run and posted it as one JSON
  string; when that single value did not arrive, the controller forwarded an empty
  payload, and the service answered — accurately — that there was no coverage to
  explain. The payload now travels as nested form fields, so no single large value
  decides whether the step works.
- An empty payload is refused by the extension itself, with a message that names the
  page rather than the scan: *"The review data did not reach the server — reload the
  page and run the scan again."* The step no longer calls the service with nothing,
  which is what turned a delivery fault into a misleading "run the scan first".
- The JSON-string body is still accepted, so a browser holding a cached copy of the
  previous page keeps working against the updated server.

## 1.0.10

- The Extensions list no longer reports the module as **Disabled**. Its settings were
  written under `module_optimasi_onboarding_*`, but OpenCart draws that column from
  `module_<code>_status`, where `<code>` is the controller's own file name —
  `onboarding`. The key it looked for did not exist, so the module read as disabled
  however it was configured.
- The settings now live under the code OpenCart uses, `module_onboarding`. An uninstall
  removes them for the same reason: OpenCart deletes `code = 'module_onboarding'`, and
  the old spelling was invisible to that as well.
- A store upgrading by replacing the files keeps its endpoint, consent and report token.
  The previous version's rows are adopted once, on the first page load, and then removed,
  so each key has one home rather than two that can disagree.

## 1.0.9

- The review now covers the whole published catalogue. Step 1 stopped at the first
  1,000 products, so a larger store was measured as a 1,000-product store: the figure
  reported was the cap rather than the catalogue, and every completeness percentage was
  an average over a slice. The cap existed twice — a constant in the controller and a
  `min(1000, …)` clamp in the scanner — plus a `LIMIT` on the query and three pieces of
  copy; all four are gone.
- The brand-voice step still sends an even spread of 50 descriptions, whatever the
  catalogue size. That is the only step that ships description text off the store, so
  its cost does not grow with the catalogue.
- The scan's time budget is now the bound instead: 600 seconds, with the queries batched
  as before.

## 1.0.8

- Product, category and brand names are decoded once in the scanner, so a title
  stored as `Black &amp; Red Ribbon` reaches the report as `Black & Red Ribbon`.
- The consent box on step 2 is ticked by default; unticking still refuses the send
  server-side.
- The Status block at the foot of the page is removed.
- The two server-side steps (cited research, audit findings) now report the
  service's own reason. They read `err.responseJSON`, which does not exist on the
  error the page is given, so every failure showed a generic line and the cause was
  discarded.

## 1.0.7

The endpoint is no longer shown anywhere on the page — not just the panel.

- The Status read loses its **Review service** row, which was the last place a
  merchant could see the endpoint.
- `index()` publishes no endpoint value at all unless the panel is switched on, so a
  hidden panel leaves nothing in the page source either.
- `error_no_endpoint` no longer names an internal constant; it tells the merchant to
  contact support.

## 1.0.6

- The **Review service endpoint** panel is hidden, matching the WooCommerce plugin.
  The extension ships a working default, so the field is a support/agency tool and
  not something a merchant should be offered — a wrong value in it silently breaks
  steps 2 and 3.
- Opt in per store with `define('OPTIMASI_ONBOARDING_SHOW_ADVANCED', true);` in
  `admin/config.php`. The setting, the `save` action and the validation all remain,
  so a store with an existing override keeps working and nothing needs migrating.

## 1.0.5

Fixes "Call to a member function scan() on null" on step 1.

- The model is read from the registry instead of from `load->model()`'s return
  value. OpenCart 4.1 declares that method `: void` — 4.0 returned the model — so
  the return value is `null` and the scan could not run at all.
- The scan's error guard now catches `\Exception` only. A failing query arrives as
  one and is named on the page; an `\Error` is a defect in the code and is left to
  raise rather than be reported as a data problem.

## 1.0.4

Fixes every step failing with "Server communication failed (HTTP 200)".

- The URLs the page hands to its script are decoded before they are emitted. They
  are written into a `<script>` block, where HTML entities are not decoded, so
  `Url::link()`'s `&amp;` was being sent literally — `amp;user_token=…` — and
  OpenCart received no token and answered with the login page at HTTP 200.
- Links written into HTML attributes are unchanged and still escaped, which is
  correct in an `href`.
- New suite `tests/admin_url_test.php` proves both directions, and fails if the
  decoding is removed.

## 1.0.3

Makes an unparseable response name itself instead of reporting "HTTP 200".

- Every JSON action stops stray server output reaching the body: `display_errors` is
  turned off for the response and anything already buffered is discarded. Diagnostics
  still reach the log. A warning printed before the payload used to turn a working
  action into "Server communication failed (HTTP 200)", which names no cause.
- A body that cannot be encoded is reported in the normal envelope, with the reason,
  rather than sent empty — an empty body is indistinguishable from no response.
- When the browser cannot parse a response it now shows the first 300 characters of
  what the server actually sent.

## 1.0.2

Fixes an install that hangs on OpenCart 4.1, and makes a failing step 1 say why.

- The module's settings are written per key instead of through
  `model_setting_setting->editSetting()`. On 4.1 that method writes `serialized`
  as an empty string for every scalar value, which a strict-mode MySQL rejects —
  so the write threw after OpenCart had already recorded the extension as
  installed.
- Install and uninstall check `extension/module`, the route OpenCart's own
  installer validates before calling into the extension, instead of the unrelated
  `marketplace/extension`.
- Step 1 reports the reason for a database failure instead of answering with a
  bare HTTP 500.

## 1.0.1

- One report action instead of two: **Open or print in a new tab** replaces the separate
  Print / Save as PDF button and the plain "open" link.
- A report kept from an earlier review is no longer re-rendered on page load. The step
  shows a provenance line with the review date and a **Show that report** button instead.
- The email card is now **Email the full report**, with a second paragraph explaining what
  the full report contains, and it stays hidden until a snapshot has been sent.
- A stored report reference with no recorded date reads "from an earlier review" rather
  than showing a blank date.

## 1.0.0

First public release.

- Four-step read-only content review: product scan, brand-voice analysis, Sales-Ready
  report, and a link to the walkthrough.
- Step 1 measures up to 1,000 published products and sends nothing off-site.
- Step 2 is gated on a consent checkbox that is remembered once given and enforced
  server-side.
- Step 3 keeps the report reference server-side and serves the report through a
  sandboxed frame, so the embedded copy cannot run script.
- No tables, no events, no catalogue code — the extension reads and reports.
