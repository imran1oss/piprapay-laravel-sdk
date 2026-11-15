# Payment Safety Implementation Plan

> **For agentic workers:** Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Fix the SDK audit findings without changing the broker or publishing a release.

**Architecture:** Keep the existing public client and response classes. Disable API redirects; send checkout creation once, while retaining configured transient retries for verification. Reject mismatched or malformed payment responses and non-finite amounts. Build gateway URLs without moving query parameters into fragments.

**Tech Stack:** PHP 8.2+, Laravel 12/13, PHPUnit 11/12; target smoke environment PHP 8.4.27 / Laravel 13.42.0.

**Spec:** The approved audit findings and fix request in this conversation; this document records the concrete design.

## Global Constraints

- Preserve client method signatures, Laravel discovery, and decimal amount strings.
- No automatic checkout retries: remote idempotency support is not established.
- No broker changes, database migrations, merge, tag, or release.
- Keep PHP/Laravel compatibility ranges and existing CI matrix.

## Review Focus

- API redirects must not forward keys or request data; return a typed request exception.
- Checkout timeouts and 5xx responses must send only one request; verification retries remain bounded.
- Verification IDs must match exactly, with required valid amount/currency and well-typed metadata.
- Non-finite amounts and array/object fields must fail with SDK exceptions, without PHP warnings.
- Gateway URL fragments and pre-existing query parameters must remain usable.

## Task 1: HTTP safety

**Files:** `src/PipraPayClient.php`, `tests/PipraPaySafetyTest.php`

- [ ] Add failing redirect, single-attempt checkout, verification retry/exhaustion, and non-retryable error tests using mocked transports.
- [ ] Run focused PHPUnit tests and inspect expected failures.
- [ ] Disable redirects, distinguish retryable verification requests, and handle nullable redirect exceptions.
- [ ] Run the focused tests; commit the reviewed HTTP changes.

## Task 2: Payment validation

**Files:** `src/PipraPayClient.php`, `src/Data/PaymentResponse.php`, `tests/PipraPaySafetyTest.php`

- [ ] Add failing mismatched ID, missing/malformed financial fields, non-finite amount, and invalid field-type tests.
- [ ] Reject malformed input/response values before unsafe casts; compare the returned ID with the requested ID.
- [ ] Preserve decimal strings and valid pending/completed responses; run focused tests and commit.

## Task 3: Gateway URLs and documentation

**Files:** `src/Data/CheckoutResponse.php`, `tests/CheckoutResponseTest.php`, `README.md`, `CHANGELOG.md`, `config/piprapay.php`

- [ ] Add failing fragment/existing gateway/query encoding tests and reject unsupported checkout URL schemes.
- [ ] Append/replace the gateway query before the fragment while preserving unrelated query parameters.
- [ ] Document single-attempt checkout, verification retries, response validation, and unreleased fixes.
- [ ] Run local focused tests, syntax checks, Composer validation, and the complete CI matrix on the Draft PR.
- [ ] Review the final diff, commit, and leave the Draft PR unmerged and unreleased.
