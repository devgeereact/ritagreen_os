<?php
// Unsubscribe from marketing email.
//
//   GET  /unsubscribe.php?e=<email>&t=<token>   -> a confirmation page
//   POST /unsubscribe.php?e=<email>&t=<token>   -> records the opt-out
//
// No login, because the person clicking has an email client and nothing else.
// The token is an HMAC over the address (see rg_unsubscribe_token), so the link
// cannot be guessed for somebody else and no table of tokens has to be kept.
//
// GET only shows a page; the opt-out happens on POST. Mail scanners and link
// previewers fetch every URL in a message, and an unsubscribe that fires on GET
// would quietly drop people who never clicked anything.
//
// This returns HTML rather than JSON. It is the one endpoint a person reads
// directly in a browser, and "{"ok":true}" is not an answer to somebody who
// wants to know whether they have been taken off the list.
require __DIR__ . '/lib.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');

// A wrong or stale link should not be a way to find out whether an address is
// on the list, so every failure reads the same and nothing confirms an address.
function page(string $heading, string $message, ?string $formEmail = null, ?string $formToken = null): never
{
    $h = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
    $form = '';
    if ($formEmail !== null && $formToken !== null) {
        $e = htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($formToken, ENT_QUOTES, 'UTF-8');
        $form = '<form method="post" action="?e=' . rawurlencode($formEmail) . '&amp;t=' . $t . '">'
            . '<p class="who">' . $e . '</p>'
            . '<button type="submit">Unsubscribe me</button>'
            . '</form>';
    }
    echo <<<HTML
<!doctype html>
<html lang="en-GB">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>$h</title>
<style>
  :root { color-scheme: light; }
  body { margin: 0; min-height: 100vh; display: grid; place-items: center;
         background: #f7f9fc; color: #10233d;
         font: 16px/1.6 "Source Sans 3", system-ui, -apple-system, sans-serif; }
  main { max-width: 34rem; padding: 2.5rem; margin: 1rem; background: #fff;
         border: 1px solid #dbe5f1; border-radius: 1rem; }
  h1 { margin: 0 0 .75rem; font-size: 1.4rem; }
  p { margin: 0 0 1rem; color: #46586f; }
  .who { font-weight: 600; color: #10233d; }
  button { font: inherit; font-weight: 600; cursor: pointer; color: #fff;
           background: #0c447c; border: 0; border-radius: .7rem; padding: .7rem 1.2rem; }
  button:hover { opacity: .9; }
  button:focus-visible { outline: 3px solid #5dcaa5; outline-offset: 2px; }
  a { color: #0c447c; }
</style>
</head>
<body>
  <main>
    <h1>$h</h1>
    <p>$m</p>
    $form
    <p><a href="/">Back to ritagreenservices.com</a></p>
  </main>
</body>
</html>
HTML;
    exit;
}

$secret = app_secret();
if ($secret === '') {
    // Configuration is missing, not the visitor's fault, and saying so plainly
    // beats a page that claims to have done something it has not.
    http_response_code(503);
    page('Unsubscribe is unavailable', 'This link cannot be processed right now. Please email us and we will remove you by hand.');
}

$email = trim((string) ($_GET['e'] ?? ''));
$token = trim((string) ($_GET['t'] ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !rg_unsubscribe_valid($email, $token, $secret)) {
    http_response_code(400);
    page('This link is not valid', 'It may have been truncated by an email client, or it may be from an old message. Email us and we will take care of it.');
}

if (method() === 'POST') {
    rate_limit($conn, 'unsubscribe', 20, 600);
    record_marketing_consent($conn, $email, false, 'unsubscribe-link');
    audit($conn, null, 'marketing.unsubscribe', mb_strtolower($email));
    page(
        'You have been unsubscribed',
        'We have recorded that you no longer want marketing email from us. '
        . 'You may still get messages about work we are doing for you, a booking you have made, '
        . 'or an application you have sent us — those are not marketing, and you need them.'
    );
}

page(
    'Unsubscribe from marketing email',
    'Confirm and we will stop sending you marketing. Messages about a booking, '
    . 'a shift or an application are separate and will still reach you.',
    $email,
    $token
);
