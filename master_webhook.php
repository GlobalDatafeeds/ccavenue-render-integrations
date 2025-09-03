<?php
// ---------------- CONFIG ----------------
$webhook1_url = "https://ccavenue-render-integrations.onrender.com/ccavenue_webhook.php"; // Payment Status
$webhook2_url = "https://ccavenue-render-integrations.onrender.com/webhook.php";        // Deal / Automation

// ---------------- LOG RECEIVED DATA ----------------
$incoming_post = $_POST;
file_put_contents("master_webhook_log.json", json_encode($incoming_post, JSON_PRETTY_PRINT));

// ---------------- FORWARD FUNCTION ----------------
function forwardWebhook($url, $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    return ['response' => $response, 'error' => $error];
}

// ---------------- FORWARD TO BOTH WEBHOOKS ----------------
$result1 = forwardWebhook($webhook1_url, $incoming_post);
$result2 = forwardWebhook($webhook2_url, $incoming_post);

// ---------------- LOG FOR DEBUG ----------------
file_put_contents("forward_log_webhook1.json", json_encode($result1, JSON_PRETTY_PRINT));
file_put_contents("forward_log_webhook2.json", json_encode($result2, JSON_PRETTY_PRINT));

// ---------------- RETURN RESPONSE TO CCAvenue ----------------
echo json_encode([
    "status" => "received",
    "forwarded_to" => [
        "payment_webhook" => $webhook1_url,
        "deal_webhook" => $webhook2_url
    ]
], JSON_PRETTY_PRINT);
?>
