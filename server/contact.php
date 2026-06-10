<?php
// Public enquiry endpoint for the corporate site contact form.
// POST /contact.php {name, email, phone, division, message} -> { ok: true }
require __DIR__ . '/lib.php';

if (method() !== 'POST') fail('Method not allowed.', 405);

$data = body();
$name = trim((string) ($data['name'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$phone = trim((string) ($data['phone'] ?? ''));
$division = trim((string) ($data['division'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    fail('Please add your name, email and a message.');
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('That email is not valid.');
if (mb_strlen($message) > 5000) fail('That message is too long.');

// Normalise the division to a known value, else store as "other".
$allowed = ['care', 'academy', 'cleaning', 'security', 'other'];
if (!in_array($division, $allowed, true)) $division = 'other';

$stmt = $conn->prepare(
    'INSERT INTO contact_enquiries (name, email, phone, division, message) VALUES (?, ?, ?, ?, ?)'
);
$stmt->bind_param('sssss', $name, $email, $phone, $division, $message);
$stmt->execute();

json_out(['ok' => true], 201);
