<?php
// OpenRouter proxy. Every AI call in RitaGreenOS routes through here so the
// API key stays server-side and never reaches the browser.
// POST /ai.php {prompt, system?} -> { reply }
require __DIR__ . '/lib.php';

// AI is for logged-in staff only.
//
// This said so and did not do it: require_auth() alone let a client-portal login
// and a worker-app login through, and neither has any AI feature in their
// interface. Every call here spends real OpenRouter credit against a key nobody
// but the office should be able to draw on.
$user = require_auth($conn);
require_staff($user);

if (method() !== 'POST') fail('Method not allowed.', 405);

// Spend control. There was none: one account could hold the endpoint open in a
// loop and run the balance to zero, and the first anyone would know is a failing
// invoice. Per account rather than per address, because the cost follows the
// account and an office shares an address.
rate_limit($conn, 'ai', 40, 3600, 'u' . (int) $user['id']);

if (OR_API_KEY === 'CHANGE_ME' || OR_API_KEY === '') {
    fail('AI is not configured. Set OR_API_KEY in config.php.', 503);
}

// Tokens are billed by length, so an unbounded prompt is an unbounded bill.
// Generous enough for the longest thing the interface sends (a document summary
// from files.php?action=extract, capped at 12000 characters there).
const AI_MAX_PROMPT = 16000;
const AI_MAX_SYSTEM = 2000;

$data = body();
$prompt = trim((string) ($data['prompt'] ?? ''));
$system = trim((string) ($data['system'] ?? 'You are a helpful assistant for RitaGreen, a UK care, training, cleaning and security group. Be concise and use UK English.'));
if ($prompt === '') fail('A prompt is required.');
if (mb_strlen($prompt) > AI_MAX_PROMPT) fail('That prompt is too long to send.', 413);
if (mb_strlen($system) > AI_MAX_SYSTEM) $system = mb_substr($system, 0, AI_MAX_SYSTEM);

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
