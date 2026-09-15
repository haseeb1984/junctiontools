# JunctionTools Phase 0 — 35-Tool Compatibility Matrix

## Scope

Static compatibility review of the `security/phase-0-hardening` scanner replacement against the 35 tools exposed by the current navigation. This matrix compares the legacy `scanner.php` contract with `scanner-secure.php` and identifies items that must be fixed before PR #1 can be merged.

## Static verification result

**Security/static review: PASS WITH COMPATIBILITY BLOCKERS.**

Verified:

- `scanner-secure.php` is the public scanner implementation and disables PHP error display.
- `.htaccess` internally routes public `scanner.php` requests to `scanner-secure.php`.
- Secure outbound requests use the SSRF URL validator, TLS verification, HTTP/HTTPS protocol restrictions, disabled redirects, timeouts, and response-size controls.
- Contact handling is POST-only, rate-limited, size-limited, validated, and uses environment-based credentials.
- Internal security helpers are denied direct web access.
- The legacy `scanner.php` still contains unsafe outbound-fetch code, but it is not on the public `scanner.php` request path.
- No CI status checks are registered on the current PR head, so this is a source/static verification only; it is **not** a runtime test result.

## Compatibility matrix

Legend: **PASS** = request/response contract is compatible; **PASS*** = compatible with an intentional security behavior change; **PARTIAL** = basic response works but important legacy semantics differ; **FAIL** = current secure endpoint does not support the existing tool contract; **N/A** = tool does not use the scanner backend.

