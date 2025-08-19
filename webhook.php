<?php
// webhook.php - receive CCAvenue POST, decrypt, normalize and forward to Zoho Flow
// Uses environment variable CCA_WORKING_KEY and ZOHO_FLOW_WEBHOOK

$WORKING_KEY = getenv('CCA_WORKING_KEY') ?: 'B410D0FB52051326F8B5F33B491A9230';
$FLOW_WEBHOOK = getenv('ZOHO_FLOW_WEBHOOK') ?: 'https://flow.zoho.in/60040586143/flow/webhook/incoming?zapikey=1001.43db27cdaa06246af2f14b82dd07f31e.f0fe67f215db79f856304aa6bb7d4fb7&isdebug=true';

// DEBUG mode: if debug=1 and 'debug_payload' posted, the script will skip decryption and use provided JSON (for testing)
$DEBUG = (isset($_REQUEST['debug']) && $_REQUEST['debug'] == '1');

// helpers
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
    $err = curl_error($ch);
    curl_close($ch);
    return ["response" => $res, "error" => $err];
}

// MAIN
header("Content-Type: application/json");

// 1) get POST
if ($DEBUG && !empty($_POST['debug_payload'])) {
    $parsed = json_decode($_POST['debug_payload'], true);
    file_put_contents("debug_parsed.json", json_encode($parsed, JSON_PRETTY_PRINT));
} else {
    $encResp = $_POST['encResp'] ?? '';
    if (!$encResp) {
        http_response_code(400);
        echo json_encode(["status" => "error", "message" => "encResp not found"]);
        exit;
    }
    $decrypted = decryptCCA($encResp, $WORKING_KEY);
    if ($decrypted === false) {
        http_response_code(500);
        file_put_contents("decrypted_failed.json", json_encode(["encResp_len" => strlen($encResp)]));
        echo json_encode(["status" => "error", "message" => "decrypt_failed"]);
        exit;
    }
    parse_str($decrypted, $parsed);
    // keep raw logs
    file_put_contents("ccavenue_raw.json", json_encode($parsed, JSON_PRETTY_PRINT));
}

// 2) Extract and normalize to a consistent JSON structure
$orderStatusRaw = strtolower($parsed['order_status'] ?? 'unknown');
$status = ($orderStatusRaw === 'success') ? 'captured' : 'failed';
$refNo = $parsed['merchant_param1'] ?? $parsed['inv_mer_reference_no'] ?? 'NA';

$normalized = [
    "webhookTrigger" => [
        "headers" => [
            "gateway" => "CCAvenue",
            "content_type" => "application/json"
        ],
        "payload" => [
            "event" => "payment." . $status,
            "entity" => "event",
            "payload" => [
                "payment" => [
                    "entity" => [
                        "id" => $parsed['order_id'] ?? null,
                        "status" => $status,
                        "amount" => $parsed['amount'] ?? null,
                        "currency" => "INR",
                        "method" => $parsed['payment_mode'] ?? 'Unknown',
                        "tracking_id" => $parsed['tracking_id'] ?? null,
                        "customer_name" => $parsed['billing_name'] ?? null,
                        "email" => $parsed['billing_email'] ?? null,
                        "contact" => $parsed['billing_tel'] ?? null,
                        "notes" => [
                            "merchant_reference_no" => $refNo
                        ],
                        "raw" => $parsed
                    ]
                ]
            ]
        ]
    ]
];

// 3) Forward to Zoho Flow (if configured)
$flow_result = null;
if (!empty($FLOW_WEBHOOK)) {
    $flow_result = postJSON($FLOW_WEBHOOK, $normalized);
    file_put_contents("flow_forward.json", json_encode($flow_result, JSON_PRETTY_PRINT));
}

// 4) Reply to CCAvenue (important: reply something)
echo json_encode([
    "status" => "received",
    "reference_no" => $refNo,
    "order_status" => $status,
    "forward" => $flow_result ? "sent" : "not_sent",
    "normalized" => $normalized
], JSON_PRETTY_PRINT);
