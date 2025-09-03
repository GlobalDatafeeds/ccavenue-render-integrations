<?php
// ---------- CONFIG ----------
$WORKING_KEY = 'B410D0FB52051326F8B5F33B491A9230';
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
    curl_setopt_array($ch, [CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true]);
    $res=curl_exec($ch); curl_close($ch);
    $data=json_decode($res,true);
    return $data['access_token'] ?? null;
}

function zapi($method, $url, $body=null) {
    $token = zoho_access_token();
    $headers = ["Authorization: Zoho-oauthtoken $token", "Content-Type: application/json"];
    $ch = curl_init(Z_BASE . '/' . $url);
    $opts = [CURLOPT_HTTPHEADER=>$headers, CURLOPT_RETURNTRANSFER=>true];
    
    if($method==='POST') { $opts[CURLOPT_POST]=true; $opts[CURLOPT_POSTFIELDS]=json_encode($body); }
    if($method==='PUT') { $opts[CURLOPT_CUSTOMREQUEST]='PUT'; $opts[CURLOPT_POSTFIELDS]=json_encode($body); }
    
    curl_setopt_array($ch,$opts);
    $res=curl_exec($ch); curl_close($ch);
    return json_decode($res,true);
}

// ---------- 1) Accept webhook ----------
$encResp = $_POST['encResp'] ?? '';
if(!$encResp) { echo json_encode(["status"=>"error","message"=>"encResp missing"]); exit; }

$plain = cca_decrypt($encResp, $WORKING_KEY);
parse_str($plain, $cc);
log_file("decrypted_log.json",$cc);

// ---------- 2) Core data ----------
$order_id      = $cc['order_id'] ?? '';
$order_status  = strtolower($cc['order_status'] ?? 'failed');
$amount        = isset($cc['amount']) ? floatval($cc['amount']) : 0.0;
$payment_mode  = $cc['payment_mode'] ?? '';
$billing_name  = $cc['billing_name'] ?? '';
$billing_email = $cc['billing_email'] ?? '';
$billing_tel   = $cc['billing_tel'] ?? '';
$today         = date('Y-m-d');
$stage         = ($order_status==='success' || $order_status==='successful') ? 'Closed Won' : 'Closed Lost';

// ---------- 3) Status Update ----------
if($order_id){
    $search_res = zapi('GET', "Deals/search?criteria=(Reference_ID:equals:".rawurlencode($order_id).")");
    if(!empty($search_res['data'][0]['id'])){
        $deal_id = $search_res['data'][0]['id'];
        $upd = [
            "Paymet_Status" => ($stage==='Closed Won' ? 'captured' : 'failed'),
            "Payment_Mode" => $payment_mode
        ];
        $res = zapi('PUT', "Deals/$deal_id", ["data"=>[$upd]]);
        log_file("status_update.json",$res);
        echo json_encode(["status"=>"ok","mode"=>"status_update","deal_id"=>$deal_id]);
        exit;
    }
}

// ---------- 4) Full Automation (Create / Update Deal) ----------

// ----- Contact -----
$contact_id = null;
$type_of_customer='Fresh';
if($billing_email){
    $res = zapi('GET', "Contacts/search?criteria=(Email:equals:".rawurlencode($billing_email).")");
    if(!empty($res['data'][0]['id'])) { $contact_id=$res['data'][0]['id']; $type_of_customer='Renewal'; }
}
if(!$contact_id && $billing_tel){
    $res = zapi('GET', "Contacts/search?criteria=(Phone:equals:".rawurlencode($billing_tel).")");
    if(!empty($res['data'][0]['id'])) { $contact_id=$res['data'][0]['id']; $type_of_customer='Renewal'; }
}
if(!$contact_id){
    $parts = preg_split('/\s+/', $billing_name);
    $first = $parts[0] ?? '';
    $last  = isset($parts[1]) ? implode(' ',array_slice($parts,1)) : '-';
    $body = ["data"=>[["First_Name"=>$first,"Last_Name"=>$last,"Email"=>$billing_email,"Phone"=>$billing_tel]]];
    $res = zapi('POST', 'Contacts', $body);
    $contact_id = $res['data'][0]['details']['id'] ?? null;
}

// ----- Account -----
$account_id=null;
$res=zapi('GET', "Accounts/search?criteria=(Account_Name:equals:".rawurlencode($billing_name).")");
if(!empty($res['data'][0]['id'])){ $account_id=$res['data'][0]['id']; }
else{
    $body=["data"=>[["Account_Name"=>$billing_name]]];
    $res = zapi('POST', 'Accounts', $body);
    $account_id = $res['data'][0]['details']['id'] ?? null;
}

// ----- Subscription Details -----
$subscription_details=[];
for($i=1;$i<=5;$i++){
    $paramKey="merchant_param".$i;
    if(!empty($cc[$paramKey])){
        $parts = explode('|',$cc[$paramKey]);
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

// ----- Deal -----
$deal_fields=[
    "Deal_Name"=>$billing_name,
    "Reference_ID"=>$order_id?:('ORD_'.time()),
    "Amount"=>$amount,
    "Closing_Date"=>$today,
    "Pipeline"=>"Standard (Standard)",
    "Stage"=>$stage,
    "Lead_Source"=>"Website",
    "Type_of_Enquiry"=>"Buy/Free Trial Data Products",
    "Type_of_Customer"=>$type_of_customer,
    "Payment_Mode"=>$payment_mode,
    "Payment_Status"=>($stage==='Closed Won'?'captured':'failed'),
    "Contact_Name"=>$contact_id?["id"=>$contact_id]:null,
    "Account_Name"=>$account_id?["id"=>$account_id]:null,
    "Subscription_Details"=>array_values($subscription_details),
    "Owner"=>["id"=>"862056000002106001"]
];

log_file("zoho_payload.json",$deal_fields);

// Upsert Deal
$search_res = zapi('GET', "Deals/search?criteria=(Reference_ID:equals:".rawurlencode($deal_fields['Reference_ID']).")");
if(!empty($search_res['data'][0]['id'])){
    $deal_id=$search_res['data'][0]['id'];
    $res = zapi('PUT',"Deals/$deal_id",["data"=>[$deal_fields]]);
}else{
    $res = zapi('POST','Deals',["data"=>[$deal_fields]]);
    $deal_id = $res['data'][0]['details']['id'] ?? null;
}

// ---------- Response ----------
echo json_encode([
    "status"=>"ok",
    "mode"=>"full_automation",
    "order_id"=>$deal_fields['Reference_ID'],
    "stage"=>$stage,
    "deal_id"=>$deal_id,
    "contact_id"=>$contact_id,
    "account_id"=>$account_id,
    "type_customer"=>$type_of_customer
],JSON_PRETTY_PRINT);
