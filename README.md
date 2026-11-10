# Optimasi Content Audit — OpenCart 4

A free, four-step, **read-only** content review for an OpenCart catalogue. Upload one
`.ocmod.zip`, open one page, and get a four-page Sales-Ready report about your own
product content — without writing anything to the store and without an account.

| Step | What happens | Where it runs |
|---|---|---|
| 1. Scan your products | measures up to 1,000 published products: description length and headings, images, reviews, brand, categories, attributes, and which content blocks each one carries | **your own store** — nothing leaves it |
| 2. Analyse brand voice | sends a short sample of descriptions to measure tone/vocabulary consistency, AI-readiness and duplicate copy | Optimasi's servers — **only after you tick the consent box** |
| 3. Your Sales-Ready report | the four-page Sales-Ready Standard Inspection Report: key findings, results by category, prioritised roadmap | Optimasi's servers |
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
