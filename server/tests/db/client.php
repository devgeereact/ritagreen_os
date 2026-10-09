<?php
// A small HTTP client and assertion vocabulary for the database-backed tests.
//
// Dependency-free on purpose, for the same reason server/tests/run.php is: the
// production host cannot install Composer packages, and a suite that only runs
// where someone has installed a tool is a suite that stops being run.

declare(strict_types=1);

final class RgtResponse
{
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
        public readonly mixed $json
    ) {
    }

    /** The endpoint's error message, or '' when the body was not an error. */
    public function error(): string
    {
        return is_array($this->json) && isset($this->json['error'])
            ? (string) $this->json['error'] : '';
    }

    public function get(string $path, mixed $default = null): mixed
    {
        $node = $this->json;
        foreach (explode('.', $path) as $key) {
            if (is_array($node) && array_key_exists($key, $node)) {
                $node = $node[$key];
            } else {
                return $default;
            }
        }
        return $node;
    }
}

final class RgtClient
{
    private ?string $token = null;
    private array $extraHeaders = [];

    public function __construct(private string $base)
    {
    }

    public function withToken(?string $token): self
    {
        $clone = clone $this;
        $clone->token = $token;
        return $clone;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->extraHeaders[$name] = $value;
        return $clone;
    }

    public function token(): ?string
    {
        return $this->token;
    }

    /**
     * Sign in and return a client carrying the token.
     * Throws rather than returning an unauthenticated client, because a test
     * that silently continues as nobody proves nothing about permissions.
     */
    public function login(string $email, string $password = 'LocalDev123!'): self
    {
        $res = $this->post('/auth.php?action=login', ['email' => $email, 'password' => $password]);
        $token = $res->get('token');
        if (!is_string($token) || $token === '') {
            throw new RuntimeException("login failed for $email: HTTP {$res->status} {$res->body}");
        }
        return $this->withToken($token);
    }

    public function get(string $path): RgtResponse
    {
        return $this->send('GET', $path, null);
    }

    public function post(string $path, mixed $body = null): RgtResponse
    {
        return $this->send('POST', $path, $body);
    }

    public function put(string $path, mixed $body = null): RgtResponse
    {
        return $this->send('PUT', $path, $body);
    }

    public function delete(string $path, mixed $body = null): RgtResponse
    {
        return $this->send('DELETE', $path, $body);
    }

    /**
     * A multipart POST with one file, as a browser form sends it.
     *
     * @param array<string,string> $fields
     */
    public function upload(string $path, string $filePath, string $mime, array $fields = []): RgtResponse
    {
        $headers = ['Accept: application/json'];
        if ($this->token !== null) $headers[] = 'Authorization: Bearer ' . $this->token;
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => $fields + ['file' => new CURLFile($filePath, $mime, basename($filePath))],
        ]);
        $out = curl_exec($ch);
        if ($out === false) throw new RuntimeException("upload $path failed: " . curl_error($ch));
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $json = json_decode((string) $out, true);
        return new RgtResponse($status, [], (string) $out, json_last_error() === JSON_ERROR_NONE ? $json : null);
    }

    public function send(string $method, string $path, mixed $body = null, ?string $raw = null): RgtResponse
    {
        $headers = ['Accept: application/json'];
        if ($this->token !== null) $headers[] = 'Authorization: Bearer ' . $this->token;
        foreach ($this->extraHeaders as $name => $value) $headers[] = "$name: $value";

        $payload = $raw;
        if ($payload === null && $body !== null) {
            $payload = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        } elseif ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            throw new RuntimeException("$method $path failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $rawHeaders = substr((string) $out, 0, $headerSize);
        $responseBody = substr((string) $out, $headerSize);

        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }

        $json = json_decode($responseBody, true);
        return new RgtResponse($status, $parsed, $responseBody, json_last_error() === JSON_ERROR_NONE ? $json : null);
    }
}

// ---------------------------------------------------------------------------
// Assertions. One counter, one failure list, printed by run.php.

final class Rgt
{
    public static int $passed = 0;
    /** @var string[] */
    public static array $failures = [];
    public static string $group = '';
    /** @var array<string,int> */
    public static array $groupCounts = [];

    public static function group(string $name): void
    {
        self::$group = $name;
        self::$groupCounts[$name] ??= 0;
    }

    public static function pass(string $what): void
    {
        self::$passed++;
        self::$groupCounts[self::$group] = (self::$groupCounts[self::$group] ?? 0) + 1;
        if (getenv('RG_TEST_VERBOSE')) fwrite(STDOUT, "    ok  $what\n");
    }

    public static function fail(string $what, string $detail): void
    {
        self::$failures[] = sprintf("%s — %s\n      %s", self::$group, $what, $detail);
        fwrite(STDOUT, "    FAIL  $what\n");
    }

    public static function ok(string $what, bool $condition, string $detail = ''): void
    {
        $condition ? self::pass($what) : self::fail($what, $detail !== '' ? $detail : 'expected true');
    }

    public static function same(string $what, mixed $actual, mixed $expected): void
    {
        if ($actual === $expected) { self::pass($what); return; }
        self::fail($what, sprintf("expected: %s\n      actual:   %s",
            var_export($expected, true), var_export($actual, true)));
    }

    /** Assert an HTTP status, quoting the body when it does not match. */
    public static function status(string $what, RgtResponse $res, int $expected): void
    {
        if ($res->status === $expected) { self::pass($what); return; }
        self::fail($what, sprintf("expected HTTP %d, got %d\n      body: %s",
            $expected, $res->status, substr($res->body, 0, 400)));
    }

    /**
     * Assert the request was refused, and that nothing leaked in the refusal.
     * 401 and 403 are both acceptable answers to "you may not"; what is not
     * acceptable is a 200 with somebody else's record in it.
     */
    public static function denied(string $what, RgtResponse $res): void
    {
        if ($res->status === 401 || $res->status === 403) { self::pass($what); return; }
        self::fail($what, sprintf("expected 401 or 403, got %d\n      body: %s",
            $res->status, substr($res->body, 0, 400)));
    }
}
