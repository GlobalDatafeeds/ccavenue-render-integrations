<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

$encResp = $_POST['encResp'] ?? '';
file_put_contents("webhook_log.json", json_encode($_POST));

if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// --- Decrypt Response ---
function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}

function decrypt($encryptedText, $key) {
    $key = hextobin(md5($key));
    $initVector = pack("C*", ...range(0, 15));
    $encryptedText = hextobin($encryptedText);
    return openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
}

$decrypted = decrypt($encResp, $working_key);
parse_str($decrypted, $parsed);
file_put_contents("decrypted_log.json", json_encode($parsed));

// --- Extract Payment & Products ---
$refNo = $parsed['merchant_param1'] ?? $parsed['order_id'] ?? 'NA';
$status = strtolower($parsed['order_status'] ?? 'Unknown');
$status = $status === 'success' ? 'captured' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'upi';
$amount = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;
$products = json_decode($parsed['merchant_param2'] ?? '[]', true);

// --- Zoho Access Token ---
function getZohoAccessToken() {
    $client_id = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
    $client_secret = 'YOUR_CLIENT_SECRET';
    $refresh_token = 'YOUR_REFRESH_TOKEN';

    $postData = http_build_query([
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'grant_type' => 'refresh_token'
    ]);

    $ch = curl_init("https://accounts.zoho.in/oauth/v2/token");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data['access_token'] ?? null;
}

$access_token = getZohoAccessToken();
if (!$access_token) {
    file_put_contents("zoho_error.json", json_encode(["error" => "access_token missing"]));
    exit;
}

// --- Search Deal in Zoho ---
$module = "Deals";
$search_url = "https://www.zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_response = curl_exec($ch);
curl_close($ch);
$search_result = json_decode($search_response, true);
file_put_contents("zoho_search_log.json", $search_response);

// --- Update or Create Deal ---
if (isset($search_result['data'][0]['id'])) {
    $deal_id = $search_result['data'][0]['id'];
    $update_url = "https://www.zohoapis.in/crm/v2/$module/$deal_id";
    $update_body = json_encode([
        "data" => [[
            "Payment_Status" => $status,
            "Payment_Mode" => $paymentMode,
            "Amount" => $amount,
            "Products" => json_encode($products)
        ]]
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $update_url);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $update_body);
    $update_response = curl_exec($ch);
    curl_close($ch);
    file_put_contents("zoho_update_log.json", $update_response);

} else {
    $create_url = "https://www.zohoapis.in/crm/v2/$module";
    $create_body = json_encode([
        "data" => [[
            "Deal_Name" => "Deal for $refNo",
            "Reference_ID" => $refNo,
            "Payment_Status" => $status,
            "Payment_Mode" => $paymentMode,
            "Amount" => $amount,
            "Products" => json_encode($products)
        ]]
    ]);
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $create_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $create_body);
    $create_response = curl_exec($ch);
    curl_close($ch);
    file_put_contents("zoho_create_log.json", $create_response);
}

// --- Razorpay-like JSON Response ---
$razorpay_like_response = [
    "webhookTrigger" => [
        "payload" => [
            "payment" => [
                "entity" => [
                    "id" => $parsed['order_id'],
                    "status" => $status,
                    "amount" => $amount*100,
                    "currency" => $parsed['currency'] ?? 'INR',
                    "method" => $paymentMode,
                    "email" => $parsed['billing_email'] ?? '',
                    "contact" => $parsed['billing_tel'] ?? '',
                    "notes" => [
                        "product_desc" => $products,
                        "reference_no" => $refNo
                    ]
                ]
            ]
        ],
        "created_at" => time(),
        "event" => "payment.captured",
        "entity" => "event"
    ]
];

echo json_encode($razorpay_like_response, JSON_PRETTY_PRINT);
?>
