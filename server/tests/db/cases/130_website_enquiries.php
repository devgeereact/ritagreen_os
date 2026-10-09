<?php
// The website's enquiry forms, and where each one lands.
//
// Request staff goes to bookings.php, a cleaning quote to quotes.php?action=request,
// and care, training and general enquiries to contact.php with a type. Each is
// public, so each is rate limited, capped and refused without what it needs, and
// none of them opens the staff side of the endpoint it shares.

/** @var RgtClient $client */
/** @var mysqli $db */
/** @var string $base */

$count = static fn (string $sql): int => (int) $db->query($sql)->fetch_row()[0];

Rgt::group('website — general, care and training enquiries go to contact.php');

$db->query('DELETE FROM rate_limits');
$res = $client->post('/contact.php', [
    'name' => 'General Sender', 'email' => 'general.sender@example.test',
    'message' => 'Do you cover Bromley?', 'type' => 'general',
]);
Rgt::status('a general enquiry is accepted', $res, 201);
$row = $db->query("SELECT division, message FROM contact_enquiries WHERE email = 'general.sender@example.test'")->fetch_assoc();
Rgt::same('filed as other, since it named no service', $row['division'] ?? null, 'other');
Rgt::same('and its message is stored as written', $row['message'] ?? null, 'Do you cover Bromley?');

$res = $client->post('/contact.php', [
    'name' => 'Care Sender', 'email' => 'care.sender@example.test', 'division' => 'security',
    'message' => "For: a family member\nNeed: support at home", 'type' => 'care',
]);
Rgt::status('a care enquiry is accepted', $res, 201);
$row = $db->query("SELECT division, message FROM contact_enquiries WHERE email = 'care.sender@example.test'")->fetch_assoc();
Rgt::same('a care enquiry is filed under Healthcare whatever the form said', $row['division'] ?? null, 'care');
Rgt::ok('and the message opens by naming the type, for the office screen',
    str_starts_with((string) ($row['message'] ?? ''), "Enquiry type: Care enquiry\n\n"), (string) ($row['message'] ?? ''));

$res = $client->post('/contact.php', [
    'name' => 'Training Sender', 'email' => 'training.sender@example.test',
    'message' => 'Course: Mandatory training. Group size: 6', 'type' => 'training',
]);
Rgt::status('a training enquiry is accepted', $res, 201);
$row = $db->query("SELECT division, message FROM contact_enquiries WHERE email = 'training.sender@example.test'")->fetch_assoc();
Rgt::same('a training enquiry is filed under Training', $row['division'] ?? null, 'academy');
Rgt::ok('and says so', str_starts_with((string) ($row['message'] ?? ''), 'Enquiry type: Training enquiry'), (string) ($row['message'] ?? ''));

$res = $client->post('/contact.php', [
    'name' => 'Odd Type', 'email' => 'odd.type@example.test', 'message' => 'Hello', 'type' => 'payroll',
]);
Rgt::status('an unknown type is accepted as a general enquiry', $res, 201);
$row = $db->query("SELECT division, message FROM contact_enquiries WHERE email = 'odd.type@example.test'")->fetch_assoc();
Rgt::same('with no type line added', $row['message'] ?? null, 'Hello');

Rgt::status('a care enquiry with no message is still refused',
    $client->post('/contact.php', ['name' => 'X', 'email' => 'x@example.test', 'message' => '', 'type' => 'care']), 400);

Rgt::group('website — request staff goes to bookings.php');

$db->query('DELETE FROM rate_limits');
$before = $count('SELECT COUNT(*) FROM service_requests');
$res = $client->post('/bookings.php', [
    'contact_name' => 'Rota Manager', 'email' => 'rota.manager@example.test', 'phone' => '020 7946 0000',
    'division' => 'care', 'site_name' => 'Oak House, SE13',
    'detail' => "Organisation: Oak House\nRole and number: 2 care assistants\nDates and times: Sat 08:00 to 20:00",
    'preferred_start' => '2026-11-07T08:00', 'preferred_end' => '2026-11-07T20:00', 'consent' => true,
]);
Rgt::status('a staff request is accepted', $res, 201);
Rgt::same('and lands in the bookings queue', $count('SELECT COUNT(*) FROM service_requests'), $before + 1);
$row = $db->query("SELECT division, status, preferred_start, preferred_end FROM service_requests WHERE email = 'rota.manager@example.test'")->fetch_assoc();
Rgt::same('as a new Healthcare request', [$row['division'] ?? null, $row['status'] ?? null], ['care', 'new']);
Rgt::same('with the shift times it was given', [$row['preferred_start'] ?? null, $row['preferred_end'] ?? null],
    ['2026-11-07 08:00:00', '2026-11-07 20:00:00']);
Rgt::status('a staff request without consent is refused', $client->post('/bookings.php', [
    'contact_name' => 'No Consent', 'email' => 'no.consent@example.test', 'division' => 'care', 'detail' => 'Cover',
]), 400);

Rgt::group('website — a cleaning quote goes to quotes.php, not the enquiry inbox');

