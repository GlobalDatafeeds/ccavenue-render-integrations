<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230'; // your CCAvenue working key
$zoho_url = "https://www.zohoapis.in/crm/v2/Deals"; // Zoho CRM API Endpoint
$access_token = "YOUR_ZOHO_ACCESS_TOKEN"; // put your Zoho OAuth token here

$encResp = $_POST['encResp'] ?? '';
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "No response"]);
    exit;
}

// ----- Decrypt CCAvenue Response -----
function decrypt($encrypted_text, $key) {
    $encrypted_text = base64_decode($encrypted_text);
    $iv = substr($key, 0, 16);
    return openssl_decrypt($encrypted_text, "AES-128-CBC", $key, OPENSSL_RAW_DATA, $iv);
}

$rcvdString = decrypt($encResp, $working_key);

// Convert to array
parse_str($rcvdString, $response);

// Log CCAvenue response
file_put_contents("webhook_log.json", json_encode($response, JSON_PRETTY_PRINT));

// ---- Subscription Fields Mapping ----
$period_days = isset($response['Period_Days']) ? intval($response['Period_Days']) : 0;
$expiry_date = date('Y-m-d', strtotime("+$period_days days")); // expiry date = today + period days

$price_before = $response['Price_Before'] ?? ($response['Price_After'] ?? 0);
$price_after = $response['Price_After'] ?? $price_before;

// ---- Create Deal Data for Zoho ----
$data = [
    "data" => [[
        "Deal_Name"             => $response['customer_name'] . " - " . ($response['Product'] ?? "Subscription"),
        "Stage"                 => $response['order_status'] ?? "Created",
        "Amount"                => $response['amount'] ?? 0,
        "Closing_Date"          => date('Y-m-d'),

        // Subscription fields
        "Subscription_Details"  => $response['Subscription_Details'] ?? "",
        "Product"               => $response['Product'] ?? "",
        "Plan_Code"             => $response['Plan_Code'] ?? "",
        "Exchanges"             => $response['Exchanges'] ?? "",
        "Price_Before"          => $price_before,
        "Price_After"           => $price_after,
        "Period_Days"           => $period_days,
        "Expiry_Date"           => $expiry_date,
        "Plan_Category"         => $response['Plan_Category'] ?? "",
        "Subscription_Numb"     => $response['Subscription_Numb'] ?? "",
        "Sub_ID"                => $response['Sub_ID'] ?? "",

        // Customer Info
        "Contact_Name"          => $response['customer_name'] ?? "",
        "Email"                 => $response['email'] ?? "",
        "Phone"                 => $response['phone'] ?? "",
    ]]
];

// ---- Send to Zoho ----
$ch = curl_init($zoho_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$responseData = curl_exec($ch);
curl_close($ch);

// Save Zoho response
file_put_contents("zoho_response.json", $responseData);

echo $responseData;

