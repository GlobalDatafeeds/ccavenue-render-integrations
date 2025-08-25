<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

$encResp = $_POST['encResp'] ?? '';
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

// --- Decrypt POST data ---
$decrypted = decrypt($encResp, $working_key);
parse_str($decrypted, $parsed);

// --- Extract key data ---
$refNo = $parsed['merchant_param1'] ?? $parsed['order_id'] ?? 'NA';
$status = strtolower($parsed['order_status'] ?? 'Unknown');
$status = $status === 'success' ? 'captured' : 'failed';
$paymentMode = $parsed['payment_mode'] ?? 'upi';
$amount = isset($parsed['amount']) ? (float)$parsed['amount'] : 0;
$products = $parsed['merchant_param2'] ?? '';

// --- Return JSON directly for debugging ---
$response = [
    "Reference_No" => $refNo,
    "Payment_Status" => $status,
    "Payment_Mode" => $paymentMode,
    "Amount" => $amount,
    "Products" => $products,
    "Raw_Post_Data" => $_POST,
    "Decrypted_Data" => $parsed
];

header('Content-Type: application/json');
echo json_encode($response, JSON_PRETTY_PRINT);
?>
