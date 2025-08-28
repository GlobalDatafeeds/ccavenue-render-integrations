<?php
// =====================================================
// ONE WEBHOOK for two scenarios (CCAvenue)
//  - Scenario A: Website payment -> create/upsert Deal (your mapping kept)
//  - Scenario B: Manual payment link -> update existing Deal status only
// =====================================================

// ---------- CONFIG ----------
$WORKING_KEY = 'B410D0FB52051326F8B5F33B491A9230'; // CCAvenue working key

define('Z_CLIENT_ID', '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC');
define('Z_CLIENT_SECRET', '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06');
define('Z_REFRESH_TOKEN', '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14');

define('Z_BASE', 'https://www.zohoapis.in/crm/v2');

// ---------- Helpers ----------
function log_file($name, $payload) {
    @file_put_contents($name, is_string($payload) ? $payload : json_encode($payload, JSON_PRETTY_PRINT));
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
        'client_id' => Z_CLIENT_ID,
        'client_secret' => Z_CLIENT_SECRET,
        'grant_type' => 'refresh_token'
    ]);
    $ch = curl_init('https://accounts.zoho.in/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $post,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res, true);
    return $data['access_token'] ?? null;
}

function zget($url, $headers) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function zpost($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

function zput($url, $headers, $body) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    return $res;
}

// ---------- 1) Accept webhook ----------
$encResp = $_POST['encResp'] ?? '';
log_file('webhook_log.json', $_POST);
if (!$encResp) { echo json_encode(["status"=>"error","message"=>"encResp missing"]); exit; }

// ---------- 2) Decrypt ----------
$plain = cca_decrypt($encResp, $WORKING_KEY);
parse_str($plain, $cc);
log_file('decrypted_log.json', $cc);

// ---------- 3) Common fields ----------
$order_id      = $cc['order_id'] ?? '';
$order_status  = strtolower($cc['order_status'] ?? 'failed');
$amount        = isset($cc['amount']) ? floatval($cc['amount']) : 0.0;
$billing_name  = trim($cc['billing_name'] ?? '');
$billing_email = trim($cc['billing_email'] ?? '');
$billing_tel   = trim($cc['billing_tel'] ?? '');
$payment_mode  = $cc['payment_mode'] ?? '';
$today         = date('Y-m-d');

// Determine success/failure
$is_success = ($order_status === 'success' || $order_status === 'successful');
$stage = $is_success ? 'Closed Won' : 'Closed Lost';

// Extract possible reference number for manual-update scenario
$mp1 = $cc['merchant_param1'] ?? '';
$inv_ref = $cc['inv_mer_reference_no'] ?? '';
$ref_candidate = $inv_ref ?: $mp1;

// ---------- 4) Decide flow ----------
// RULES:
// - Scenario B (Manual): merchant_param1 has NO '|' (i.e., not the subscription pipe string) OR inv_mer_reference_no is present.
// - Scenario A (Website): merchant_param1 is your pipe-separated subscription data (contains '|') OR no reference provided.

$manual_flag = false;
if (!empty($inv_ref)) {
    $manual_flag = true;
} elseif (!empty($mp1) && strpos($mp1, '|') === false) {
    // Plain reference id in merchant_param1 (no pipe) => manual link flow
    $manual_flag = true;
}

// ---------- 5) Zoho access ----------
$token = zoho_access_token();
if (!$token) { echo json_encode(['status'=>'error','message'=>'Zoho token missing']); exit; }
$headers = ["Authorization: Zoho-oauthtoken $token", "Content-Type: application/json"];

// =====================================================
// SCENARIO B: Manual payment link -> UPDATE ONLY
// (Do not change Deal mappings; just set Payment_Status & Payment_Mode)
// =====================================================
if ($manual_flag) {
    $refNo = trim($ref_candidate) ?: 'NA';
    $status_norm = $is_success ? 'captured' : 'failed'; // align with your first code’s Payment_Status values

    // Find Deal by Reference_ID
    if ($refNo !== 'NA') {
        $search_url = Z_BASE."/Deals/search?criteria=(Reference_ID:equals:".rawurlencode($refNo).")";
        $search_res = zget($search_url,$headers);
        log_file('zoho_search_manual.json', $search_res);
        $search = json_decode($search_res,true);

        if (!empty($search['data'][0]['id'])) {
            $deal_id = $search['data'][0]['id'];

            // UPDATE ONLY minimal fields for manual flow
            $update_body = json_encode(["data"=>[[
                "Payment_Status" => $status_norm,
                "Payment_Mode"   => $payment_mode
                // NOTE: Not touching Stage, Amount, etc. in manual flow.
            ]]]);

            $update_res = zput(Z_BASE."/Deals/$deal_id", $headers, $update_body);
            log_file('zoho_update_manual.json', $update_res);

            echo json_encode([
                "status"        => "ok",
                "flow"          => "manual_update",
                "reference_no"  => $refNo,
                "order_status"  => $order_status,
                "payment_mode"  => $payment_mode,
                "deal_id"       => $deal_id
            ], JSON_PRETTY_PRINT);
            exit;
        } else {
            // No Deal found — log and fall back? We will exit with not found to avoid accidental create.
            echo json_encode([
                "status"       => "error",
                "flow"         => "manual_update",
                "message"      => "No Deal found for Reference_ID",
                "reference_no" => $refNo
            ], JSON_PRETTY_PRINT);
            exit;
        }
    } else {
        echo json_encode([
            "status"  => "error",
            "flow"    => "manual_update",
            "message" => "Missing reference number"
        ], JSON_PRETTY_PRINT);
        exit;
    }
}

