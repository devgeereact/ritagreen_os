<?php
// OpenRouter proxy. Every AI call in RitaGreenOS routes through here so the
// API key stays server-side and never reaches the browser.
// POST /ai.php {prompt, system?} -> { reply }
require __DIR__ . '/lib.php';

// AI is for logged-in staff only.
require_auth($conn);

if (method() !== 'POST') fail('Method not allowed.', 405);

if (OR_API_KEY === 'CHANGE_ME' || OR_API_KEY === '') {
    fail('AI is not configured. Set OR_API_KEY in config.php.', 503);
}

$data = body();
$prompt = trim((string) ($data['prompt'] ?? ''));
$system = trim((string) ($data['system'] ?? 'You are a helpful assistant for RitaGreen, a UK care, training, cleaning and security group. Be concise and use UK English.'));
if ($prompt === '') fail('A prompt is required.');

$payload = json_encode([
    'model' => OR_MODEL,
    'messages' => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => $prompt],
    ],
]);

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . OR_API_KEY,
        'HTTP-Referer: https://ritagreenservices.com',
        'X-Title: RitaGreenOS',
    ],
    CURLOPT_TIMEOUT => 60,
]);
$response = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($response === false) fail('Could not reach the AI service: ' . $curlErr, 502);
if ($status >= 400) fail('The AI service returned an error.', 502);

$json = json_decode($response, true);
$reply = $json['choices'][0]['message']['content'] ?? null;
if ($reply === null) fail('The AI service returned an unexpected response.', 502);

json_out(['reply' => $reply]);
