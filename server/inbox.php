<?php
// In-app email inbox, backed by the mailbox over IMAP.
//
//   GET  inbox.php?action=list&limit=30        -> recent messages (newest first)
//   GET  inbox.php?action=message&uid=123       -> full message body (marks \Seen)
//   POST inbox.php?action=reply                  -> send a reply via SMTP
//        body: { to, subject, message, in_reply_to?, uid? }
//   POST inbox.php?action=seen&uid=123           -> mark read   (action=unseen to undo)
//
// Reads live from IMAP, sends through send_mail() (SMTP). Staff only.
require __DIR__ . '/lib.php';

$user = require_auth($conn);

if (!function_exists('imap_open')) {
    fail('The PHP imap extension is not installed on the server.', 503);
}
if (!defined('IMAP_HOST') || IMAP_HOST === '') {
    fail('The inbox is not configured. Set IMAP_HOST in config.php.', 503);
}

$port = defined('IMAP_PORT') ? (int) IMAP_PORT : 993;
$ref = '{' . IMAP_HOST . ':' . $port . '/imap/ssl/novalidate-cert}';

function open_inbox(string $ref)
{
    $imap = @imap_open($ref . 'INBOX', IMAP_USER, IMAP_PASS, 0, 1);
    if (!$imap) fail('Could not connect to the mailbox: ' . imap_last_error(), 502);
    return $imap;
}

// Pull the bare address out of a "Name <a@b.com>" header value.
function address_of(string $header): string
{
    if (preg_match('/<([^>]+)>/', $header, $m)) return trim($m[1]);
    if (preg_match('/[\w.+-]+@[\w.-]+\.\w+/', $header, $m)) return trim($m[0]);
    return '';
}

// Walk the MIME structure and return the best plain-text body we can find.
function message_text($imap, int $uid): string
{
    $structure = imap_fetchstructure($imap, $uid, FT_UID);
    if (!$structure) return '';

    // Simple, single-part message.
    if (empty($structure->parts)) {
        $raw = imap_fetchbody($imap, $uid, '1', FT_UID);
        return decode_part($raw, $structure->encoding ?? 0, sub_of($structure));
    }

    // Multipart: prefer text/plain, fall back to text/html (tags stripped).
    $plain = find_part($imap, $uid, $structure->parts, 'PLAIN', '');
    if ($plain !== '') return $plain;
    $html = find_part($imap, $uid, $structure->parts, 'HTML', '');
    if ($html !== '') return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    return '';
}

function sub_of($part): string
{
    return isset($part->subtype) ? strtoupper($part->subtype) : '';
}

function find_part($imap, int $uid, array $parts, string $want, string $prefix): string
{
    foreach ($parts as $i => $part) {
        $section = ($prefix === '' ? '' : $prefix . '.') . ($i + 1);
        if (!empty($part->parts)) {
            $nested = find_part($imap, $uid, $part->parts, $want, $section);
            if ($nested !== '') return $nested;
        } elseif (sub_of($part) === $want) {
            $raw = imap_fetchbody($imap, $uid, $section, FT_UID);
            return decode_part($raw, $part->encoding ?? 0, $want);
        }
    }
    return '';
}

// IMAP encoding constants: 3 = BASE64, 4 = QUOTED-PRINTABLE.
function decode_part(string $raw, int $encoding, string $subtype): string
{
    if ($encoding === 3) $raw = base64_decode($raw);
    elseif ($encoding === 4) $raw = quoted_printable_decode($raw);
    return trim((string) imap_utf8($raw));
}

$action = $_GET['action'] ?? 'list';
$verb = method();

