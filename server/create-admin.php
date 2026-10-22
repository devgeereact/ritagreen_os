<?php
// Create the first super_admin, or add another one.
//
//     php server/create-admin.php --email you@example.com --name "Your Name"
//
// The password is prompted for, never passed as an argument: an argument shows
// up in `ps`, in shell history and in any command log, and a first-administrator
// credential is the last thing that should be in any of them.
//
// This exists because schema.sql used to seed an administrator whose password
// was written in a comment in the tracked file. The account is now created
// deliberately, once, by whoever owns it.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("create-admin.php runs from the command line only.\n");
}

require __DIR__ . '/lib.php';

$args = array_slice($argv, 1);
function arg_value(array $args, string $flag): ?string
{
    $i = array_search($flag, $args, true);
    return ($i === false || !isset($args[$i + 1])) ? null : (string) $args[$i + 1];
}

$email = trim((string) (arg_value($args, '--email') ?? ''));
$name = trim((string) (arg_value($args, '--name') ?? ''));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("Usage: php server/create-admin.php --email you@example.com --name \"Your Name\"\n");
}
if ($name === '') $name = $email;

$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
$stmt->bind_param('s', $email);
$stmt->execute();
if ($stmt->get_result()->fetch_assoc()) {
    exit("REFUSED: a user with that email already exists. Reset its password instead.\n");
}

echo "Creating a super_admin on:\n";
echo "  host     " . DB_HOST . "\n";
echo "  database " . DB_NAME . "\n";
echo "  email    " . $email . "\n\n";

// Read the password without echoing it, where the terminal allows it.
function prompt_secret(string $label): string
{
    echo $label;
    $quiet = @shell_exec('stty -g 2>/dev/null');
    if ($quiet !== null && trim((string) $quiet) !== '') {
        @shell_exec('stty -echo');
        $value = (string) fgets(STDIN);
        @shell_exec('stty ' . trim((string) $quiet));
        echo "\n";
    } else {
        // No tty control available. Say so rather than echoing silently.
        echo "\n  (this terminal cannot hide input -- the password will be visible)\n";
        $value = (string) fgets(STDIN);
    }
    return rtrim($value, "\r\n");
}

$password = prompt_secret('Password: ');
$again = prompt_secret('Confirm:  ');

if ($password !== $again) exit("REFUSED: the two passwords did not match.\n");
if (strlen($password) < 12) exit("REFUSED: use at least 12 characters.\n");

$hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
$role = 'super_admin';
// users.person_id is NOT NULL since migration 004, so the account needs a person.
// It goes through find_or_create_person() like every other identity, which also
// links the login to an existing person with the same email rather than copying it.
$personId = find_or_create_person($conn, $name, $email);
$stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, role, person_id) VALUES (?, ?, ?, ?, ?)');
$stmt->bind_param('ssssi', $name, $email, $hash, $role, $personId);
$stmt->execute();
$id = (int) $conn->insert_id;

audit($conn, $id, 'user.create', "super_admin bootstrap: $email");

echo "\nCreated super_admin #$id.\n";
echo "Sign in, then enrol two-factor authentication before doing anything else.\n";
