<?php
// == CONFIG ==
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// Zoho Config
$client_id = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
$client_secret = '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06';
$refresh_token = '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14';

// Zoho Flow Webhook (optional)
$flow_webhook_url = "https://flow.zoho.in/60040586143/flow/webhook/incoming?zapikey=1001.5fee5d5d026bae1ec4a6fbde8a2119d4.5e0d96d0be52d22c1e82a1c3a8c3deea&isdebug=false"
// == HELPERS ==
function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}
function decryptCCA($encryptedHex, $key) {
    $key = hextobin(md5($key));
    $iv = pack("C*", ...range(0, 15));
    $clean = preg_replace('/[^a-fA-F0-9]/', '', $encryptedHex);
    if ($clean === "" || (strlen($clean) % 2) !== 0) return false;
    $bin = hextobin($clean);
    return openssl_decrypt($bin, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
}
function getZohoAccessToken($client_id, $client_secret, $refresh_token) {
    $postData = http_build_query([
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'refresh_token'
    ]);
    $ch = curl_init("https://accounts.zoho.in/oauth/v2/token");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30
    ]);
    $response = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}
function postJSON($url, $payload) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ["Content-Type: application/json"],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 30
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// == MAIN ==
header("Content-Type: application/json");

// 1) Get encResp from CCAvenue
$encResp = $_POST['encResp'] ?? '';
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// 2) Decrypt
$decrypted = decryptCCA($encResp, $working_key);
if ($decrypted === false) {
    echo json_encode(["status" => "error", "message" => "decryption failed"]);
    exit;
}

// 3) Parse into array
parse_str($decrypted, $parsed);
file_put_contents("ccavenue_raw.json", json_encode($parsed));

// 4) Extract values
$refNo = $parsed['merchant_param1'] ?? $parsed['inv_mer_reference_no'] ?? 'NA';
$orderStatusRaw = strtolower($parsed['order_status'] ?? 'unknown');
$status = ($orderStatusRaw === 'success') ? 'captured' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'Unknown';
$amount = $parsed['amount'] ?? null;
$order_id = $parsed['order_id'] ?? null;
$tracking_id = $parsed['tracking_id'] ?? null;

// 5) Normalize JSON (Razorpay-like format)
$normalized = [
    "webhookTrigger" => [
        "headers" => [
            "gateway" => "CCAvenue",
            "content_type" => "application/json",
        ],
        "payload" => [
            "event" => "payment." . $status,
            "entity" => "event",
            "payload" => [
                "payment" => [
                    "entity" => [
                        "id" => $order_id,
                        "status" => $status,
                        "amount" => $amount,
                        "currency" => "INR",
                        "method" => $paymentMode,
                        "tracking_id" => $tracking_id,
                        "customer_name" => $parsed['billing_name'] ?? null,
                        "email" => $parsed['billing_email'] ?? null,
                        "contact" => $parsed['billing_tel'] ?? null,
                        "notes" => [
                            "merchant_reference_no" => $refNo,
                        ]
                    ]
                ]
            ]
        ]
    ]
];

// 6) Update Zoho CRM (if Reference_ID exists)
$access_token = getZohoAccessToken($client_id, $client_secret, $refresh_token);
if ($access_token && $refNo !== 'NA') {
    $module = "Deals";
    $search_url = "https://zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $search_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers
    ]);
    $search_response = curl_exec($ch);
    curl_close($ch);
    $search_result = json_decode($search_response, true);

    if (isset($search_result['data'][0]['id'])) {
        $deal_id = $search_result['data'][0]['id'];
        $update_url = "https://zohoapis.in/crm/v2/$module/$deal_id";
        $update_body = json_encode([
            "data" => [[
                "Paymet_Status" => $status,
                "Payment_Mode" => $paymentMode,
                "CCA_Order_ID" => $order_id,
                "CCA_Tracking_ID" => $tracking_id
            ]]
        ]);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $update_url,
            CURLOPT_CUSTOMREQUEST => "PUT",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => $update_body
        ]);
        $update_response = curl_exec($ch);
        curl_close($ch);
        file_put_contents("zoho_update.json", $update_response);
    }
}

// 7) Forward to Zoho Flow
if (!empty($flow_webhook_url)) {
    $flow_res = postJSON($flow_webhook_url, $normalized);
    file_put_contents("flow_forward.json", $flow_res);
}

// 8) Final reply
echo json_encode([
    "status" => "ok",
    "normalized" => $normalized
]);
