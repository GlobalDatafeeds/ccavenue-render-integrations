<?php
// ---------------- CONFIG ----------------
$WORKING_KEY = 'B410D0FB52051326F8B5F33B491A9230';

define('Z_CLIENT_ID', '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC');
define('Z_CLIENT_SECRET', '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06');
define('Z_REFRESH_TOKEN', '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14');
define('Z_BASE', 'https://www.zohoapis.in/crm/v2');

// ---------------- HELPERS ----------------
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
function decrypt_ccavenue($encryptedText, $workingKey) {
    $key = hextobin(md5($workingKey));
    $iv  = pack("C*", ...range(0, 15));
    $encryptedText = hextobin($encryptedText);
    return openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
}

// Zoho token helpers
function getZohoAccessToken() {
    $postData = http_build_query([
        'refresh_token' => Z_REFRESH_TOKEN,
        'client_id' => Z_CLIENT_ID,
        'client_secret' => Z_CLIENT_SECRET,
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

// Zoho request helpers
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

// ---------------- 1) Accept webhook ----------------
$encResp = $_POST['encResp'] ?? '';
log_file('webhook_log.json', $_POST);
if (!$encResp) {
    echo json_encode(["status" => "error", "message" => "encResp not found"]);
    exit;
}

// ---------------- 2) Decrypt ----------------
$plain = decrypt_ccavenue($encResp, $WORKING_KEY);
parse_str($plain, $cc);
log_file('decrypted_log.json', $cc);

// ---------------- Decide MODE ----------------
$refNo = $cc['inv_mer_reference_no'] ?? '';
if (!empty($refNo)) {
    // ---------------- MANUAL UPDATE ----------------
    $statusRaw = strtolower($cc['order_status'] ?? 'Unknown');
    $status = $statusRaw === 'success' ? 'success' : 'failed';
    $paymentMode = $cc['payment_mode'] ?? 'Unknown';

    echo json_encode([
        "status" => "received",
        "reference_no" => $refNo,
        "order_status" => $status,
        "payment_mode" => $paymentMode
    ]);

    $access_token = getZohoAccessToken();
    if (!$access_token) {
        file_put_contents("zoho_error.json", json_encode(["error" => "Missing access_token"]));
        exit;
    }

    $module = "Deals";
    $search_url = Z_BASE."/Deals/search?criteria=(Reference_ID:equals:$refNo)";
    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];

    $search_response = zget($search_url, $headers);
    file_put_contents("zoho_search_log.json", $search_response);
    $search_result = json_decode($search_response, true);

    if (isset($search_result['data'][0]['id'])) {
        $deal_id = $search_result['data'][0]['id'];
        $update_body = json_encode([
            "data" => [[
                "Paymet_Status" => $status,
                "Payment_Mode" => $paymentMode
            ]]
        ]);
        $update_response = zput(Z_BASE."/Deals/$deal_id", $headers, $update_body);
        file_put_contents("zoho_update_log.json", $update_response);
    }
    exit;
}

// ---------------- WEBSITE ORDER ----------------
$order_id      = $cc['order_id'] ?? '';
$order_status  = strtolower($cc['order_status'] ?? 'failed');
$amount        = isset($cc['amount']) ? floatval($cc['amount']) : 0.0;
$billing_name  = trim($cc['billing_name'] ?? '');
$billing_email = trim($cc['billing_email'] ?? '');
$billing_tel   = trim($cc['billing_tel'] ?? '');
$payment_mode  = $cc['payment_mode'] ?? '';
$today         = date('Y-m-d');
$stage = ($order_status==='success' || $order_status==='successful')?'Closed Won':'Closed Lost';

$token = getZohoAccessToken();
if (!$token) { echo json_encode(['status'=>'error','message'=>'Zoho token missing']); exit; }
$headers = ["Authorization: Zoho-oauthtoken $token", "Content-Type: application/json"];

// ---------- Contact ----------
$contact_id = null;
$type_of_customer = 'Fresh';
if ($billing_email) {
    $res = zget(Z_BASE."/Contacts/search?criteria=(Email:equals:".rawurlencode($billing_email).")",$headers);
    $data = json_decode($res,true);
    if(!empty($data['data'][0]['id'])){ $contact_id=$data['data'][0]['id']; $type_of_customer='Renewal'; }
}
if(!$contact_id && $billing_tel){
    $res = zget(Z_BASE."/Contacts/search?criteria=(Phone:equals:".rawurlencode($billing_tel).")",$headers);
    $data = json_decode($res,true);
    if(!empty($data['data'][0]['id'])){ $contact_id=$data['data'][0]['id']; $type_of_customer='Renewal'; }
}
if(!$contact_id){
    $parts = preg_split('/\s+/', trim($billing_name));
    $first=$parts[0]??''; $last=isset($parts[1])?implode(' ',array_slice($parts,1)):'-';
    $body=json_encode(["data"=>[["First_Name"=>$first,"Last_Name"=>$last,"Email"=>$billing_email,"Phone"=>$billing_tel]]]);
    $res = zpost(Z_BASE."/Contacts",$headers,$body);
    $data=json_decode($res,true);
    $contact_id=$data['data'][0]['details']['id']??null;
}

// ---------- Account ----------
$account_name_value = $billing_name;
$account_id=null;
$res = zget(Z_BASE."/Accounts/search?criteria=(Account_Name:equals:".rawurlencode($account_name_value).")",$headers);
$data=json_decode($res,true);
if(!empty($data['data'][0]['id'])){ $account_id=$data['data'][0]['id']; }
else{
    $body=json_encode(["data"=>[["Account_Name"=>$account_name_value]]]);
    $res = zpost(Z_BASE."/Accounts",$headers,$body);
    $data=json_decode($res,true);
    $account_id=$data['data'][0]['details']['id']??null;
}

// ---------- Subscription Details ----------
$subscription_details=[];
for($i=1;$i<=5;$i++){
    $paramKey="merchant_param".$i;
    if(!empty($cc[$paramKey])){
        $parts=explode('|',$cc[$paramKey]);
        $subscription_details[]=[
            "Product"=>$parts[1]??'',
            "Period_Days"=>(int)($parts[2]??0),
            "Exchanges"=>$parts[3]??'',
            "Plan_Category"=>$parts[4]??'',
            "Extra1"=>$parts[5]??'',
            "Quantity"=>(int)($parts[6]??1),
            "Price_Before"=>(float)($parts[7]??0),
            "Price_After"=>(float)($parts[8]??0),
            "Expiry_Date"=>$today,
            "Subscription_Numb"=>'',
            "Sub_ID"=>''
        ];
    }
}

// ---------- Deal ----------
$deal_fields=[
    "Deal_Name"=>$billing_name,
    "Reference_ID"=>$order_id?:('ORD_'.time()),
    "Amount"=>$amount,
    "Closing_Date"=>$today,
    "Pipeline"=>"Standard (Standard)",
    "Stage"=>$stage,
    "Lead_Source"=>"Website",
    "Type_of_Enquiry"=>"Buy/Free Trial Data Products",
    "Data_Required_for_Exchange"=>["Bombay Stock Exchange (BSE)"],
    "Type_of_Customer"=>$type_of_customer,
    "Payment_Mode"=>$payment_mode,
    "Payment_Status"=>($stage==='Closed Won'?'captured':'failed'),
    "Contact_Name"=>$contact_id?["id"=>$contact_id]:null,
    "Account_Name"=>$account_id?["id"=>$account_id]:null,
    "Subscription_Details"=>array_values($subscription_details),
    "Owner"=>["id"=>"862056000002106001"]
];
log_file('zoho_payload.json',$deal_fields);

// ---------- Upsert Deal ----------
$deal_id=null;
$search_url = Z_BASE."/Deals/search?criteria=(Reference_ID:equals:".rawurlencode($deal_fields['Reference_ID']).")";
$search_res = zget($search_url,$headers);
$search = json_decode($search_res,true);

if(!empty($search['data'][0]['id'])){
    $deal_id=$search['data'][0]['id'];
    $res=zput(Z_BASE."/Deals/$deal_id",$headers,json_encode(["data"=>[$deal_fields]]));
}else{
    $res=zpost(Z_BASE."/Deals",$headers,json_encode(["data"=>[$deal_fields]]));
    $out=json_decode($res,true);
    $deal_id=$out['data'][0]['details']['id']??null;
}

// ---------- Response ----------
echo json_encode([
    "status"=>"ok",
    "order_id"=>$deal_fields['Reference_ID'],
    "stage"=>$stage,
    "deal_id"=>$deal_id,
    "contact_id"=>$contact_id,
    "account_id"=>$account_id,
    "type_customer"=>$type_of_customer
],JSON_PRETTY_PRINT);
