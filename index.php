<?php
// index.php - generate CCAvenue quick-invoice (tiny_url)
// Put this on Render and point your Zoho Blueprint invokeURL to it.

// CONFIG: prefer environment variables, fallback to hard-coded for local testing
$WORKING_KEY = getenv('CCA_WORKING_KEY') ?: 'B410D0FB52051326F8B5F33B491A9230';
$ACCESS_CODE  = getenv('CCA_ACCESS_CODE')  ?: 'AVBG77FE89AQ50GBQA';
$CCA_URL = "https://api.ccavenue.com/apis/servlet/DoWebTrans";

// Read JSON input (Zoho sendBody or invokeurl JSON)
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!$input) {
    // also accept form-encoded POST for quick test
    $input = $_POST ?: [];
}

// Required: ensure merchant_param1 (Reference_ID) exists
$merchant_param1 = $input['merchant_param1'] ?? ($input['Reference_ID'] ?? null);
if (!$merchant_param1) {
    http_response_code(400);
    echo json_encode(["error" => "Reference_ID / merchant_param1 required"]);
    exit;
}

// Build merchant payload (example fields - include whatever CCAvenue expects)
$merchant_data = [
    "customer_name" => $input['customer_name'] ?? ($input['billing_name'] ?? ''),
    "customer_email_id" => $input['customer_email'] ?? ($input['billing_email'] ?? ''),
    "bill_delivery_type" => "email",
    "customer_email_subject" => $input['email_subject'] ?? "Invoice",
    "invoice_description" => $input['invoice_description'] ?? "Order",
    "currency" => $input['currency'] ?? "INR",
    "valid_for" => $input['valid_for'] ?? "2",
    "valid_type" => $input['valid_type'] ?? "days",
    "merchant_reference_no" => $merchant_param1,
    "amount" => (string) ($input['amount'] ?? '1.00')
];

// Convert to JSON string (CCAvenue expects JSON when request_type=JSON)
$merchant_json = json_encode($merchant_data);

// --- helper: hex-to-bin used by CCA encryption ---
function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}
function encryptCCA($plainText, $key) {
    // AES-128-CBC with key = hextobin(md5($key)), IV = bytes 0..15
    $keyBin = hextobin(md5($key));
    $iv = pack("C*", ...range(0,15));
    $encrypted = openssl_encrypt($plainText, 'AES-128-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
    return bin2hex($encrypted);
}

// Prepare POST body for CCAvenue
$enc_request = encryptCCA($merchant_json, $WORKING_KEY);
$postFields = http_build_query([
    'request_type' => 'JSON',
    'access_code'  => $ACCESS_CODE,
    'command'      => 'generateQuickInvoice',
    'version'      => '1.2',
    'response_type'=> 'JSON',
    'enc_request'  => $enc_request
]);

// CURL to CCAvenue
$ch = curl_init($CCA_URL);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postFields,
    CURLOPT_SSL_VERIFYPEER => 0,
    CURLOPT_TIMEOUT => 30
]);
$result = curl_exec($ch);
$curlErr = curl_error($ch);
curl_close($ch);

// Parse response (CCAvenue returns form-encoded: enc_response=...&status=...)
$responseParts = [];
parse_str($result, $responseParts);
$enc_response = $responseParts['enc_response'] ?? null;
$status = $responseParts['status'] ?? null;

if (!$enc_response) {
    // if error, return raw result
    http_response_code(500);
    echo json_encode(["error" => "no enc_response", "raw" => $result, "curl_error" => $curlErr]);
    exit;
}

// Decrypt enc_response (CCA returns hex; convert then decrypt)
function decryptCCAResponse($encHex, $key) {
    $clean = preg_replace('/[^a-fA-F0-9]/', '', $encHex);
    if ($clean === "") return false;
    $bin = hextobin($clean);
    $keyBin = hextobin(md5($key));
    $iv = pack("C*", ...range(0,15));
    return openssl_decrypt($bin, 'AES-128-CBC', $keyBin, OPENSSL_RAW_DATA, $iv);
}

$decrypted = decryptCCAResponse($enc_response, $WORKING_KEY);
if ($decrypted === false) {
    http_response_code(500);
    echo json_encode(["error" => "failed to decrypt enc_response", "raw_enc_response" => $enc_response]);
    exit;
}

// parse decrypted (it's JSON when we used request_type=JSON)
$data = json_decode($decrypted, true);

// return tiny_url or full response to caller (Zoho)
$result_out = [
    "status" => $status,
    "decrypted" => $data
];
if (isset($data['tiny_url'])) $result_out['tiny_url'] = $data['tiny_url'];

echo json_encode($result_out, JSON_PRETTY_PRINT);
