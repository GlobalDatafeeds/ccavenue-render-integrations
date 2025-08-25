<?php
$working_key = 'B410D0FB52051326F8B5F33B491A9230';

// Step 1: Save raw POST data
$encResp = $_POST['encResp'] ?? '';
file_put_contents("webhook_log.json", json_encode($_POST));

if (!$encResp) {
    http_response_code(400);
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

// Step 2: Decrypt and save
$decrypted = decrypt($encResp, $working_key);
parse_str($decrypted, $parsed);
file_put_contents("decrypted_log.json", json_encode($parsed, JSON_PRETTY_PRINT));

// Step 3: Display decrypted response on browser (for debug)
header("Content-Type: application/json");
echo json_encode([
    "status" => "success",
    "message" => "Webhook received successfully",
    "decrypted_data" => $parsed
], JSON_PRETTY_PRINT);

// Step 4: Send proper 200 OK to CC Avenue
http_response_code(200);
exit;
?>
