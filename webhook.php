<?php
$working_key = "B410D0FB52051326F8B5F33B491A9230"; // CCAvenue working key

// ----------------- Helper Functions -----------------
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

// ----------------- Get Zoho Access Token -----------------
function getZohoAccessToken() {
    $client_id = "1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC";
    $client_secret = "3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06";
    $refresh_token = "1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14";

    $postData = http_build_query([
        "refresh_token" => $refresh_token,
        "client_id" => $client_id,
        "client_secret" => $client_secret,
        "grant_type" => "refresh_token"
    ]);

    $ch = curl_init("https://accounts.zoho.in/oauth/v2/token");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response, true);
    return $data["access_token"] ?? null;
}

// ----------------- Start Webhook -----------------
$encResp = $_POST["encResp"] ?? '';
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

$decrypted = decrypt($encResp, $working_key);
parse_str($decrypted, $parsed);

// Log decrypted response
file_put_contents("decrypted_log.json", json_encode($parsed, JSON_PRETTY_PRINT));

// ----------------- Extract Data -----------------
$order_status = strtolower($parsed["order_status"] ?? "Failed");
$stage = ($order_status === "success") ? "Closed Won" : "Closed Lost";

$customer_name = $parsed["billing_name"] ?? "";
$email = $parsed["billing_email"] ?? "";
$phone = $parsed["billing_tel"] ?? "";

// Break full name into first/last
$nameParts = explode(" ", $customer_name, 2);
$first_name = $nameParts[0] ?? "";
$last_name = $nameParts[1] ?? "";

// Merchant param1 → custom subscription details
$subscription_raw = explode("|", $parsed["merchant_param1"] ?? "");
$product = $subscription_raw[1] ?? "";
$plan_code = $subscription_raw[2] ?? "";
$exchange = $subscription_raw[3] ?? "";
$category = $subscription_raw[4] ?? "";
$period_days = intval($subscription_raw[2] ?? 0);
$expiry_date = date("Y-m-d", strtotime("+$period_days days"));
$price_before = $parsed["amount"] ?? 0;
$price_after = $parsed["amount"] ?? 0;

// ----------------- Zoho CRM API -----------------
$access_token = getZohoAccessToken();
if (!$access_token) {
    file_put_contents("zoho_error.json", json_encode(["error" => "access_token missing"]));
    exit;
}
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

// --- Step 1: Search Contact by Email ---
$search_url = "https://www.zohoapis.in/crm/v2/Contacts/search?criteria=(Email:equals:$email)";
$ch = curl_init($search_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
$contact_response = curl_exec($ch);
curl_close($ch);
$contact_data = json_decode($contact_response, true);

$type_of_customer = "Fresh";
$contact_id = null;

if (isset($contact_data["data"][0]["id"])) {
    $contact_id = $contact_data["data"][0]["id"];
    $type_of_customer = "Renewal";
} else {
    // --- Step 2: Create Contact ---
    $contact_body = json_encode([
        "data" => [[
            "First_Name" => $first_name,
            "Last_Name" => $last_name,
            "Email" => $email,
            "Phone" => $phone
        ]]
    ]);

    $ch = curl_init("https://www.zohoapis.in/crm/v2/Contacts");
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $contact_body);
    $new_contact_response = curl_exec($ch);
    curl_close($ch);

    $new_contact_data = json_decode($new_contact_response, true);
    $contact_id = $new_contact_data["data"][0]["details"]["id"] ?? null;
}

// --- Step 3: Create Deal ---
$deal_body = json_encode([
    "data" => [[
        "Deal_Name" => $customer_name . " - " . $product,
        "Stage" => $stage,
        "Amount" => $parsed["amount"] ?? 0,
        "Closing_Date" => date("Y-m-d"),
        "Lead_Source" => "Website",
        "Type_of_Enquiry" => "Buy/Free Trial Data Products",
        "Data_Required_for_Exchange" => "Bombay Stock Exchange (BSE)",
        "Type_of_customer" => $type_of_customer,

        // Subscription fields
        "Product" => $product,
        "Plan_Code" => $plan_code,
        "Exchanges" => $exchange,
        "Price_Before" => $price_before,
        "Price_After" => $price_after,
        "Period_Days" => $period_days,
        "Expiry_Date" => $expiry_date,
        "Plan_Category" => $category,

        // Lookup with contact
        "Contact_Name" => $contact_id
    ]]
]);

$ch = curl_init("https://www.zohoapis.in/crm/v2/Deals");
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $deal_body);
$deal_response = curl_exec($ch);
curl_close($ch);

file_put_contents("zoho_deal_response.json", $deal_response);

echo $deal_response;
