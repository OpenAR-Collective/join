<?php
/**
 * Mint a short-lived GitHub App installation token.
 *
 * The OpenAR-Collective org disables deploy keys by policy, which is the right
 * call: a deploy key cannot be rotated, attributed, or revoked centrally, and it
 * lives forever on whatever box holds it. A GitHub App is better on every one of
 * those counts. The token this prints is scoped to one repository, carries only
 * the permissions the App was granted, and expires in an hour.
 *
 * Plain PHP on purpose. Signing RS256 needs nothing beyond ext-openssl, which is
 * already here, so there is no pip, no venv, and no dependency to keep patched.
 *
 *   php github-token.php
 *
 * Configuration comes from the environment or from
 * /home/rob/.config/openar/github-app.env:
 *
 *   OPENAR_GH_APP_ID       the App ID from the App's settings page
 *   OPENAR_GH_KEY_FILE     path to the .pem private key, mode 600
 *   OPENAR_GH_REPO         owner/name, defaults to OpenAR-Collective/website
 *
 * Prints the token on stdout and nothing else, so callers can capture it.
 * Every diagnostic goes to stderr.
 */

const DEFAULT_REPO = 'OpenAR-Collective/website';
const ENV_FILE = '/home/rob/.config/openar/github-app.env';
const UA = 'openar-roster-sync';

function fail(string $message): never {
  fwrite(STDERR, "github-token: {$message}\n");
  exit(1);
}

/** Read KEY=value lines from the env file, without overriding the real environment. */
function load_env(): void {
  if (!is_readable(ENV_FILE)) {
    return;
  }
  foreach (file(ENV_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
      continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v, " \t\"'");
    if (getenv($k) === FALSE) {
      putenv("{$k}={$v}");
    }
  }
}

function b64url(string $raw): string {
  return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

function api(string $url, string $jwt, string $method = 'GET'): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => TRUE,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $jwt,
      'Accept: application/vnd.github+json',
      'X-GitHub-Api-Version: 2022-11-28',
      'User-Agent: ' . UA,
    ],
  ]);
  $body = curl_exec($ch);
  if ($body === FALSE) {
    fail('could not reach api.github.com: ' . curl_error($ch));
  }
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $decoded = json_decode((string) $body, TRUE);
  if ($code < 200 || $code >= 300) {
    $msg = $decoded['message'] ?? substr((string) $body, 0, 200);
    fail("GitHub returned HTTP {$code} for {$url}: {$msg}");
  }
  return is_array($decoded) ? $decoded : [];
}

load_env();

$appId = getenv('OPENAR_GH_APP_ID') ?: '';
$keyFile = getenv('OPENAR_GH_KEY_FILE') ?: '';
$repo = getenv('OPENAR_GH_REPO') ?: DEFAULT_REPO;

if ($appId === '') {
  fail('OPENAR_GH_APP_ID is not set (see ' . ENV_FILE . ')');
}
if ($keyFile === '' || !is_readable($keyFile)) {
  fail("cannot read the private key at '{$keyFile}'");
}

$perms = fileperms($keyFile) & 0777;
if ($perms & 0077) {
  fwrite(STDERR, sprintf("github-token: warning, %s is mode %04o; 0600 would be better\n", $keyFile, $perms));
}

$pem = file_get_contents($keyFile);
$key = openssl_pkey_get_private($pem);
if ($key === FALSE) {
  fail("the file at '{$keyFile}' is not a usable private key");
}

// A GitHub App JWT is valid for at most ten minutes. Backdating by a minute
// absorbs any clock skew between here and GitHub.
$now = time();
$header = b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
$claims = b64url(json_encode(['iat' => $now - 60, 'exp' => $now + 540, 'iss' => $appId]));

$signature = '';
if (!openssl_sign("{$header}.{$claims}", $signature, $key, OPENSSL_ALGO_SHA256)) {
  fail('could not sign the JWT');
}
$jwt = "{$header}.{$claims}." . b64url($signature);

// Find the installation covering this repository, rather than making anyone
// hunt for an installation id in a URL.
[$owner, $name] = array_pad(explode('/', $repo, 2), 2, '');
if ($owner === '' || $name === '') {
  fail("OPENAR_GH_REPO should look like owner/name, got '{$repo}'");
}

$installation = api("https://api.github.com/repos/{$owner}/{$name}/installation", $jwt);
$installationId = $installation['id'] ?? NULL;
if (!$installationId) {
  fail("the App is not installed on {$repo}");
}

$token = api("https://api.github.com/app/installations/{$installationId}/access_tokens", $jwt, 'POST');
if (empty($token['token'])) {
  fail('GitHub did not return a token');
}

fwrite(STDERR, "github-token: installation {$installationId}, expires {$token['expires_at']}\n");
echo $token['token'];