// =====================================================
// SCENARIO A: Website payment -> CREATE/UPSERT DEAL
// (Keeps your original mappings/logic)
// =====================================================

// ---------- Contact ----------
$contact_id = null;
$type_of_customer = 'Fresh';

// search/create contact by email
if ($billing_email) {
    $res = zget(Z_BASE."/Contacts/search?criteria=(Email:equals:".rawurlencode($billing_email).")", $headers);
    $data = json_decode($res,true);
    if (!empty($data['data'][0]['id'])) { $contact_id = $data['data'][0]['id']; $type_of_customer='Renewal'; }
}
// then by phone
if (!$contact_id && $billing_tel) {
    $res = zget(Z_BASE."/Contacts/search?criteria=(Phone:equals:".rawurlencode($billing_tel).")", $headers);
    $data = json_decode($res,true);
    if (!empty($data['data'][0]['id'])) { $contact_id = $data['data'][0]['id']; $type_of_customer='Renewal'; }
}
// create if still not found
if (!$contact_id) {
    $parts = preg_split('/\s+/', trim($billing_name));
    $first = $parts[0] ?? ''; $last = isset($parts[1]) ? implode(' ',array_slice($parts,1)) : '-';
    $body = json_encode(["data"=>[[
        "First_Name"=>$first, "Last_Name"=>$last, "Email"=>$billing_email, "Phone"=>$billing_tel
    ]]]);
    $res = zpost(Z_BASE."/Contacts",$headers,$body);
    $data = json_decode($res,true);
    $contact_id = $data['data'][0]['details']['id'] ?? null;
}

// ---------- Subscription details (your parsing kept) ----------
$subscription_details = [];
if(!empty($cc['merchant_param1'])) {
    $parts = explode('|',$cc['merchant_param1']);
    $subscription_details[] = [
        "Product"=>$parts[1]??'',
        "Period_Days"=>(int)($parts[2]??0),
        "Exchanges"=>$parts[3]??'',
        "Price_Before"=>(float)($parts[7]??0),
        "Price_After"=>(float)($parts[8]??0),
        "Expiry_Date"=>$today,
        "Plan_Category"=>$parts[4]??'',
        "Subscription_Numb"=>'',
        "Sub_ID"=>''
    ];
}

// ---------- Deal payload (UNCHANGED mapping) ----------
$deal_reference = $order_id ?: ('ORD_'.time());
$deal_fields = [
    "Deal_Name"=>$billing_name,
    "Reference_ID"=>$deal_reference,
    "Amount"=>$amount,
    "Closing_Date"=>$today,
    "Pipeline"=>"Standard (Standard)",
    "Stage"=>$stage,
    "Lead_Source"=>"Website",
    "Type_of_Enquiry"=>"Buy/Free Trial Data Products",
    "Data_Required_for_Exchange"=>["Bombay Stock Exchange (BSE)"],
    "Type_of_Customer"=>$type_of_customer,
    "Payment_Mode"=>$payment_mode,
    "Payment_Status"=>($is_success ? 'captured' : 'failed'),
    "Contact_Name"=>$contact_id ? ["id"=>$contact_id]:null,
    "Subscription_Details"=>$subscription_details,
    "Owner"=> ["id" => "862056000002106001"]
];

// ---------- Upsert by Reference_ID (UNCHANGED behavior) ----------
$deal_id = null;
$search_url = Z_BASE."/Deals/search?criteria=(Reference_ID:equals:".rawurlencode($deal_fields['Reference_ID']).")";
$search_res = zget($search_url,$headers);
$search = json_decode($search_res,true);

if(!empty($search['data'][0]['id'])){
    $deal_id=$search['data'][0]['id'];
    $body = json_encode(["data"=>[$deal_fields]]);
    $res = zput(Z_BASE."/Deals/$deal_id",$headers,$body);
    log_file('zoho_update_auto.json', $res);
}else{
    $body = json_encode(["data"=>[$deal_fields]]);
    $res = zpost(Z_BASE."/Deals",$headers,$body);
    log_file('zoho_create_auto.json', $res);
    $out = json_decode($res,true);
    $deal_id = $out['data'][0]['details']['id'] ?? null;
}

// ---------- Final response ----------
echo json_encode([
    "status"=>"ok",
    "flow"  => "website_create",
    "order_id"=>$deal_fields['Reference_ID'],
    "stage"=>$stage,
    "deal_id"=>$deal_id,
    "contact_id"=>$contact_id,
    "type_customer"=>$type_of_customer
],JSON_PRETTY_PRINT);
