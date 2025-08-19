<?php
// ================== CONFIG ==================
$WORKING_KEY = 'B410D0FB52051326F8B5F33B491A9230';
$ACCESS_CODE  = 'AVBG77FE89AQ50GBQA';
$ZOHO_CLIENT_ID = '1000.QT7DOYHYASD7JCOEOIW41AOXO1I3NC';
$ZOHO_CLIENT_SECRET = '3cdc3a3ccb8411df5cb4dfbe10f8b5a9c43c43ec06';
$ZOHO_REFRESH_TOKEN = '1000.49e678cd6058a884a5da991f79238c67.907c8e04ac8dd556021b441423b97b14';
$CCA_URL = "https://api.ccavenue.com/apis/servlet/DoWebTrans";

header("Content-Type: application/json");

// ================== FUNCTIONS ==================

// Hex to Binary
function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}

// Encrypt for CCAvenue
function encryptCCA($plainText, $key) {
    $keyBin = hextobin(md5($key));
    $iv = pack("C*", ...range(0,15));
    $encrypted = openssl_encrypt($plainText, 'AES-128-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return bin2hex($encrypted);
}

// Decrypt CCAvenue response
function decryptCCA($encText, $key) {
    $keyBin = hextobin(md5($key));
    $iv = pack("C*", ...range(0,15));
    $encBin = hextobin($encText);
    return openssl_decrypt($encBin, 'AES-128-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
}

// Get Zoho Access Token
function getZohoAccessToken($client_id, $client_secret, $refresh_token) {
    $postData = http_build_query([
        'refresh_token' => $refresh_token,
        'client_id' => $client_id,
        'client_secret' => $client_secret,
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

// Update Zoho CRM Deal
function updateZohoDeal($access_token, $refNo, $status, $paymentMode) {
    $module = "Deals";
    $search_url = "https://www.zohoapis.in/crm/v2/$module/search?criteria=(Reference_ID:equals:$refNo)";
    $headers = [
        "Authorization: Zoho-oauthtoken $access_token",
        "Content-Type: application/json"
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $search_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $search_response = curl_exec($ch);
    curl_close($ch);
    $search_result = json_decode($search_response, true);

    if (isset($search_result['data'][0]['id'])) {
        $deal_id = $search_result['data'][0]['id'];
        $update_url = "https://www.zohoapis.in/crm/v2/$module/$deal_id";
        $update_body = json_encode([
            "data" => [[
                "Payment_Status" => $status,
                "Payment_Mode" => $paymentMode
            ]]
        ]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $update_url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $update_body);
        $update_response = curl_exec($ch);
        curl_close($ch);
        return $update_response;
    }
    return null;
}

// Generate CCAvenue Tiny Invoice
function generateCCAInvoice($input, $WORKING_KEY, $ACCESS_CODE, $CCA_URL) {
    $merchant_data = json_encode($input);
    $enc_request = encryptCCA($merchant_data, $WORKING_KEY);
    $postFields = http_build_query([
        'request_type' => 'JSON',
        'access_code'  => $ACCESS_CODE,
        'command'      => 'generateQuickInvoice',
        'version'      => '1.2',
        'response_type'=> 'JSON',
        'enc_request'  => $enc_request
    ]);

    $ch = curl_init($CCA_URL);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $result = curl_exec($ch);
    curl_close($ch);

    parse_str($result, $responseParts);
    $enc_response = $responseParts['enc_response'] ?? '';
    $status = $responseParts['status'] ?? '0';

    if (!$enc_response) return ["status" => 0, "error" => "No enc_response", "raw" => $result];

    $decrypted = decryptCCA($enc_response, $WORKING_KEY);
    $data = json_decode($decrypted, true);

    return [
        "status" => $status,
        "tiny_url" => $data['tiny_url'] ?? '',
        "full_response" => $data
    ];
}

// ================== MAIN ==================

// 1) Receive request from your frontend or CRM Blueprint
$input = json_decode(file_get_contents("php://input"), true) ?: $_POST;
if (!$input) {
    echo json_encode(["error" => "No input received"]);
    exit;
}

// Input must have at least: Reference_ID, customer_name, customer_email, amount
$required = ['Reference_ID', 'customer_name', 'customer_email', 'amount'];
foreach ($required as $r) {
    if (!isset($input[$r])) {
        echo json_encode(["error" => "$r is required"]);
        exit;
    }
}

// 2) Generate CCAvenue payment link
$ccaResponse = generateCCAInvoice($input, $WORKING_KEY, $ACCESS_CODE, $CCA_URL);

// 3) Update Zoho CRM Deal
$access_token = getZohoAccessToken($ZOHO_CLIENT_ID, $ZOHO_CLIENT_SECRET, $ZOHO_REFRESH_TOKEN);
if ($access_token) {
    updateZohoDeal($access_token, $input['Reference_ID'], "pending", "CCAvenue");
}

// 4) Return CCAvenue link to caller
echo json_encode([
    "status" => $ccaResponse['status'],
    "tiny_url" => $ccaResponse['tiny_url'] ?? '',
    "full_response" => $ccaResponse['full_response'] ?? ''
], JSON_PRETTY_PRINT);

?>
