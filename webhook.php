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

// --- Extract Payment & Billing Info ---
$status = strtolower($parsed['order_status'] ?? 'Unknown');
$status = ($status === 'success' || $status === 'successful') ? 'captured' : 'failed';
$amount = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;

$product_desc  = $parsed['merchant_param1'] ?? '';
$billing_name  = $parsed['billing_name'] ?? '';
$billing_email = $parsed['billing_email'] ?? '';
$billing_phone = $parsed['billing_tel'] ?? '';

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
if (!$access_token) {
    file_put_contents("zoho_error.json", json_encode(["error" => "access_token missing"]));
    exit;
}

$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

// --- Step 1: Search or Create Contact ---
$contact_id = null;

// Search Contact by Email
$search_contact_url = "https://www.zohoapis.in/crm/v2/Contacts/search?email={$billing_email}";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $search_contact_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$search_contact_response = curl_exec($ch);
curl_close($ch);
$search_contact_result = json_decode($search_contact_response, true);

if (isset($search_contact_result['data'][0]['id'])) {
    $contact_id = $search_contact_result['data'][0]['id'];
} else {
    // Create new Contact
    // Split first and last name
    $name_parts = explode(' ', $billing_name, 2);
    $first_name = $name_parts[0];
    $last_name  = $name_parts[1] ?? '';
    
    $contact_data = [
        "First_Name" => $first_name,
        "Last_Name"  => $last_name,
        "Email"      => $billing_email,
        "Phone"      => $billing_phone
    ];
    $create_contact_url = "https://www.zohoapis.in/crm/v2/Contacts";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $create_contact_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["data"=>[$contact_data]]));
    $create_contact_response = curl_exec($ch);
    curl_close($ch);
    $create_contact_result = json_decode($create_contact_response, true);
    if (isset($create_contact_result['data'][0]['details']['id'])) {
        $contact_id = $create_contact_result['data'][0]['details']['id'];
    }
}

// --- Step 2: Create Deal ---
$deal_data = [
    "Deal_Name"                     => $billing_name,
    "Amount"                        => $amount,
    "Description"                   => $product_desc,
    "Stage"                         => "Closed Won",
    "Contact_Name"                  => ["id" => $contact_id],  // lookup
    "Type_of_Customer"              => "Renewal",
    "Type_of_Enquiry"               => "Buy/Free Trial – Data Products",
    "Data_Required_for_Exchange"    => "Bombay Stock Exchange (BSE)",
    "Lead_Source"                    => "Website"
];

$create_deal_url = "https://www.zohoapis.in/crm/v2/Deals";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $create_deal_url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["data"=>[$deal_data]]));
$create_deal_response = curl_exec($ch);
curl_close($ch);

file_put_contents("zoho_create_log.json", $create_deal_response);

// --- Response for Testing ---
echo json_encode([
    "status" => "received",
    "order_status" => $status,
    "amount" => $amount,
    "products" => $product_desc,
    "billing_name" => $billing_name,
    "billing_email" => $billing_email,
    "billing_phone" => $billing_phone,
    "stage" => "Closed Won",
    "contact_id" => $contact_id
], JSON_PRETTY_PRINT);
?>