$db->query('DELETE FROM rate_limits');
$quote = [
    'contact_name' => 'Block Manager', 'email' => 'block.manager@example.test', 'phone' => '07700 900123',
    'organisation' => 'Riverside Lettings', 'area' => 'SE8', 'site_type' => 'residential',
    'size' => 'Three blocks, 40 flats', 'frequency' => 'weekly', 'start_date' => '2026-12-01',
    'notes' => 'Communal stairs and bin stores.', 'consent' => true,
];
$quotesBefore = $count('SELECT COUNT(*) FROM quotes');
$enquiriesBefore = $count('SELECT COUNT(*) FROM contact_enquiries');
$res = $client->post('/quotes.php?action=request', $quote);
Rgt::status('a quote request needs no login', $res, 201);
$row = $db->query("SELECT division, site_name, detail, status, preferred_start FROM service_requests WHERE email = 'block.manager@example.test'")->fetch_assoc();
Rgt::same('it is a new Cleaning request in the bookings queue', [$row['division'] ?? null, $row['status'] ?? null], ['cleaning', 'new']);
Rgt::same('named for the organisation', $row['site_name'] ?? null, 'Riverside Lettings');
$detail = (string) ($row['detail'] ?? '');
Rgt::ok('its detail says what it is', str_starts_with($detail, "Cleaning quote request\n"), $detail);
Rgt::ok('and reads as words, not keys', str_contains($detail, 'Site type: Residential block or communal areas')
    && str_contains($detail, 'Frequency: Weekly') && str_contains($detail, 'Start: 2026-12-01'), $detail);
Rgt::ok('a start date is not a shift time, so no preferred start is invented',
    is_array($row) && array_key_exists('preferred_start', $row) && $row['preferred_start'] === null);
Rgt::same('no priced quote is created from it', $count('SELECT COUNT(*) FROM quotes'), $quotesBefore);
Rgt::same('and it does not land in the general enquiry inbox', $count('SELECT COUNT(*) FROM contact_enquiries'), $enquiriesBefore);
Rgt::same('the consent to be contacted is recorded',
    $count("SELECT COUNT(*) FROM consent_log WHERE email = 'block.manager@example.test' AND kind = 'quote' AND granted = 1"), 1);
Rgt::same('marketing is not recorded when the box was not ticked',
    $count("SELECT COUNT(*) FROM consent_log WHERE email = 'block.manager@example.test' AND kind = 'marketing'"), 0);

$res = $client->post('/quotes.php?action=request',
    ['email' => 'keen.buyer@example.test', 'contact_name' => 'Keen Buyer', 'marketing_consent' => true] + $quote);
Rgt::status('a request with the marketing box ticked is accepted', $res, 201);
Rgt::same('and only then is marketing recorded, as a yes',
    $count("SELECT COUNT(*) FROM consent_log WHERE email = 'keen.buyer@example.test' AND kind = 'marketing' AND granted = 1"), 1);

$db->query('DELETE FROM rate_limits');
Rgt::status('without consent it is refused', $client->post('/quotes.php?action=request', ['consent' => false] + $quote), 400);
Rgt::status('an unknown site type is refused', $client->post('/quotes.php?action=request', ['site_type' => 'castle'] + $quote), 400);
Rgt::status('an unknown frequency is refused', $client->post('/quotes.php?action=request', ['frequency' => 'hourly'] + $quote), 400);
Rgt::status('a start date that is not a date is refused',
    $client->post('/quotes.php?action=request', ['start_date' => '2026-02-30'] + $quote), 400);
$db->query('DELETE FROM rate_limits');
Rgt::status('a bad email is refused', $client->post('/quotes.php?action=request', ['email' => 'not-an-email'] + $quote), 400);
Rgt::status('oversized notes are refused with a message, not a 500',
    $client->post('/quotes.php?action=request', ['notes' => str_repeat('a', 3001)] + $quote), 400);
Rgt::status('an oversized organisation is refused', $client->post('/quotes.php?action=request', ['organisation' => str_repeat('o', 161)] + $quote), 400);

Rgt::group('website — the quote request is rate limited and opens nothing else');

$db->query('DELETE FROM rate_limits');
$statuses = [];
for ($i = 1; $i <= 6; $i++) {
    $statuses[] = $client->post('/quotes.php?action=request', ['email' => "burst$i@example.test"] + $quote)->status;
}
Rgt::same('five requests in ten minutes are accepted and the sixth is refused', $statuses, [201, 201, 201, 201, 201, 429]);
$db->query('DELETE FROM rate_limits');

Rgt::denied('the quote list still needs a login', $client->get('/quotes.php'));
Rgt::denied('creating a priced quote still needs a login',
    $client->post('/quotes.php', ['title' => 'Free money', 'division' => 'cleaning', 'net_pence' => 100]));
Rgt::denied('accepting a quote still needs a login', $client->post('/quotes.php?action=accept&id=1'));
$worker = $client->login('amara.login@local.invalid');
Rgt::denied('and a worker cannot read the quote list either', $worker->get('/quotes.php'));
