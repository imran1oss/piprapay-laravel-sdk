# PipraPay API fixtures

These are synthetic, anonymized contract fixtures based on the checkout and verification shapes in [PipraPay/PipraPay at 021ecd3](https://github.com/PipraPay/PipraPay/blob/021ecd302a0dc3316383933ef3aa405be9271404/index.php#L402) and its [webhook documentation](https://docs.piprapay.com/reference/webhook-guides).

They contain no production data and are not recordings from the deployed broker. Verify the deployed panel revision and run an authorized staging payment smoke test before release or broker integration. Amount strings, long string payment IDs, optional gateway fields, metadata and status variants are retained deliberately. The SDK verifies webhook status against the API; it does not credit deposits itself.
