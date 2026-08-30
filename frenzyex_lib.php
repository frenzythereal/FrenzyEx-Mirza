<?php
/**
 * FrenzyEx helpers, loaded by the patched index.php.
 *
 * Kept in its own file at the bot root so the patch applied to index.php stays
 * a handful of lines. Nothing here depends on a particular Mirza version.
 */

if (!function_exists('frenzyex_setting')) {
    /**
     * One PaySetting row. Uses Mirza's own accessor when it exists so the
     * value comes from the same cache every other gateway reads.
     */
    function frenzyex_setting($name, $default = '')
    {
        if (function_exists('getPaySettingValue')) {
            return trim((string) getPaySettingValue($name, $default));
        }

        $row = select("PaySetting", "ValuePay", "NamePay", $name, "select");
        return trim((string) ($row['ValuePay'] ?? $default));
    }
}

if (!function_exists('frenzyex_status_is_on')) {
    /**
     * Canonical storage is `1`/`0`, matching Mirza's numeric gateway switches.
     * Accept `onfrenzyex` temporarily so a request cannot break while an older
     * database is being normalized by the installer.
     */
    function frenzyex_status_is_on($value)
    {
        $value = strtolower(trim((string) $value));
        return $value === '1' || $value === 'onfrenzyex';
    }
}

if (!function_exists('frenzyex_enabled')) {
    /**
     * Both credentials, or the button stays hidden.
     *
     * With an API key but no signing key the bot happily creates payments and
     * then rejects every callback as unsigned — the buyer pays and nothing
     * arrives. That failure is invisible until someone loses money, so it is
     * checked here rather than left to the admin to notice.
     */
    function frenzyex_enabled()
    {
        if (!frenzyex_status_is_on(frenzyex_setting('statusfrenzyex', '0'))) {
            return false;
        }

        $key = frenzyex_setting('apifrenzyex', '');
        $secret = frenzyex_setting('secretfrenzyex', '');

        return $key !== '' && $key !== '0' && $secret !== '' && $secret !== '0';
    }
}

if (!function_exists('frenzyex_claim_paid')) {
    /**
     * Claim an order for delivery. True exactly once per order, ever.
     *
     * 0.4 added claimPaymentPaid() for this and it is used when present. 0.3
     * has no equivalent: its gateways read the status, deliver, and only then
     * write 'paid'. Two confirmations arriving together both read "not paid"
     * and both deliver — and FrenzyEx retries, so that race is one this
     * gateway would actually hit rather than a theoretical one.
     *
     * The fallback is the same single atomic UPDATE 0.4 settled on. The row is
     * claimed by the write itself, so only the caller whose UPDATE matched a
     * row proceeds. Column names are identical in 0.3 and 0.4.
     */
    function frenzyex_claim_paid($order_id)
    {
        if (function_exists('claimPaymentPaid')) {
            return claimPaymentPaid($order_id);
        }

        global $pdo;
        $stmt = $pdo->prepare(
            "UPDATE Payment_report SET payment_Status = 'paid'
             WHERE id_order = :id_order AND payment_Status <> 'paid'"
        );
        $stmt->bindValue(':id_order', $order_id);
        $stmt->execute();

        // Mirza caches select() results; without this DirectPayment() can read
        // the pre-claim row back and act on a stale status.
        if (function_exists('clearSelectCache')) {
            clearSelectCache('Payment_report');
        }

        return $stmt->rowCount() >= 1;
    }
}

if (!function_exists('createPayFrenzyEx')) {
    /**
     * Ask FrenzyEx for a payment link.
     *
     * Returns the decoded response. `pay_url` is the link to hand the buyer;
     * its absence means the call failed and the caller must not create an
     * invoice row.
     */
    function createPayFrenzyEx($amount_toman, $order_id)
    {
        global $domainhosts;

        $api_key = frenzyex_setting('apifrenzyex', '');
        if ($api_key === '' || $api_key === '0') {
            return ['success' => false, 'message' => 'frenzyex: api key is unset'];
        }

        $base = frenzyex_setting('endpointfrenzyex', '');
        if ($base === '' || $base === '0') {
            $base = 'https://frenzy.fastsnap.info';
        }
        $base = rtrim($base, '/');

        // `https://` written here rather than left to $domainhosts: that
        // variable is a bare host, and a callback without a scheme is either
        // refused outright or resolved against the wrong base.
        $callback = "https://$domainhosts/payment/frenzyex.php";

        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $base . '/api/v1/payment-requests',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $api_key,
            ],
        ]);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
            'amount' => (float) $amount_toman,
            'amount_ccy' => 'TMN',
            'order_ref' => (string) $order_id,
            'description' => 'Mirza invoice ' . $order_id,
            'callback_url' => $callback,
        ], JSON_UNESCAPED_UNICODE));

        $response = curl_exec($curl);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            error_log("FrenzyEx: create payment failed — $error");
            return ['success' => false, 'message' => $error];
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            error_log("FrenzyEx: unreadable create response — $response");
            return ['success' => false, 'message' => 'invalid response'];
        }

        return $decoded;
    }
}
