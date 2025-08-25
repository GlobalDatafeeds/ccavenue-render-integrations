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
$refNo        = $parsed['merchant_param1'] ?? $parsed['order_id'] ?? 'NA';
$status       = strtolower($parsed['order_status'] ?? 'Unknown');
$status       = ($status === 'success' || $status === 'successful') ? 'Captured' : 'Failed';
$paymentMode  = $parsed['payment_mode'] ?? 'UPI';
$amount       = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;

// --- Extract Customer/Product Details ---
$product_desc   = $parsed['merchant_param2'] ?? '';
$customer_name  = $parsed['billing_name'] ?? $parsed['merchant_param3'] ?? 'Unknown';
$customer_email = $parsed['billing_email'] ?? $parsed['merchant_param4'] ?? '';
$customer_phone = $parsed['billing_tel'] ?? $parsed['merchant_param5'] ?? '';

// Extract additional billing details if available
$first_name = $parsed['billing_name'] ?? '';
$last_name = '';
if (strpos($first_name, ' ') !== false) {
    $name_parts = explode(' ', $first_name, 2);
    $first_name = $name_parts[0];
    $last_name = $name_parts[1] ?? '';
}

$company_name = $parsed['merchant_param6'] ?? '';
$address = $parsed['billing_address'] ?? $parsed['merchant_param7'] ?? '';
$city = $parsed['billing_city'] ?? $parsed['merchant_param8'] ?? '';
$state = $parsed['billing_state'] ?? $parsed['merchant_param9'] ?? '';
$zip = $parsed['billing_zip'] ?? $parsed['merchant_param10'] ?? '';
$country = $parsed['billing_country'] ?? $parsed['merchant_param11'] ?? '';

// Parse product details from description (alternative approach)
$product_name = "Unknown Product";
$period_days = "";
$exchange = "";
$plan_category = "";
$data_required = "";
$quantity = 1;
$price_before = $amount;
$price_after = $amount;

// Try to extract product details from description
if (preg_match('/(.*?)\s*-\s*\d+\s*Symbols/', $product_desc, $matches)) {
    $product_name = trim($matches[1]);
}

if (preg_match('/Select Subscription Period\s*:\s*(\d+)\s*Days/i', $product_desc, $matches)) {
    $period_days = $matches[1];
}

if (preg_match('/Select Exchange.*?:\s*([A-Z]+)/i', $product_desc, $matches)) {
    $exchange = $matches[1];
}

if (preg_match('/Additional License.*?:\s*(\w+)/i', $product_desc, $matches)) {
    $plan_category = $matches[1];
}

if (preg_match('/IECD Data.*?:\s*(\w+)/i', $product_desc, $matches)) {
    $data_required = $matches[1];
}

if (preg_match('/Actual Price.*?[\d.]+/', $product_desc, $matches)) {
    // Extract price information if available
}

// ---------------------------
// Get Zoho Access Token
// ---------------------------
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

// ---------------------------
// Zoho CRM Helper Functions
// ---------------------------
function zohoApiCall($url, $headers, $method = 'GET', $data = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
    } elseif ($method === 'PUT') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
    }
    
    if ($data) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    }
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    return json_decode($response, true);
}

// ---------------------------
// Find or Create Account and Contact
// ---------------------------
$headers = [
    "Authorization: Zoho-oauthtoken $access_token",
    "Content-Type: application/json"
];

// Search for existing account by name
$account_id = null;
$contact_id = null;

if (!empty($company_name)) {
    $search_account_url = "https://www.zohoapis.in/crm/v2/Accounts/search?criteria=(Account_Name:equals:$company_name)";
    $account_result = zohoApiCall($search_account_url, $headers);
    
    if (isset($account_result['data'][0]['id'])) {
        $account_id = $account_result['data'][0]['id'];
    } else {
        // Create new account
        $account_data = [
            "Account_Name" => $company_name,
            "Phone" => $customer_phone,
            "Website" => isset($parsed['website']) ? $parsed['website'] : '',
            "Billing_Street" => $address,
            "Billing_City" => $city,
            "Billing_State" => $state,
            "Billing_Code" => $zip,
            "Billing_Country" => $country
        ];
        
        $create_account_url = "https://www.zohoapis.in/crm/v2/Accounts";
        $create_account_body = json_encode(["data" => [$account_data]]);
        $new_account = zohoApiCall($create_account_url, $headers, 'POST', $create_account_body);
        
        if (isset($new_account['data'][0]['id'])) {
            $account_id = $new_account['data'][0]['id'];
        }
    }
}

