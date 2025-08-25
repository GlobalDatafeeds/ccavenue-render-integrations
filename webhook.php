<?php
// ---- CONFIG ----
$working_key     = "B410D0FB52051326F8B5F33B491A9230"; // CCAvenue Working Key
$client_id       = "1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC";
$client_secret   = "3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06";
$refresh_token   = "1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14";
// ---- Get encResp ----
$encResp = $_POST["encResp"] ?? "";
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "No response"]);
    exit;
}

// ---- CCAvenue Decrypt ----
function decrypt($encrypted_text, $key) {
    $encrypted_text = base64_decode($encrypted_text);
    $iv = pack("C*", 0,1,2,3,4,5,6,7,8,9,10,11,12,13,14,15);
    $key = hex2bin(md5($key));
    return openssl_decrypt($encrypted_text, "AES-128-CBC", $key, OPENSSL_RAW_DATA, $iv);
}

$rcvdString = decrypt($encResp, $working_key);
parse_str($rcvdString, $response);

// Debug log
file_put_contents("ccavenue_response.json", json_encode($response, JSON_PRETTY_PRINT));

// ---- Zoho Access Token ----
function getZohoAccessToken($client_id, $client_secret, $refresh_token) {
    $url = "https://accounts.zoho.in/oauth/v2/token?refresh_token={$refresh_token}&client_id={$client_id}&client_secret={$client_secret}&grant_type=refresh_token";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $result = json_decode($result, true);
    return $result["access_token"] ?? null;
}

$access_token = getZohoAccessToken($client_id, $client_secret, $refresh_token);
if (!$access_token) {
    echo json_encode(["status" => "error", "message" => "Zoho token failed"]);
    exit;
}

// ---- Subscription Data Mapping ----
$period_days = isset($response['Period_Days']) ? intval($response['Period_Days']) : 0;
$expiry_date = date('Y-m-d', strtotime("+$period_days days"));

$price_before = $response['Price_Before'] ?? ($response['Price_After'] ?? 0);
$price_after  = $response['Price_After'] ?? $price_before;

// ---- Zoho Deal Data (with Subform) ----
$dealData = [
    "data" => [[
        "Deal_Name"    => ($response['customer_name'] ?? "Unknown") . " - " . ($response['Product'] ?? "Subscription"),
        "Stage"        => $response['order_status'] ?? "Created",
        "Amount"       => $response['amount'] ?? 0,
        "Closing_Date" => date('Y-m-d'),

        "Contact_Name" => $response['customer_name'] ?? "",
        "Email"        => $response['billing_email'] ?? "",
        "Phone"        => $response['billing_tel'] ?? "",

        // ---- Subform "Subscription_Details" ----
        "Subscription_Details" => [[
            "Product"             => $response['Product'] ?? "",
            "Plan_Code"           => $response['Plan_Code'] ?? "",
            "Exchanges"           => $response['Exchanges'] ?? "",
            "Price_Before"        => $price_before,
            "Price_After"         => $price_after,
            "Period_Days"         => $period_days,
            "Expiry_Date"         => $expiry_date,
            "Plan_Category"       => $response['Plan_Category'] ?? "",
            "Subscription_Numb"   => $response['Subscription_Numb'] ?? "",
            "Sub_ID"              => $response['Sub_ID'] ?? ""
        ]]
    ]]
];

// ---- Send to Zoho ----
$zoho_url = "https://www.zohoapis.in/crm/v2/Deals";

$ch = curl_init($zoho_url);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
]);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($dealData));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$zohoResponse = curl_exec($ch);
curl_close($ch);

// Save response
file_put_contents("zoho_response.json", $zohoResponse);

echo $zohoResponse;


