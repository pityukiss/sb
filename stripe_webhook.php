<?php

include __DIR__ . '/db.php';
include __DIR__ . '/stripe_helper.php';

http_response_code(200);

if (!shobidStripeConfigElerheto()) {
    http_response_code(500);
    echo 'Stripe config hianyzik';
    exit;
}

$payload = file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

if ($payload === false || trim((string)$payload) === '') {
    http_response_code(400);
    echo 'Ures payload';
    exit;
}

if ($sigHeader === '') {
    http_response_code(400);
    echo 'Hianyzik az alairas';
    exit;
}

function shobidStripeVerifySignature($payload, $sigHeader, $secret, $tolerance = 300) {
    $parts = [];
    foreach (explode(',', (string)$sigHeader) as $piece) {
        $pair = explode('=', trim($piece), 2);
        if (count($pair) === 2) {
            $parts[$pair[0]][] = $pair[1];
        }
    }

    $timestamp = isset($parts['t'][0]) ? intval($parts['t'][0]) : 0;
    $signatures = $parts['v1'] ?? [];
    if ($timestamp < 1 || !$signatures) {
        return false;
    }

    if (abs(time() - $timestamp) > $tolerance) {
        return false;
    }

    $signedPayload = $timestamp . '.' . $payload;
    $expected = hash_hmac('sha256', $signedPayload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

if (!shobidStripeVerifySignature($payload, $sigHeader, SHOBID_STRIPE_WEBHOOK_SECRET)) {
    http_response_code(400);
    echo 'Ervenytelen alairas';
    exit;
}

$event = json_decode($payload, true);
if (!is_array($event)) {
    http_response_code(400);
    echo 'Hibas JSON';
    exit;
}

$eventType = trim((string)($event['type'] ?? ''));
$object = $event['data']['object'] ?? [];

if ($eventType === 'checkout.session.completed') {
    $sessionId = trim((string)($object['id'] ?? ''));
    $paymentIntentId = trim((string)($object['payment_intent'] ?? ''));
    $mode = trim((string)($object['mode'] ?? ''));
    if ($mode === 'setup') {
        shobidStripeSetupSessionRogzites($conn, $sessionId);
    } else {
        shobidStripeFixVasarlasTeljesites($conn, $sessionId, $paymentIntentId);
    }
}

echo 'ok';
