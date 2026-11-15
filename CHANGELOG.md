# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Stop following API redirects to prevent forwarding credentials to redirect destinations.
- Send checkout creation once; retain configured transient retries for payment/webhook verification.
- Reject mismatched payment IDs, invalid financial response fields, malformed field types, and non-finite amounts.
- Preserve query parameters and fragments when building direct gateway URLs, replacing existing gateway selections.
- Add payment safety and gateway URL regression tests for the Laravel 12/13 support matrix.
- Initial Laravel 12/13 SDK.
- Added checkout creation using the current `/api/checkout/redirect` endpoint.
- Added payment verification using `/api/verify-payment`.
- Added direct gateway URL support through `external_gateway_code`.
- Added API-verified webhook handling, typed responses, exceptions, config, facade, tests, and CI.
