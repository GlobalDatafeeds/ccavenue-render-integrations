<?php
// ---------- CONFIG ----------
$WORKING_KEY = 'B410D0FB52051326F8B5F33B491A9230'; // CCAvenue working key

// Zoho OAuth (INDIA DC)
define('Z_CLIENT_ID',     '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC');
define('Z_CLIENT_SECRET', '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06');
define('Z_REFRESH_TOKEN', '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14');

define('Z_BASE', 'https://www.zohoapis.in/crm/v2');

// ---------- helpers ----------
function log_file($name, $payload) {
    // Render’s disk is ephemeral but fine for immediate debugging
    @file_put_contents($name, is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT));
    error_log("$name: " . (is_string($payload) ? $payload : json_encode($payload)));
}

function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}
function cca_decrypt($encryptedText, $workingKey) {
    $key = hextobin(md5($workingKey));
    $iv  = pack("C*", ...range(0, 15));
    $encryptedText = hextobin($encryptedText);
    return openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

function zoho_access_token() {
    $post = http_build_query([
        'refresh_token' => Z_REFRESH_TOKEN,
        'client_id'     => Z_CLIENT_ID,
        'client_secret' => Z_CLIENT_SECRET,
        'grant_type'    => 'refresh_token'
    ]);
    $ch = curl_init('https://accounts.zoho.in/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    log_file('zoho_token_log.json', $data);
    return $data['access_token'] ?? null;
}

function zget($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}
function zpost($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}
function zput($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// ---------- 1) accept webhook ----------
$encResp = $_POST['encResp'] ?? '';
log_file('webhook_log.json', $_POST);

if (!$encResp) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// ---------- 2) decrypt ----------
$plain = cca_decrypt($encResp, $WORKING_KEY);
parse_str($plain, $cc);
log_file('decrypted_log.json', $cc);

// ---------- 3) extract core fields ----------
$order_id      = $cc['order_id']        ?? '';
$order_status  = strtolower($cc['order_status'] ?? 'failed');
$amount        = isset($cc['amount']) ? floatval($cc['amount']) : 0.0;

$billing_name  = trim($cc['billing_name']  ?? '');
$billing_email = trim($cc['billing_email'] ?? '');
$billing_tel   = trim($cc['billing_tel']   ?? '');

$payment_mode  = $cc['payment_mode'] ?? '';
$today         = date('Y-m-d');

// Stage
$stage = ($order_status === 'success' || $order_status === 'successful') ? 'Closed Won' : 'Closed Lost';

// ---------- 4) parse products (merchant_param1/2) ----------
/*
merchant_param1 sample:
"Product|NimbleDataPlusLite - 15 Symbols ZohoTesting|30|NFO|No|No|1|1.994915|1.694915"
Format expected:
[0]"Product" | [1]Product | [2]Period(Days) | [3]Exchanges | [4]AdditionalLicense | [5]IEOD | [6]Qty | [7]Total | [8]TotalWoTax
We fill subform fields:
Product, Plan_Code, Exchanges, Price_Before, Price_After, Period_Days, Expiry_Date(current payment date), Plan_Category, Subscription_Numb, Sub_ID
*/
function parse_one_line($s) {
    $parts = array_map('trim', explode('|', $s));
    // guard
    if (count($parts) < 3) return null;

    return [
        'Product'            => $parts[1] ?? '',
        'Plan_Code'          => '',                     // not present in sample
        'Exchanges'          => $parts[3] ?? '',
        'Price_Before'       => (float)($parts[7] ?? 0),
        'Price_After'        => (float)($parts[8] ?? ($parts[7] ?? 0)),
        'Period_Days'        => (int)   ($parts[2] ?? 0),
        'Plan_Category'      => strtolower($parts[4] ?? ''), // you showed "no"
        'Subscription_Numb'  => '',                     // not present
        'Sub_ID'             => ''                      // not present
    ];
}
// collect lines
$sub_rows = [];
if (!empty($cc['merchant_param1'])) {
    $row = parse_one_line($cc['merchant_param1']);
    if ($row) $sub_rows[] = $row;
}
if (!empty($cc['merchant_param2'])) {
    // merchant_param2 may contain JSON array or another pipe-line
    $mp2 = $cc['merchant_param2'];
    $decoded = json_decode($mp2, true);
    if (is_array($decoded)) {
        foreach ($decoded as $line) {
            if (is_string($line)) {
                $r = parse_one_line($line);
                if ($r) $sub_rows[] = $r;
            } elseif (is_array($line) && isset($line['raw'])) {
                $r = parse_one_line($line['raw']);
                if ($r) $sub_rows[] = $r;
            }
        }
    } else {
        // treat as single pipe-line
        $r = parse_one_line($mp2);
        if ($r) $sub_rows[] = $r;
    }
}
// set Expiry_Date = current payment date (per your instruction)
foreach ($sub_rows as &$r) { $r['Expiry_Date'] = $today; }
unset($r);

log_file('subform_rows_debug.json', $sub_rows);

// For Deal_Name, use first product name if present
$first_product_name = $sub_rows[0]['Product'] ?? 'Subscription';

// ---------- 5) get Zoho access token ----------
$token = zoho_access_token();
if (!$token) {
    log_file('zoho_error.json', ['error' => 'access_token missing']);
    echo json_encode(['status' => 'error', 'message' => 'Zoho token missing']); exit;
}
$headers = [
    "Authorization: Zoho-oauthtoken $token",
    "Content-Type: application/json"
];

// ---------- 6) find-or-create contact ----------
$contact_id = null;
$type_of_customer = 'Fresh';

// search by email first
if ($billing_email !== '') {
    $url = Z_BASE . "/Contacts/search?criteria=(Email:equals:" . rawurlencode($billing_email) . ")";
    $res = zget($url, $headers);
    $data = json_decode($res, true);
    log_file('zoho_contact_search_email.json', $data);
    if (!empty($data['data'][0]['id'])) {
        $contact_id = $data['data'][0]['id'];
        $type_of_customer = 'Renewal';
    }
}
// if not found, try phone
if (!$contact_id && $billing_tel !== '') {
    $url = Z_BASE . "/Contacts/search?criteria=(Phone:equals:" . rawurlencode($billing_tel) . ")";
    $res = zget($url, $headers);
    $data = json_decode($res, true);
    log_file('zoho_contact_search_phone.json', $data);
    if (!empty($data['data'][0]['id'])) {
        $contact_id = $data['data'][0]['id'];
        $type_of_customer = 'Renewal';
    }
}
// if still not found, create
if (!$contact_id) {
    // split name
    $parts = preg_split('/\s+/', trim($billing_name));
    $first = $parts[0] ?? '';
    $last  = isset($parts[1]) ? implode(' ', array_slice($parts, 1)) : '';

    $body = json_encode([
        "data" => [[
            "First_Name" => $first,
            "Last_Name"  => $last !== '' ? $last : '-',
            "Email"      => $billing_email,
            "Phone"      => $billing_tel
        ]]
    ]);
    $res = zpost(Z_BASE . "/Contacts", $headers, $body);
    $data = json_decode($res, true);
    log_file('zoho_contact_create.json', $data);
    $contact_id = $data['data'][0]['details']['id'] ?? null;
    $type_of_customer = 'Fresh';
}

// ---------- 7) prepare subform payload ----------
$subscription_details = [];
foreach ($sub_rows as $row) {
    $subscription_details[] = [
        "Product"            => $row['Product'],
        "Plan_Code"          => $row['Plan_Code'],
        "Exchanges"          => $row['Exchanges'],
        "Price_Before"       => $row['Price_Before'],
        "Price_After"        => $row['Price_After'],
        "Period_Days"        => $row['Period_Days'],
        "Expiry_Date"        => $row['Expiry_Date'],
        "Plan_Category"      => $row['Plan_Category'],
        "Subscription_Numb"  => $row['Subscription_Numb'],
        "Sub_ID"             => $row['Sub_ID']
    ];
}

// ---------- 8) upsert Deal by Reference_ID (order_id) ----------
$reference_id = $order_id ?: ('ORD_' . time());

// search existing deal
$deal_id = null;
$search_url = Z_BASE . "/Deals/search?criteria=(Reference_ID:equals:" . rawurlencode($reference_id) . ")";
$search_res = zget($search_url, $headers);
$search = json_decode($search_res, true);
log_file('zoho_deal_search.json', $search);
if (!empty($search['data'][0]['id'])) {
    $deal_id = $search['data'][0]['id'];
}

// build common fields
$deal_fields = [
    "Deal_Name"                  => $billing_name . " — " . $first_product_name,
    "Reference_ID"               => $reference_id,
    "Amount"                     => $amount,
    "Closing_Date"               => $today,

    // fixed fields you asked
    "Pipeline"                   => "Standard (Standard)",
    "Stage"                      => $stage,
    "Lead_Source"                => "Website",
    "Type_of_Enquiry"            => "Buy/Free Trial Data Products",
    "Data_Required_for_Exchange" => "Bombay Stock Exchange (BSE)",
    "Type_of_customer"           => $type_of_customer,

    // payment fields (optional)
    "Payment_Mode"               => $payment_mode,
    "Payment_Status"             => ($stage === 'Closed Won' ? 'captured' : 'failed'),

    // lookup to Contact
    "Contact_Name"               => $contact_id ? ["id" => $contact_id] : null,

    // subform
    "Subscription_Details"       => $subscription_details
];

// remove nulls to avoid API complaints
$deal_fields = array_filter($deal_fields, fn($v) => !is_null($v));

if ($deal_id) {
    // UPDATE
    $body = json_encode(["data" => [ $deal_fields ]]);
    $res  = zput(Z_BASE . "/Deals/$deal_id", $headers, $body);
    $out  = json_decode($res, true);
    log_file('zoho_deal_update.json', $out);
    $result = ["action" => "updated", "deal_id" => $deal_id, "zoho" => $out];
} else {
    // CREATE
    $body = json_encode(["data" => [ $deal_fields ]]);
    $res  = zpost(Z_BASE . "/Deals", $headers, $body);
    $out  = json_decode($res, true);
    log_file('zoho_deal_create.json', $out);
    $deal_id = $out['data'][0]['details']['id'] ?? null;
    $result = ["action" => "created", "deal_id" => $deal_id, "zoho" => $out];
}

// ---------- 9) final response to gateway (keep it short) ----------
echo json_encode([
    "status"        => "ok",
    "order_id"      => $reference_id,
    "stage"         => $stage,
    "deal_id"       => $deal_id,
    "contact_id"    => $contact_id,
    "type_customer" => $type_of_customer
], JSON_PRETTY_PRINT);
