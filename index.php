<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230';
$access_code = 'AVBG77FE89AQ50GBQA';
$URL = "https://api.ccavenue.com/apis/servlet/DoWebTrans";

header("Content-Type: application/json");

// --- Read input ---
$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    echo json_encode(["error" => "Invalid JSON input", "raw" => $raw]);
    exit;
}

// --- Add reference & product data ---
$input['merchant_param1'] = $input['merchant_param1'] ?? uniqid('deal_'); // Deal reference
$input['merchant_param2'] = json_encode($input['products'] ?? []); // Product array as JSON

$merchant_data = json_encode($input);

// --- Encrypt CCAvenue request ---
$enc_request = encrypt($merchant_data, $working_key);

$final_data = "request_type=JSON&access_code=$access_code&command=generateQuickInvoice&version=1.2&response_type=JSON&enc_request=$enc_request";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $URL);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $final_data);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
$result = curl_exec($ch);
curl_close($ch);

parse_str($result, $responseParts);
$enc_response = $responseParts['enc_response'] ?? '';
$status = $responseParts['status'] ?? '0';

$enc_response_clean = preg_replace('/[^a-fA-F0-9]/', '', $enc_response);
if (strlen($enc_response_clean) % 2 !== 0) {
    echo json_encode(["status"=>"0","error"=>"enc_response not valid HEX","raw"=>$enc_response]);
    exit;
}

$decrypted = decrypt($enc_response_clean, $working_key);
$responseData = json_decode($decrypted, true);

// --- Return payment link & reference ---
echo json_encode([
    "status" => $status,
    "tiny_url" => $responseData['tiny_url'] ?? '',
    "reference_id" => $input['merchant_param1'],
    "full_response" => $responseData
]);

// --- Encryption Functions ---
function encrypt($plainText, $key) {
    $key = hextobin(md5($key));
    $initVector = pack("C*", ...range(0, 15));
    return bin2hex(openssl_encrypt($plainText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector));
}

function decrypt($encryptedText, $key) {
    $key = hextobin(md5($key));
    $initVector = pack("C*", ...range(0, 15));
    $encryptedText = hextobin($encryptedText);
    return openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
}

function hextobin($hexString) {
    $bin = "";
    for ($i = 0; $i < strlen($hexString); $i += 2) {
        $bin .= pack("H*", substr($hexString, $i, 2));
    }
    return $bin;
}
?>
