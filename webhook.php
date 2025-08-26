<?php
// ---------------------------
// CCAvenue Webhook + Zoho CRM Deal Creation
// ---------------------------

// CCAvenue Working Key
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// --- Capture POST Data ---
$encResp = $_POST['encResp'] ?? '';
file_put_contents("webhook_log.json", json_encode($_POST));

if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// --- Decrypt Response ---
function decrypt($encrypted_text, $working_key) {
    $encrypted_text = base64_decode($encrypted_text);
    $iv = pack("C*", 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15);
    $decrypted_text = openssl_decrypt(
        $encrypted_text,
        "AES-128-CBC",
        pack("C*", ...array_map("ord", str_split($working_key))),
        OPENSSL_RAW_DATA,
        $iv
    );
    return $decrypted_text;
}

$decrypted_text = decrypt($encResp, $working_key);
parse_str($decrypted_text, $responseData);

// ---------------------------
// Extract Required Values
// ---------------------------

// Deal_Name → billing_name
$deal_name = $responseData['billing_name'] ?? '';

// Description & Products → merchant_param1 (full product details string)
$product_desc = $responseData['merchant_param1'] ?? '';

// ---------------------------
// Prepare Deal Data
// ---------------------------
$dealData = [
    "Deal_Name"   => $deal_name,       // ✅ Billing Name as Deal Name
    "Description" => $product_desc,    // ✅ Product details
    "Products"    => $product_desc,    // ✅ Same product details in Products field
    "Amount"      => $responseData['amount'] ?? '',
    "Stage"       => $responseData['order_status'] ?? '',
    "Closing_Date"=> date("Y-m-d"),
    "Email"       => $responseData['billing_email'] ?? '',
    "Phone"       => $responseData['billing_tel'] ?? '',
    "Lead_Source" => "Website Order"
];

// ---------------------------
// Push to Zoho CRM (API Call)
// ---------------------------
$access_token = "YOUR_ZOHO_ACCESS_TOKEN"; // Replace with your OAuth token

$ch = curl_init("https://www.zohoapis.in/crm/v2/Deals");
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["data" => [$dealData]]));

$response = curl_exec($ch);
curl_close($ch);

// Log response
file_put_contents("zoho_response.json", $response);

echo $response;
?>