| # | Tool | Page | Scanner type | Current status | Verification / required action |
|---:|---|---|---|---|---|
| 1 | PageSpeed Analyzer | `pagespeed` | External Google PageSpeed API | N/A | Does not use `scanner.php`; unchanged by scanner replacement. |
| 2 | Image Compressor | `asset-optimizer` | Client-side | N/A | No scanner backend dependency. |
| 3 | Pixel Diagnostic | `pixel-diagnostic` | `pixels` (default) | **FAIL** | Secure scanner has no `pixels` case. Must restore pixel detection and return `pixels` array. Legacy contract also returns `pixels_found`. |
| 4 | GA4 Event Code Generator | `ga4-verifier` | Client-side | N/A | No scanner backend dependency. |
| 5 | CSS/JS Minifier | `css-js-minifier` | Client-side | N/A | No scanner backend dependency. |
| 6 | ARIA & Alt Audit | `aria-audit` | `aria_audit` | **PASS*** | Response fields match. Redirects are intentionally disabled by the secure HTTP client, so redirecting targets may now require the final URL to be entered directly. |
| 7 | Mobile Viewport UX | `mobile-ux` | `mobile_audit` | **PASS*** | Response fields match. Same intentional no-redirect behavior. |
| 8 | UX Layout Evaluator | `ux-evaluator` | `ux_evaluator` | **PASS*** | Response fields match. Same intentional no-redirect behavior. |
| 9 | Cross-Browser Check | `cross-browser` | `browser_checker` | **PASS*** | Response fields match. Same intentional no-redirect behavior. |
| 10 | Meta SEO Checker | `meta-seo` | `seo_auditor` | **PASS*** | Response fields match. Robots.txt is no longer fetched separately by the secure implementation; SEO result is therefore a reduced semantic replacement. |
| 11 | Schema Validator | `schema-validator` | `schema_validator` | **FAIL** | Legacy supports both URL and raw JSON-LD payloads. Secure implementation always requires/fetches a URL in this branch, so raw JSON validation is broken. Must support the existing payload contract locally. |
| 12 | Checkout Funnel Analyzer | `checkout-funnel` | `friction_analyzer` | **FAIL** | This is a local calculation using `checkout_type`, `required_fields`, and `guest_option`. Secure implementation incorrectly enters the URL-fetch/HTML-analysis branch first. Must move local calculation before URL fetching. |
| 13 | Cart Loss Calculator | `cart-abandonment` | `cart_abandonment` | **FAIL** | This is a local calculation using carts/orders/AOV. Secure implementation incorrectly requires/fetches a URL first. Must move local calculation before URL fetching. |
| 14 | CTA & Headline Analyzer | `cta-analyzer` | `cta_analyzer` | **PASS** | Existing request fields and `score`/`message` response are preserved. |
| 15 | Trust Badge Inspector | `trust-inspector` | `trust_inspector` | **PASS** | Existing `confidenceIndex` and `message` contract is preserved. |
| 16 | SSL & Security Checker | `ssl-checker` | `ssl_audit` | **PARTIAL** | UI only requires `score` and `message`, so it renders. However, secure implementation no longer performs the legacy certificate-expiry/issuer check and returns `daysRemaining: null`. Must restore certificate metadata safely if parity is required. |
| 17 | Invoice Generator | `invoice-generator` | Client-side | N/A | No scanner backend dependency. |
| 18 | Discount Calculator | `discount-calculator` | Client-side | N/A | No scanner backend dependency. |
| 19 | Aspect Ratio Calculator | `aspect-ratio-calculator` | Client-side | N/A | No scanner backend dependency. |
| 20 | Color Palette Generator | `color-palette-generator` | Client-side | N/A | No scanner backend dependency. |
| 21 | Contrast Checker | `contrast-checker` | `wcag_checker` | **PASS** | Existing UI consumes `success` and `message`; secure implementation preserves both. |
| 22 | Social Asset Optimizer | `social-asset-optimizer` | Client-side | N/A | No scanner backend dependency. |
| 23 | Product Copy Analyzer | `product-copy` | `copy_analyzer` | **PASS** | Existing UI consumes `success` and `message`; secure implementation preserves both. |
| 24 | Readability Evaluator | `readability` | `readability_evaluator` | **PASS** | Existing UI consumes `success` and `message`; secure implementation preserves both. |
| 25 | Bio Font Generator | `bio-font-generator` | Client-side | N/A | No scanner backend dependency. |
| 26 | Clean Text Tool | `clean-text` | Client-side | N/A | No scanner backend dependency. |
| 27 | Case Converter | `case-converter` | Client-side | N/A | No scanner backend dependency. |
| 28 | Word Counter | `word-counter` | Client-side | N/A | No scanner backend dependency. |
| 29 | PX to REM | `px-to-rem` | Client-side | N/A | No scanner backend dependency. |
| 30 | Base64 Converter | `base64-converter` | Client-side | N/A | No scanner backend dependency. |
| 31 | JSON Formatter | `json-formatter` | Client-side | N/A | No scanner backend dependency. |
| 32 | Regex Tester | `regex-tester` | Client-side | N/A | No scanner backend dependency. |
| 33 | SHA-256 Hash | `sha256-hash` | Client-side | N/A | No scanner backend dependency. |
| 34 | UUID Generator | `uuid-generator` | Client-side | N/A | No scanner backend dependency. |
| 35 | WhatsApp Link | `whatsapp-direct` | Client-side | N/A | No scanner backend dependency. |

## Merge blockers

1. **Restore `pixels` scanner compatibility** for Pixel Diagnostic.
2. **Fix local-only `cart_abandonment` and `friction_analyzer` routing** so they do not require a URL or perform an outbound request.
3. **Restore Schema Validator dual-mode behavior**: URL scanning plus raw JSON-LD validation.
4. **Decide SSL parity requirement** and, if required, restore safe certificate issuer/expiry inspection without reintroducing SSRF.

## Security compatibility note

The secure scanner intentionally disables redirects. This is a security improvement against SSRF redirect pivots, but it is a behavioral change from the legacy scanner. Existing users should be told to submit the final public HTTPS URL when a site redirects.

## Verification status

**Do not merge PR #1 yet.** The hardening layer itself passes static review, but the four blockers above mean the 35-tool compatibility contract is not yet complete.