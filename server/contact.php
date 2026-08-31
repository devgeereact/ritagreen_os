<?php
// Public enquiry endpoint for the corporate site contact form.
// POST /contact.php {name, email, phone, division, message} -> { ok: true }
require __DIR__ . '/lib.php';

if (method() !== 'POST') fail('Method not allowed.', 405);

// Throttle spam / mail-relay abuse: max 5 submissions per IP per 10 minutes.
rate_limit($conn, 'contact', 5, 600);

$data = body();
// Strip CR/LF from single-line fields — they're interpolated into mail subjects
// below, so a newline would otherwise allow email-header injection.
$name = trim(str_replace(["\r", "\n"], ' ', (string) ($data['name'] ?? '')));
$email = trim((string) ($data['email'] ?? ''));
$phone = trim(str_replace(["\r", "\n"], ' ', (string) ($data['phone'] ?? '')));
$division = trim((string) ($data['division'] ?? ''));
$message = trim((string) ($data['message'] ?? ''));

if ($name === '' || $email === '' || $message === '') {
    fail('Please add your name, email and a message.');
}
if (mb_strlen($name) > 160) fail('That name is too long.');
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

// Notify the office and confirm to the sender.
//
// Queued unconditionally, NOT gated on mail_enabled(). The queue is the record
// of what the system intended to send; the drainer decides what can actually go
// out and records "skipped" when mail is off. Gating here instead would mean a
// disabled mailer leaves no trace that anybody should have been contacted.
//
// Queueing also means a mail failure can no longer turn a successful submission
// into an error: the lead is already saved and the request is already over.
{
    $isCareers = strpos($message, 'CAREERS APPLICATION') === 0;
    $kind = $isCareers ? 'careers application' : 'enquiry';
    $phoneLine = $phone !== '' ? $phone : '—';

    // 1) Office alert — reply goes straight to the sender.
    $officeSubject = $isCareers
        ? "New careers application — $name"
        : "New website enquiry ($division) — $name";
    $officeBody = "A new $kind came in from the RitaGreen website.\n\n"
        . "Name:     $name\n"
        . "Email:    $email\n"
        . "Phone:    $phoneLine\n"
        . "About:    $division\n\n"
        . "Message:\n$message\n\n"
        . "— Reply to this email to respond to $name directly.";
    queue_mail($conn, MAIL_TO, $officeSubject, $officeBody, $email);

    // 2) Autoresponder to the sender — reply goes to the office.
    $senderSubject = $isCareers
        ? 'Thanks for your application to RitaGreen Academy'
        : 'Thanks for contacting RitaGreen Services';
    $senderBody = "Hi $name,\n\n"
        . ($isCareers
            ? "Thanks for applying to train with RitaGreen Academy. We've received your "
              . "application and will be in touch about the next intake, costs and what happens next.\n\n"
            : "Thanks for getting in touch. We've received your enquiry and a named contact "
              . "will reply shortly — usually the same working day.\n\n")
        . "If it's urgent, call us and we'll get the right people ready.\n\n"
        . "— RitaGreen Services\nQualified. Checked. Ready.";
    queue_mail($conn, $email, $senderSubject, $senderBody, MAIL_TO);
}

json_out(['ok' => true], 201);
