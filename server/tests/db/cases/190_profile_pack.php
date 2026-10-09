<?php
// Worker profile pack for a client (section S5, F21): the client-safe fields
// only, compliance zone and service scope required, every download audited.

/** @var RgtClient $client */
/** @var mysqli $db */

$db->query('DELETE FROM rate_limits');
$admin = $client->login('admin@local.invalid');
$scoped = $client->login('scoped@local.invalid');     // manager, security, scoped
$editor = $client->login('editor@local.invalid');     // no compliance zone

$one = static fn(string $sql): int => (int) ($db->query($sql)->fetch_row()[0] ?? 0);
$adminId = $one("SELECT id FROM users WHERE email = 'admin@local.invalid'");

$care = $db->query("SELECT w.person_id, w.email, w.phone FROM workers w WHERE w.name = 'Amara Chen'")->fetch_assoc();
$carePerson = (int) $care['person_id'];
$sec = $db->query("SELECT w.person_id, w.sia_number FROM workers w WHERE w.name = 'Dmitri Volkov'")->fetch_assoc();
$secPerson = (int) $sec['person_id'];

// Evidence with things on it that must never reach a client.
$db->query("UPDATE people SET address_line1 = '12 Secret Road', postcode = 'ZZ1 1ZZ' WHERE id = $carePerson");
$db->query("INSERT INTO verifications (person_id, requirement_key, subject_type, subject_label, status, verified_at, verified_by)
            VALUES ($carePerson, 'dbs', 'certification', 'Enhanced with barred list', 'verified', '2026-03-02 10:00:00', $adminId)");
$db->query("INSERT INTO right_to_work_checks (person_id, method, reference, checked_on, outcome, verified_at, verified_by)
            VALUES ($carePerson, 'online_share_code', 'SHARECODE-S5-XYZ', '2026-02-01', 'valid', '2026-02-01 09:00:00', $adminId)");

Rgt::group('profile pack: what a client is shown');

$auditBefore = $one("SELECT COUNT(*) FROM audit_log WHERE action = 'compliance.profile_pack' AND entity_id = $carePerson");
$res = $admin->get("/compliance.php?action=profile_pack&person_id=$carePerson");
Rgt::status('an admin builds the pack', $res, 200);
Rgt::same('it names the person', $res->get('person.name'), 'Amara Chen');
Rgt::same('with their service', $res->get('services'), ['care']);
Rgt::same('the DBS date a named person verified it', $res->get('dbs.checked_on'), '2026-03-02');
Rgt::same('and its level, as recorded', $res->get('dbs.level'), 'Enhanced with barred list');
Rgt::same('right to work: verified, and when', [$res->get('right_to_work.verified'), $res->get('right_to_work.verified_on')], [true, '2026-02-01']);
Rgt::same('no SIA section for a care worker', $res->get('sia'), null);
Rgt::ok('training is listed', is_array($res->get('training')));

Rgt::ok('no share code or document reference', !str_contains($res->body, 'SHARECODE-S5-XYZ'));
Rgt::ok('no address', !str_contains($res->body, 'Secret Road') && !str_contains($res->body, 'ZZ1 1ZZ'));
Rgt::ok('no email or phone', !str_contains($res->body, (string) $care['email']) && !str_contains($res->body, (string) $care['phone']));
Rgt::ok('no fields named for them either', !preg_match('/"(email|phone|address[a-z_0-9]*|postcode|date_of_birth|dob|ni_number|reference|method)"/', $res->body), $res->body);
Rgt::same('the download is audited', $one("SELECT COUNT(*) FROM audit_log WHERE action = 'compliance.profile_pack' AND entity_id = $carePerson"), $auditBefore + 1);

Rgt::group('profile pack: who may build one');

Rgt::denied('an editor (no compliance zone) cannot', $editor->get("/compliance.php?action=profile_pack&person_id=$carePerson"));
Rgt::denied('a security-scoped manager cannot build one for a care worker',
    $scoped->get("/compliance.php?action=profile_pack&person_id=$carePerson"));
$s = $scoped->get("/compliance.php?action=profile_pack&person_id=$secPerson");
Rgt::status('but can for a security worker', $s, 200);
Rgt::same('whose pack carries the SIA licence', $s->get('sia.licence_number'), $sec['sia_number']);
Rgt::same('and an unverified DBS is not presented as checked', [$s->get('dbs.verified'), $s->get('dbs.checked_on')], [false, null]);
Rgt::same('a refused request is not audited as a download',
    $one("SELECT COUNT(*) FROM audit_log WHERE action = 'compliance.profile_pack' AND entity_id = $carePerson"), $auditBefore + 1);

// Leave the fixture as it was.
$db->query("DELETE FROM right_to_work_checks WHERE reference = 'SHARECODE-S5-XYZ'");
$db->query("DELETE FROM verifications WHERE person_id = $carePerson AND subject_label = 'Enhanced with barred list'");
$db->query("UPDATE people SET address_line1 = NULL, postcode = NULL WHERE id = $carePerson");