// ---- List recent messages ----
if ($verb === 'GET' && $action === 'list') {
    $limit = min(100, max(1, (int) ($_GET['limit'] ?? 30)));
    $imap = open_inbox($ref);
    $uids = imap_sort($imap, SORTDATE, 1, SE_UID) ?: [];
    $uids = array_slice($uids, 0, $limit);

    $messages = [];
    foreach ($uids as $uid) {
        $ov = imap_fetch_overview($imap, (string) $uid, FT_UID);
        if (!$ov) continue;
        $o = $ov[0];
        $from = isset($o->from) ? imap_utf8($o->from) : '';
        $messages[] = [
            'uid' => (int) $uid,
            'subject' => isset($o->subject) && $o->subject !== '' ? imap_utf8($o->subject) : '(no subject)',
            'from' => $from,
            'from_email' => address_of($from),
            'date' => isset($o->date) ? date('c', strtotime($o->date)) : null,
            'seen' => !empty($o->seen),
            'answered' => !empty($o->answered),
        ];
    }
    imap_close($imap);
    json_out(['messages' => $messages]);
}

// ---- Read one message (marks it seen) ----
if ($verb === 'GET' && $action === 'message') {
    $uid = (int) ($_GET['uid'] ?? 0);
    if (!$uid) fail('A message uid is required.');
    $imap = open_inbox($ref);
    $ov = imap_fetch_overview($imap, (string) $uid, FT_UID);
    if (!$ov) { imap_close($imap); fail('Message not found.', 404); }
    $o = $ov[0];
    $from = isset($o->from) ? imap_utf8($o->from) : '';
    $body = message_text($imap, $uid);
    @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
    $message = [
        'uid' => $uid,
        'subject' => isset($o->subject) && $o->subject !== '' ? imap_utf8($o->subject) : '(no subject)',
        'from' => $from,
        'from_email' => address_of($from),
        'message_id' => $o->message_id ?? '',
        'date' => isset($o->date) ? date('c', strtotime($o->date)) : null,
        'body' => $body,
    ];
    imap_close($imap);
    json_out(['message' => $message]);
}

// ---- Mark seen / unseen ----
if ($verb === 'POST' && ($action === 'seen' || $action === 'unseen')) {
    $uid = (int) ($_GET['uid'] ?? 0);
    if (!$uid) fail('A message uid is required.');
    $imap = open_inbox($ref);
    if ($action === 'seen') @imap_setflag_full($imap, (string) $uid, '\\Seen', ST_UID);
    else @imap_clearflag_full($imap, (string) $uid, '\\Seen', ST_UID);
    imap_close($imap);
    json_out(['ok' => true]);
}

// ---- Reply via SMTP ----
if ($verb === 'POST' && $action === 'reply') {
    if (!mail_enabled()) fail('Outgoing mail is disabled. Set MAIL_ENABLED in config.php.', 503);
    $d = body();
    $to = trim((string) ($d['to'] ?? ''));
    $subject = trim((string) ($d['subject'] ?? ''));
    $message = trim((string) ($d['message'] ?? ''));
    $inReplyTo = trim((string) ($d['in_reply_to'] ?? ''));
    $uid = (int) ($d['uid'] ?? 0);

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) fail('A valid recipient address is required.');
    if ($message === '') fail('The reply cannot be empty.');
    if ($subject === '') $subject = '(no subject)';
    if (stripos($subject, 're:') !== 0) $subject = 'Re: ' . $subject;

    $extra = [];
    if ($inReplyTo !== '') {
        $extra[] = 'In-Reply-To: ' . $inReplyTo;
        $extra[] = 'References: ' . $inReplyTo;
    }

    $sent = send_mail($to, $subject, $message, null, $extra);
    if (!$sent) fail('The reply could not be sent. Check the SMTP settings in config.php.', 502);

    // Flag the original as answered so the list reflects it.
    if ($uid) {
        $imap = open_inbox($ref);
        @imap_setflag_full($imap, (string) $uid, '\\Answered', ST_UID);
        imap_close($imap);
    }
    audit($conn, (int) $user['id'], 'inbox_reply', $to);
    json_out(['ok' => true]);
}

fail('Unknown inbox action.', 404);
