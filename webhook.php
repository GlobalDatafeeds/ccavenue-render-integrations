<?php
// ---------------------------
// CCAvenue Webhook + Zoho CRM Deal Creation
// ---------------------------

$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// Capture POST data
$encResp = $_POST['encResp'] ?? '';
file_put_contents("webhook_log.json", json_encode($_POST));

if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// Decrypt functions
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

// Extract payment & customer info
$refNo        = $parsed['merchant_param1'] ?? $parsed['order_id'] ?? 'NA';
$status       = strtolower($parsed['order_status'] ?? 'Unknown');
$status       = ($status === 'success' || $status === 'successful') ? 'Captured' : 'Failed';
$paymentMode  = $parsed['payment_mode'] ?? 'UPI';
$amount       = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;

$website        = $parsed['merchant_param2'] ?? 'Website';
$customer_name  = $parsed['billing_name'] ?? $parsed['merchant_param3'] ?? 'Unknown';
$customer_email = $parsed['billing_email'] ?? $parsed['merchant_param4'] ?? '';
$customer_phone = $parsed['billing_tel'] ?? $parsed['merchant_param5'] ?? '';

// Sample product details from website (dynamic)
$product_data = $parsed['merchant_products'] ?? []; 
/*
$product_data should be array of subform items:
[
  [
    "Product" => "NimbleDataPlusLite",
    "Exchanges" => "NFO",
    "Period_Days" => 30,
    "Price_Before" => 1.994915,
    "Price_After" => 1.694915,
    "Plan_Category" => "No"
  ],
  ...
]
*/

// Zoho Access Token
function getZohoAccessToken() {
    $client_id     = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
    $client_secret = '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06';
    $refresh_token = '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14';

    $postData = http_build_query([
        'refresh_token' => $refresh_token,
        'client_id'     => $client_id,
        'client_secret' => $client_secret,
        'grant_type'    => 'refresh_token'
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
    file_put_contents("zoho_error.json", json_encode([
        "error" => "access_token missing or refNo invalid"
    ]));
    exit;
}

// Prepare Deal Data
$deal_fields = [
    "Deal_Name"                  => $website,
    "Account_Name"               => $website, // lookup by website
    "Stage"                      => ($status === "Captured") ? "Closed Won" : "Closed Lost",
    "Payment_Status"             => $status,
    "Payment_Mode"               => $paymentMode,
    "Amount"                     => $amount,
    "Type_of_Customer"           => "Renewal",
    "Type_of_Enquiry"            => "Buy/Free Trial – Data Products",
    "Data_Required_for_Exchange" => "Bombay Stock Exchange (BSE)",
    "Closing_Date"               => date("Y-m-d"),
    "Contact_Name"               => [
        "first_name" => $customer_name, 
        "last_name"  => "",
        "email"      => $customer_email,
        "phone"      => $customer_phone
    ],
    "Subscription_Details"       => $product_data
];

// Search or Create/Update Deal
$module  = "Deals";
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

$search_url = "https://www.zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_response = curl_exec($ch);
curl_close($ch);
$search_result = json_decode($search_response, true);
file_put_contents("zoho_search_log.json", $search_response);

// Create or Update
if (isset($search_result['data'][0]['id'])) {
    $deal_id = $search_result['data'][0]['id'];
    $update_url  = "https://www.zohoapis.in/crm/v2/$module/$deal_id";
    $update_body = json_encode(["data" => [$deal_fields]]);

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
    $create_url  = "https://www.zohoapis.in/crm/v2/$module";
    $create_body = json_encode(["data" => [$deal_fields]]);

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

// Response
echo json_encode([
    "status"        => "received",
    "reference_no"  => $refNo,
    "order_status"  => $status,
    "payment_mode"  => $paymentMode,
    "amount"        => $amount,
    "website"       => $website,
    "customer_name" => $customer_name,
    "customer_email"=> $customer_email,
    "customer_phone"=> $customer_phone,
    "products"      => $product_data
], JSON_PRETTY_PRINT);

?>