// Search for existing contact by email
if (!empty($customer_email)) {
    $search_contact_url = "https://www.zohoapis.in/crm/v2/Contacts/search?criteria=(Email:equals:$customer_email)";
    $contact_result = zohoApiCall($search_contact_url, $headers);
    
    if (isset($contact_result['data'][0]['id'])) {
        $contact_id = $contact_result['data'][0]['id'];
    } else {
        // Create new contact
        $contact_data = [
            "First_Name" => $first_name,
            "Last_Name" => $last_name,
            "Email" => $customer_email,
            "Phone" => $customer_phone,
            "Mailing_Street" => $address,
            "Mailing_City" => $city,
            "Mailing_State" => $state,
            "Mailing_Zip" => $zip,
            "Mailing_Country" => $country
        ];
        
        if ($account_id) {
            $contact_data["Account_Name"] = ["id" => $account_id];
        }
        
        $create_contact_url = "https://www.zohoapis.in/crm/v2/Contacts";
        $create_contact_body = json_encode(["data" => [$contact_data]]);
        $new_contact = zohoApiCall($create_contact_url, $headers, 'POST', $create_contact_body);
        
        if (isset($new_contact['data'][0]['id'])) {
            $contact_id = $new_contact['data'][0]['id'];
        }
    }
}

// ---------------------------
// Prepare Deal Data for Zoho CRM
// ---------------------------
$module = "Deals";

// Prepare deal fields
$data_fields = [
    "Deal_Name" => "Deal for $customer_name - $refNo",
    "Reference_ID" => $refNo,
    "Stage" => ($status === "Captured") ? "Closed Won" : "Closed Lost",
    "Payment_Status" => $status,
    "Payment_Mode" => $paymentMode,
    "Amount" => $amount,
    "Closing_Date" => date("Y-m-d"),
    "Type_of_Customer" => "Renewal",
    "Type_of_Enquiry" => "Buy/Free Trial – Data Products",
    "Data_Required_for_Exchange" => "Bombay Stock Exchange (BSE)",
    "Subscription_Details" => [
        [
            "Product" => $product_name,
            "Exchanges" => $exchange,
            "Period_Days" => $period_days,
            "Plan_Category" => $plan_category,
            "Data_Required_for_Exchange" => $data_required,
            "Quantity" => $quantity,
            "Price_Before" => $price_before,
            "Price_After" => $price_after
        ]
    ]
];

// Add account and contact lookup if available
if ($account_id) {
    $data_fields["Account_Name"] = ["id" => $account_id];
}

if ($contact_id) {
    $data_fields["Contact_Name"] = ["id" => $contact_id];
}

// --- Search for Deal by Reference_ID ---
$search_url = "https://www.zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
$search_result = zohoApiCall($search_url, $headers);
file_put_contents("zoho_search_log.json", json_encode($search_result));

// --- Update if Deal Exists, else Create ---
if (isset($search_result['data'][0]['id'])) {
    $deal_id = $search_result['data'][0]['id'];
    $update_url  = "https://www.zohoapis.in/crm/v2/$module/$deal_id";
    $update_body = json_encode(["data" => [$data_fields]]);

    $update_response = zohoApiCall($update_url, $headers, 'PUT', $update_body);
    file_put_contents("zoho_update_log.json", json_encode($update_response));
} else {
    $create_url  = "https://www.zohoapis.in/crm/v2/$module";
    $create_body = json_encode(["data" => [$data_fields]]);

    $create_response = zohoApiCall($create_url, $headers, 'POST', $create_body);
    file_put_contents("zoho_create_log.json", json_encode($create_response));
}

// ---------------------------
// Response for Debugging
// ---------------------------
echo json_encode([
    "status"        => "received",
    "reference_no"  => $refNo,
    "order_status"  => $status,
    "payment_mode"  => $paymentMode,
    "amount"        => $amount,
    "customer_name" => $customer_name,
    "customer_email"=> $customer_email,
    "customer_phone"=> $customer_phone,
    "account_id"    => $account_id,
    "contact_id"    => $contact_id,
    "product_details" => [
        "product_name" => $product_name,
        "period_days" => $period_days,
        "exchange" => $exchange,
        "plan_category" => $plan_category,
        "data_required" => $data_required,
        "quantity" => $quantity,
        "price_before" => $price_before,
        "price_after" => $price_after
    ]
], JSON_PRETTY_PRINT);
?>
