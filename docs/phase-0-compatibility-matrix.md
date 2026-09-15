# JunctionTools Phase 0 — 35-Tool Compatibility Matrix

## Verification status

**Static verification: PASS.**

Public `scanner.php` requests now route to `scanner-compat.php`. The shim preserves the legacy local contracts for Pixel Diagnostic, Checkout Funnel, Cart Abandonment, and Schema Validator while delegating all other scanner types to the hardened `scanner-secure.php` implementation. Outbound requests use the SSRF-safe HTTP client with TLS verification, redirects disabled, timeouts, response-size limits, and DNS/private-address validation.

The SSL Checker now obtains certificate issuer and expiry metadata from the same TLS-verified cURL connection using `CURLOPT_CERTINFO`. No second raw socket or unrestricted DNS operation is used, preserving the SSRF boundary.

This is source/static verification only. No runtime/CI test is being claimed because the PR head has no registered status checks.

## Matrix

| # | Tool | Scanner type | Status | Notes |
|---:|---|---|---|---|
| 1 | PageSpeed Analyzer | External API | N/A | No scanner dependency. |
| 2 | Image Compressor | Client-side | N/A | No scanner dependency. |
| 3 | Pixel Diagnostic | `pixels` | **PASS** | Compatibility shim restores `pixels` and `pixels_found`; outbound fetch is hardened. |
| 4 | GA4 Event Code Generator | Client-side | N/A | No scanner dependency. |
| 5 | CSS/JS Minifier | Client-side | N/A | No scanner dependency. |
| 6 | ARIA & Alt Audit | `aria_audit` | **PASS*** | Contract preserved; redirects intentionally disabled for SSRF protection. |
| 7 | Mobile Viewport UX | `mobile_audit` | **PASS*** | Contract preserved; redirects intentionally disabled. |
| 8 | UX Layout Evaluator | `ux_evaluator` | **PASS*** | Contract preserved; redirects intentionally disabled. |
| 9 | Cross-Browser Check | `browser_checker` | **PASS*** | Contract preserved. |
| 10 | Meta SEO Checker | `seo_auditor` | **PASS*** | Core response preserved; robots.txt sub-check remains reduced. |
| 11 | Schema Validator | `schema_validator` | **PASS** | Shim supports both raw JSON-LD payload and URL modes. |
| 12 | Checkout Funnel Analyzer | `friction_analyzer` | **PASS** | Local calculation handled before any outbound request. |
| 13 | Cart Loss Calculator | `cart_abandonment` | **PASS** | Local calculation handled before any outbound request. |
| 14 | CTA & Headline Analyzer | `cta_analyzer` | **PASS** | Existing `score`/`message` contract preserved. |
| 15 | Trust Badge Inspector | `trust_inspector` | **PASS** | Existing contract preserved. |
| 16 | SSL & Security Checker | `ssl_audit` | **PASS** | Certificate issuer, expiry date, and `daysRemaining` are restored through the SSRF-safe cURL/TLS connection; no raw socket fallback. |
| 17 | Invoice Generator | Client-side | N/A | No scanner dependency. |
| 18 | Discount Calculator | Client-side | N/A | No scanner dependency. |
| 19 | Aspect Ratio Calculator | Client-side | N/A | No scanner dependency. |
| 20 | Color Palette Generator | Client-side | N/A | No scanner dependency. |
| 21 | Contrast Checker | `wcag_checker` | **PASS** | Existing UI contract preserved. |
| 22 | Social Asset Optimizer | Client-side | N/A | No scanner dependency. |
| 23 | Product Copy Analyzer | `copy_analyzer` | **PASS** | Existing UI contract preserved. |
| 24 | Readability Evaluator | `readability_evaluator` | **PASS** | Existing UI contract preserved. |
| 25 | Bio Font Generator | Client-side | N/A | No scanner dependency. |
| 26 | Clean Text Tool | Client-side | N/A | No scanner dependency. |
| 27 | Case Converter | Client-side | N/A | No scanner dependency. |
| 28 | Word Counter | Client-side | N/A | No scanner dependency. |
| 29 | PX to REM | Client-side | N/A | No scanner dependency. |
| 30 | Base64 Converter | Client-side | N/A | No scanner dependency. |
| 31 | JSON Formatter | Client-side | N/A | No scanner dependency. |
| 32 | Regex Tester | Client-side | N/A | No scanner dependency. |
| 33 | SHA-256 Hash | Client-side | N/A | No scanner dependency. |
| 34 | UUID Generator | Client-side | N/A | No scanner dependency. |
| 35 | WhatsApp Link | Client-side | N/A | No scanner dependency. |

## Current result

- **35/35 tools:** PASS or N/A.
- **0/35:** PARTIAL.
- **Previous four compatibility blockers:** resolved by `scanner-compat.php`.
- **SSL parity:** issuer, expiry date, and remaining-days metadata restored.
- **Security posture:** preserved; public scanner requests no longer execute the legacy monolithic outbound-fetch implementation.

## Static verification notes

- URL validation remains the first boundary for all outbound requests.
- HTTPS certificate metadata is collected only when explicitly requested by `certificate_info => true`.
- Certificate metadata comes from the same cURL handle that performed the verified HTTPS request.
- Redirects remain disabled.
- TLS peer and hostname verification remain enabled.
- No unrestricted `stream_socket_client` or raw certificate socket fallback was introduced.
- Certificate parsing failures degrade metadata to unavailable values rather than weakening TLS validation.
- Runtime/CI execution is still not claimed; PR #1 currently has no registered status checks.
