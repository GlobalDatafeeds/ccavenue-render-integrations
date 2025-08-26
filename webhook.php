<?php
// ---------------------------
// CCAvenue Webhook + Zoho CRM Deal Creation
// ---------------------------

// CCAvenue Working Key
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// Zoho CRM Access Token
$access_token = "YOUR_ZOHO_ACCESS_TOKEN";

// --- Capture POST Data ---
$encResp = $_POST['encResp'] ?? '';
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// --- Decrypt Function ---
function decryptResponse($encResp, $key) {
    $encResp = base64_decode($encResp);
    $key = pack('H*', $key);
    $iv = $key;
    return openssl_decrypt($encResp, "AES-128-CBC", $key, OPENSSL_RAW_DATA, $iv);
}

// --- Decrypt Response ---
$rcvdString = decryptResponse($encResp, $working_key);
parse_str($rcvdString, $response);

// Log webhook
file_put_contents("ccavenue_log.json", json_encode($response));

// Extract customer details
$billing_name    = $response['billing_name'] ?? '';
$billing_email   = $response['billing_email'] ?? '';
$billing_tel     = $response['billing_tel'] ?? '';
$order_id        = $response['order_id'] ?? '';
$order_status    = $response['order_status'] ?? '';
$amount          = $response['amount'] ?? 0;

// --- Function: Call Zoho API ---
function zohoApi($url, $method, $data = [], $access_token) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ]);
    if (!empty($data)) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    }
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// --- Step 1: Check if Contact exists by email ---
$searchUrl = "https://www.zohoapis.in/crm/v2/Contacts/search?email=" . urlencode($billing_email);
$contactSearch = zohoApi($searchUrl, "GET", [], $access_token);

if (!empty($contactSearch['data'])) {
    $contact_id = $contactSearch['data'][0]['id'];
} else {
    // --- Step 2: Create Contact if not exists ---
    $contactData = [
        "data" => [[
            "Last_Name" => $billing_name ?: "Unknown",
            "Email" => $billing_email,
            "Phone" => $billing_tel
        ]]
    ];
    $contactCreate = zohoApi("https://www.zohoapis.in/crm/v2/Contacts", "POST", $contactData, $access_token);
    $contact_id = $contactCreate['data'][0]['details']['id'] ?? null;
}

// --- Step 3: Create Deal ---
if ($contact_id) {
    $dealData = [
        "data" => [[
            "Deal_Name"      => "Order - " . $order_id,
            "Stage"          => ($order_status == "Success") ? "Closed Won" : "Payment Pending",
            "Amount"         => $amount,
            "Contact_Name"   => ["id" => $contact_id],
            "Closing_Date"   => date("Y-m-d", strtotime("+7 days"))
        ]]
    ];

    $dealCreate = zohoApi("https://www.zohoapis.in/crm/v2/Deals", "POST", $dealData, $access_token);
    file_put_contents("deal_log.json", json_encode($dealCreate));

    echo json_encode([
        "status" => "success",
        "message" => "Deal created",
        "response" => $dealCreate
    ]);
} else {
    echo json_encode([
        "status" => "error",
        "message" => "Could not create Contact, so Deal not created"
    ]);
}
?>
