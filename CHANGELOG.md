# Changelog

All notable changes to this project will be documented in this file.

## Unreleased

- Apply consistent currency validation to checkout, configuration and verification.
- Validate decimal amount strings without float comparisons; expand finite float inputs to plain decimal notation.
- Encode request JSON once and map invalid metadata/response JSON to typed exceptions with preserved causes.
- Preserve unquoted large integer IDs during response decoding, independent of global Laravel JSON flags.
- Validate HTTP(S) base/callback URLs and direct checkout objects; preserve subdirectory and local HTTP installations.
- Pin Node 24 checkout action, add lowest dependency jobs, fail CI on PHP warnings/risky tests, and audit dependencies.
- Add synthetic provider fixtures and discovery, facade, config publication/cache integration tests.
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
