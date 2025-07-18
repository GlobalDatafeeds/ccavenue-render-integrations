<?php
$working_key = '5359E7A74922E31E22D5EF4DC0545518'; // Replace with your staging/live key

// Step 1: Handle encrypted POST from CCAvenue
$encResp = $_POST['encResp'] ?? '';
file_put_contents("webhook_log.json", json_encode($_POST));

if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// Step 2: Decrypt encResp

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
parse_str($decrypted, $parsed); // converts query string into array
file_put_contents("decrypted_log.json", json_encode($parsed));

// Step 3: Extract values
// $refNo = $parsed['reference_no'] ?? 'NA';
$refNo = $parsed['merchant_param1'] ?? $parsed['inv_mer_reference_no'] ?? 'NA';
$status = strtolower($parsed['order_status'] ?? 'Unknown');
$status = $status === 'success' ? 'success' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'Unknown';

// Send response to CCAvenue
echo json_encode([
    "status" => "received",
    "reference_no" => $refNo,
    "order_status" => $status,
    "payment_mode" => $paymentMode
                 
]);

// Step 4: Get fresh Zoho access token
function getZohoAccessToken() {
    $client_id = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
    $client_secret = '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06';
    $refresh_token = '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14';

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
if (!$access_token || $refNo === 'NA') {
    file_put_contents("zoho_error.json", json_encode(["error" => "Missing refNo or access_token"]));
    exit;
}

// Step 5: Update Zoho CRM
$module = "Deals";
$search_url = "https://zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

// Search Deal
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_response = curl_exec($ch);
curl_close($ch);
file_put_contents("zoho_search_log.json", $search_response);

$search_result = json_decode($search_response, true);
if (isset($search_result['data'][0]['id'])) {
    $deal_id = $search_result['data'][0]['id'];

    // Update Deal
    $update_url = "https://zohoapis.in/crm/v2/$module/$deal_id";
    $update_body = json_encode([
        "data" => [[
            "Paymet_Status" => $status,
            "Payment_Mode" => $paymentMode
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
}
?>




















