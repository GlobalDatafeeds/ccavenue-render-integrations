<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// --- Capture POST Data ---
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

// --- Extract Payment Info ---
$refNo = $parsed['order_id'] ?? 'NA';
$status = strtolower($parsed['order_status'] ?? 'Unknown');
$status = ($status === 'success' || $status === 'successful') ? 'captured' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'upi';
$amount = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;

// --- Extract Billing & Product Details ---
$product_desc = $parsed['merchant_param1'] ?? ''; 
$billing_name = $parsed['billing_name'] ?? 'Unknown';
$billing_email = $parsed['billing_email'] ?? '';
$billing_tel = $parsed['billing_tel'] ?? '';

// --- Split Name into First & Last ---
$first_name = $billing_name;
$last_name = "";
if (strpos($billing_name, " ") !== false) {
    $name_parts = explode(" ", $billing_name, 2);
    $first_name = $name_parts[0];
    $last_name = $name_parts[1];
}

// --- Zoho Access Token ---
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
    file_put_contents("zoho_error.json", json_encode(["error" => "access_token missing or refNo invalid"]));
    exit;
}

// --- Zoho API Setup ---
$module = "Deals";
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

// --- Search Deal ---
$search_url = "https://www.zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_response = curl_exec($ch);
curl_close($ch);
$search_result = json_decode($search_response, true);
file_put_contents("zoho_search_log.json", $search_response);

// --- Update or Create Deal ---
$data_fields = [
    "Deal_Name" => $billing_name,   // Deal_Name = billing_name
    "Reference_ID" => $refNo,
    "Payment_Status" => $status,
    "Payment_Mode" => $paymentMode,
    "Amount" => $amount,
    "Description" => $product_desc, // product details

    // Lookup fields
    "Account_Name" => [
        "name" => $billing_name
    ],
    "Contact_Name" => [
        "First_Name" => $first_name,
        "Last_Name" => $last_name,
        "Email" => $billing_email,
        "Phone" => $billing_tel
    ]
];

if (isset($search_result['data'][0]['id'])) {
    $deal_id = $search_result['data'][0]['id'];
    $update_url = "https://www.zohoapis.in/crm/v2/$module/$deal_id";
    $update_body = json_encode(["data" => [$data_fields]]);
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
    $create_body = json_encode(["data" => [$data_fields]]);
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

// --- Response for Testing ---
echo json_encode([
    "status" => "received",
    "reference_no" => $refNo,
    "order_status" => $status,
    "payment_mode" => $paymentMode,
    "amount" => $amount,
    "products" => $product_desc
], JSON_PRETTY_PRINT);
?>
