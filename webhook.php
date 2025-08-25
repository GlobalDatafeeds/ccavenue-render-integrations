<?php
// === CONFIGURATION ===
$working_key = 'B410D0FB52051326F8B5F33B491A9230';
$zoho_client_id = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
$zoho_client_secret = 'YOUR_CLIENT_SECRET';
$zoho_refresh_token = 'YOUR_REFRESH_TOKEN';
$zoho_module = 'Deals';

// === HELPER FUNCTIONS ===
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

function getZohoAccessToken($client_id, $client_secret, $refresh_token) {
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

// === RECEIVE & DECRYPT PAYLOAD ===
$encResp = $_POST['encResp'] ?? '';
if (!$encResp) {
    echo json_encode(["status"=>"error","message"=>"encResp not found"]);
    exit;
}

$decrypted = decrypt($encResp, $working_key);
parse_str($decrypted, $parsed);

// --- Extract Key Payment Info ---
$refNo = $parsed['merchant_param1'] ?? $parsed['order_id'] ?? 'ORD_TEST';
$status = strtolower($parsed['order_status'] ?? 'Unknown') === 'success' ? 'captured' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'upi';
$amount = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;
$products = json_decode($parsed['merchant_param2'] ?? '[]', true);

// === Zoho Access Token ===
$access_token = getZohoAccessToken($zoho_client_id, $zoho_client_secret, $zoho_refresh_token);
if (!$access_token) {
    echo json_encode(["status"=>"error","message"=>"Zoho access token missing"]);
    exit;
}

// === SEARCH DEAL IN ZOHO ===
$headers = ["Authorization: Zoho-oauthtoken $access_token", "Content-Type: application/json"];
$search_url = "https://www.zohoapis.in/crm/v2/$zoho_module/search?criteria=(Reference_ID:equals:$refNo)";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_response = curl_exec($ch);
curl_close($ch);
$search_result = json_decode($search_response, true);

// === CREATE OR UPDATE DEAL ===
if (isset($search_result['data'][0]['id'])) {
    // Update
    $deal_id = $search_result['data'][0]['id'];
    $update_url = "https://www.zohoapis.in/crm/v2/$zoho_module/$deal_id";
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
    // Create
    $create_url = "https://www.zohoapis.in/crm/v2/$zoho_module";
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

// === RESPONSE TO CC AVENUE ===
echo json_encode([
    "status" => "success",
    "reference_no" => $refNo,
    "order_status" => $status,
    "payment_mode" => $paymentMode,
    "amount" => $amount,
    "products" => $products
]);
?>
