<?php
// Retirements (section S9): the AI proxy, the weather proxy and the Analytics
// endpoint were deleted, not hidden. Each answers 404 to any account, and the
// files endpoint no longer extracts text for an AI summary.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');

Rgt::group('retirements: the endpoints are gone');

foreach (['/ai.php', '/weather.php', '/analytics.php'] as $path) {
    Rgt::status("$path is not served, even to an admin", $admin->get($path), 404);
}
Rgt::status('nor does a POST to ai.php reach anything', $admin->post('/ai.php', ['prompt' => 'Say hello.']), 404);

Rgt::group('retirements: files no longer extracts text for AI');

$res = $admin->get('/files.php?action=extract&id=1');
Rgt::status('the extract action falls through to the plain list', $res, 200);
Rgt::same('which carries no extracted text', $res->get('text'), null);
Rgt::ok('and is the file list', is_array($res->get('files')), $res->body);
