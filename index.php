<?php
$working_key = 'D274F7613B83E3BCC204741394645086';
$access_code = 'AVCX86ML78BG42XCGB';
$URL = "https://api.ccavenue.com/apis/servlet/DoWebTrans";

header("Content-Type: application/json");

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);

if (!$input || !is_array($input)) {
    echo json_encode(["error" => "Invalid JSON input", "raw" => $raw]);
    exit;
}


$merchant_data = json_encode($input);

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
    echo json_encode([
        "status" => "0",
        "error" => "enc_response not valid HEX",
        "raw" => $enc_response
    ]);
    exit;
}

$decrypted = decrypt($enc_response_clean, $working_key);
$responseData = json_decode($decrypted, true);

echo json_encode([
    "status" => $status,
    "tiny_url" => $responseData['tiny_url'] ?? '',
    "full_response" => $responseData,
				 "enc_Request"=>$enc_response
				 
				 
]);

function encrypt($plainText,$key)
{
	$key = hextobin(md5($key));
	$initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
	$openMode = openssl_encrypt($plainText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
	$encryptedText = bin2hex($openMode);
	return $encryptedText;
}


function decrypt($encryptedText,$key)
{
	$key = hextobin(md5($key));
	$initVector = pack("C*", 0x00, 0x01, 0x02, 0x03, 0x04, 0x05, 0x06, 0x07, 0x08, 0x09, 0x0a, 0x0b, 0x0c, 0x0d, 0x0e, 0x0f);
	$encryptedText = hextobin($encryptedText);
	$decryptedText = openssl_decrypt($encryptedText, 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $initVector);
	return $decryptedText;
}

function hextobin($hexString) 
 { 
	$length = strlen($hexString); 
	$binString="";   
	$count=0; 
	while($count<$length) 
	{       
	    $subString =substr($hexString,$count,2);           
	    $packedString = pack("H*",$subString); 
	    if ($count==0)
	    {
			$binString=$packedString;
	    } 
	    
	    else 
	    {
			$binString.=$packedString;
	    } 
	    
	    $count+=2; 
	} 
        return $binString; 
  } 
?>
