<?php

declare(strict_types=1);

const DATASET_COLUMNS = ['filename', 'label', 'batch_id', 'verified', 'hash'];
const MAX_PANEL_RESPONSE_BYTES = 2_000_000;
const CHALLENGE_MAX_AGE = 240;

final class CollectorError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}

final class PanelResponse
{
    /**
     * @param array<string, list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];
        return $values[0] ?? null;
    }

    /** @return list<string> */
    public function headers(string $name): array
    {
        return $this->headers[strtolower($name)] ?? [];
    }
}

/**
 * @return array<string, string>
 */
function readProjectEnvironment(string $path): array
{
    if (!file_exists($path)) {
        return [];
    }
    if (!is_file($path) || !is_readable($path)) {
        throw new CollectorError('The project .env file is not readable.', 500);
    }

    $contents = @file_get_contents($path);
    if ($contents === false) {
        throw new CollectorError('Could not read the project .env file.', 500);
    }
    if (strlen($contents) > 65_536) {
        throw new CollectorError('The project .env file is unexpectedly large.', 500);
    }

    $supported = array_fill_keys([
        'PANEL_BASE_URL',
        'PANEL_LOGIN_USERNAME',
        'PANEL_LOGIN_PASSWORD',
        'PANEL_REQUEST_TIMEOUT',
        'OCR_DATASET_DIR',
    ], true);
    $values = [];
    $lines = preg_split('/\R/', $contents);
    if ($lines === false) {
        throw new CollectorError('Could not parse the project .env file.', 500);
    }

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $line = preg_replace('/^\xEF\xBB\xBF/', '', $line) ?? $line;
        }
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        if (
            preg_match(
                '/^(?:export\s+)?([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/D',
                $trimmed,
                $matches,
            ) !== 1
        ) {
            throw new CollectorError(
                'Invalid project .env syntax on line ' . ($index + 1) . '.',
                500,
            );
        }

        $name = $matches[1];
        if (!isset($supported[$name])) {
            continue;
        }
        $values[$name] = parseProjectEnvironmentValue(
            $matches[2],
            $index + 1,
        );
    }

    return $values;
}

function parseProjectEnvironmentValue(string $raw, int $line): string
{
    $raw = ltrim($raw);
    if ($raw === '') {
        return '';
    }

    $quote = $raw[0];
    if ($quote !== '"' && $quote !== "'") {
        return trim(preg_replace('/\s+#.*$/', '', $raw) ?? $raw);
    }

    $value = '';
    $escaped = false;
    $length = strlen($raw);
    for ($index = 1; $index < $length; $index++) {
        $character = $raw[$index];
        if ($quote === '"' && $escaped) {
            $value .= match ($character) {
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                '"', '\\', '$' => $character,
                default => '\\' . $character,
            };
            $escaped = false;
            continue;
        }
        if ($quote === '"' && $character === '\\') {
            $escaped = true;
            continue;
        }
        if ($character !== $quote) {
            $value .= $character;
            continue;
        }

        $remainder = trim(substr($raw, $index + 1));
        if ($remainder !== '' && !str_starts_with($remainder, '#')) {
            throw new CollectorError(
                "Invalid project .env value on line {$line}.",
                500,
            );
        }
        return $value;
    }

    throw new CollectorError(
        "Unclosed quote in project .env on line {$line}.",
        500,
    );
}

function collectorSetting(
    string $name,
    array $projectEnvironment,
    string $default = '',
): string {
    $serverValue = $_SERVER[$name] ?? null;
    if (is_string($serverValue) && $serverValue !== '') {
        return $serverValue;
    }

    $processValue = getenv($name);
    if ($processValue !== false && $processValue !== '') {
        return $processValue;
    }

    $projectValue = $projectEnvironment[$name] ?? '';
    return $projectValue !== '' ? $projectValue : $default;
}

function collectorConfig(?string $projectEnvPath = null): array
{
    $projectEnvironment = readProjectEnvironment(
        $projectEnvPath ?? dirname(__DIR__) . '/.env',
    );
    $configuredDataset = collectorSetting(
        'OCR_DATASET_DIR',
        $projectEnvironment,
    );
    $timeoutValue = collectorSetting(
        'PANEL_REQUEST_TIMEOUT',
        $projectEnvironment,
        '10',
    );
    $timeout = filter_var($timeoutValue, FILTER_VALIDATE_INT);

    return [
        'dataset' => rtrim(
            $configuredDataset !== '' ? $configuredDataset : dirname(__DIR__) . '/dataset',
            DIRECTORY_SEPARATOR,
        ),
        'panel_base_url' => rtrim(
            collectorSetting(
                'PANEL_BASE_URL',
                $projectEnvironment,
                'https://internet.tci.ir',
            ),
            '/',
        ),
        'panel_username' => collectorSetting(
            'PANEL_LOGIN_USERNAME',
            $projectEnvironment,
            'username',
        ),
        'panel_password' => collectorSetting(
            'PANEL_LOGIN_PASSWORD',
            $projectEnvironment,
            'password',
        ),
        'timeout' => $timeout === false ? 10 : max(1, min(60, $timeout)),
    ];
}

function sendSecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function h(string|int|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function wantsJson(): bool
{
    return str_contains(
        strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')),
        'application/json',
    );
}

function scalarRequestValue(string $key, int $maxLength): ?string
{
    $value = $_POST[$key] ?? $_GET[$key] ?? null;
    return is_string($value) && strlen($value) <= $maxLength ? $value : null;
}

function scalarPostValue(string $key, int $maxLength): ?string
{
    $value = $_POST[$key] ?? null;
    return is_string($value) && strlen($value) <= $maxLength ? $value : null;
}

function localCsrfToken(): string
{
    if (!isset($_SESSION['local_csrf']) || !is_string($_SESSION['local_csrf'])) {
        $_SESSION['local_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['local_csrf'];
}

function requireLocalCsrf(): void
{
    $submitted = scalarPostValue('csrf_token', 64);
    if ($submitted === null || !hash_equals(localCsrfToken(), $submitted)) {
        throw new CollectorError('This page has expired. Refresh it and try again.', 403);
    }
}

function normalizeAnswer(string $answer): string
{
    $answer = strtr(trim($answer), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    if ($answer === '' || strlen($answer) > 16 || preg_match('/^[0-9]+$/D', $answer) !== 1) {
        throw new CollectorError('Enter digits only.', 422);
    }
    return $answer;
}

function panelUrl(array $config, string $path): string
{
    $base = $config['panel_base_url'];
    $parts = parse_url($base);
    if (
        $parts === false
        || !isset($parts['scheme'], $parts['host'])
        || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
        || isset($parts['user'])
        || isset($parts['pass'])
        || isset($parts['query'])
        || isset($parts['fragment'])
    ) {
        throw new CollectorError('PANEL_BASE_URL must be a valid HTTP or HTTPS URL.', 500);
    }

    $scheme = strtolower((string) $parts['scheme']);
    $host = strtolower((string) $parts['host']);
    $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
    $origin = $scheme . '://' . $parts['host'] . $port;

    if (str_starts_with($path, '//')) {
        $url = $scheme . ':' . $path;
    } elseif (preg_match('#^https?://#i', $path) === 1) {
        $url = $path;
    } elseif (str_starts_with($path, '/')) {
        $url = $origin . $path;
    } else {
        $basePath = (string) ($parts['path'] ?? '');
        $directory = $basePath === '' || $basePath === '/'
            ? '/panel/'
            : rtrim($basePath, '/') . '/';
        $url = $origin . $directory . $path;
    }

    $target = parse_url($url);
    if ($target === false) {
        throw new CollectorError('The panel returned an unsafe URL.', 502);
    }
    $targetPort = isset($target['port']) ? ':' . (int) $target['port'] : '';
    if (
        !isset($target['scheme'], $target['host'])
        || strtolower((string) $target['scheme']) !== $scheme
        || strtolower((string) $target['host']) !== $host
        || $targetPort !== $port
        || isset($target['user'])
        || isset($target['pass'])
        || isset($target['fragment'])
    ) {
        throw new CollectorError('The panel returned an unsafe URL.', 502);
    }

    return $url;
}

/**
 * @param list<string> $headers
 */
function panelRequest(
    array $config,
    string $method,
    string $path,
    array $headers = [],
    string $body = '',
): PanelResponse {
    foreach ($headers as $header) {
        if (str_contains($header, "\r") || str_contains($header, "\n")) {
            throw new CollectorError('Unsafe outbound panel header.', 500);
        }
    }

    $headers[] = 'Accept: text/html, image/*;q=0.9, */*;q=0.5';
    $headers[] = 'User-Agent: tci-ocr-panel-collector/1.0';
    $options = [
        'http' => [
            'method' => strtoupper($method),
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => $config['timeout'],
            'protocol_version' => 1.1,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];

    $stream = @fopen(panelUrl($config, $path), 'rb', false, stream_context_create($options));
    if ($stream === false) {
        $details = error_get_last();
        $message = is_array($details) ? (string) ($details['message'] ?? '') : '';
        error_log('Panel request failed: ' . $message);
        throw new CollectorError('Could not connect to the panel.', 502);
    }

    try {
        $responseBody = stream_get_contents($stream, MAX_PANEL_RESPONSE_BYTES + 1);
        $metadata = stream_get_meta_data($stream);
    } finally {
        fclose($stream);
    }

    if ($responseBody === false || ($metadata['timed_out'] ?? false) === true) {
        throw new CollectorError('The panel request timed out.', 504);
    }
    if (strlen($responseBody) > MAX_PANEL_RESPONSE_BYTES) {
        throw new CollectorError('The panel returned an unexpectedly large response.', 502);
    }

    $rawHeaders = $metadata['wrapper_data'] ?? [];
    if (!is_array($rawHeaders)) {
        throw new CollectorError('The panel returned malformed HTTP headers.', 502);
    }

    $status = 0;
    $parsedHeaders = [];
    foreach ($rawHeaders as $line) {
        if (!is_string($line)) {
            continue;
        }
        if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $matches) === 1) {
            $status = (int) $matches[1];
            $parsedHeaders = [];
            continue;
        }
        $separator = strpos($line, ':');
        if ($separator === false) {
            continue;
        }
        $name = substr($line, 0, $separator);
        $value = substr($line, $separator + 1);
        if (trim($name) === '') {
            continue;
        }
        $parsedHeaders[strtolower(trim($name))][] = trim($value);
    }

    if ($status === 0) {
        throw new CollectorError('The panel returned a response without an HTTP status.', 502);
    }
    return new PanelResponse($status, $parsedHeaders, $responseBody);
}

/** @return array<string, string> */
function panelCookieMap(string $cookieHeader): array
{
    $cookies = [];
    foreach (explode(';', $cookieHeader) as $pair) {
        $separator = strpos($pair, '=');
        if ($separator === false) {
            continue;
        }
        $name = trim(substr($pair, 0, $separator));
        $value = trim(substr($pair, $separator + 1));
        if (
            preg_match('/^[A-Za-z0-9_.-]+$/D', $name) === 1
            && preg_match('/[\x00-\x20\x7f;]/', $value) !== 1
        ) {
            $cookies[$name] = $value;
        }
    }
    return $cookies;
}

function mergePanelCookies(
    string $cookieHeader,
    PanelResponse $response,
    bool $required = false,
): string {
    $cookies = panelCookieMap($cookieHeader);
    foreach ($response->headers('set-cookie') as $header) {
        $pair = trim(explode(';', $header, 2)[0]);
        $separator = strpos($pair, '=');
        if ($separator === false) {
            continue;
        }
        $name = substr($pair, 0, $separator);
        $value = substr($pair, $separator + 1);
        if (
            preg_match('/^[A-Za-z0-9_.-]+$/D', $name) !== 1
            || preg_match('/[\x00-\x20\x7f;]/', $value) === 1
        ) {
            continue;
        }
        $cookies[$name] = $value;
    }
    if ($required && $cookies === []) {
        throw new CollectorError('The panel did not create a login session.', 502);
    }

    $parts = [];
    foreach ($cookies as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    return implode('; ', $parts);
}

function extractPanelFormAction(string $html): string
{
    $patterns = [
        '/<form\b[^>]*\baction\s*=\s*(["\'])(.*?)\1/is',
        '/<form\b[^>]*\baction\s*=\s*([^\s>"\']+)/is',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $html, $matches) === 1) {
            $value = html_entity_decode(
                $matches[count($matches) - 1],
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            );
            if ($value !== '') {
                return $value;
            }
        }
    }
    throw new CollectorError('Could not find the panel login form action.', 502);
}

function extractPanelCaptchaUrl(string $html): string
{
    if (
        preg_match(
            '/<img\b(?=[^>]*\bid\s*=\s*(["\'])loginCaptchaImage\1)[^>]*\bsrc\s*=\s*(["\'])(.*?)\2/is',
            $html,
            $matches,
        ) !== 1
    ) {
        throw new CollectorError('Could not find the panel captcha image.', 502);
    }

    $url = html_entity_decode($matches[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '') {
        throw new CollectorError('The panel returned an empty captcha URL.', 502);
    }
    return $url;
}

function imageType(string $bytes, ?string $contentType): array
{
    $mediaType = strtolower(trim(explode(';', (string) $contentType, 2)[0]));
    $types = [
        'image/jpeg' => ['extension' => '.jpg', 'magic' => str_starts_with($bytes, "\xff\xd8\xff")],
        'image/png' => ['extension' => '.png', 'magic' => str_starts_with($bytes, "\x89PNG\r\n\x1a\n")],
        'image/gif' => ['extension' => '.gif', 'magic' => str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')],
        'image/bmp' => ['extension' => '.bmp', 'magic' => str_starts_with($bytes, 'BM')],
        'image/webp' => [
            'extension' => '.webp',
            'magic' => strlen($bytes) >= 12
                && substr($bytes, 0, 4) === 'RIFF'
                && substr($bytes, 8, 4) === 'WEBP',
        ],
    ];
    $type = $types[$mediaType] ?? null;
    if ($type === null || $type['magic'] !== true) {
        throw new CollectorError('The panel returned an unsupported captcha image.', 502);
    }
    return ['mime' => $mediaType, 'extension' => $type['extension']];
}

function fetchPanelChallenge(array $config): array
{
    $login = panelRequest($config, 'GET', '/panel/');
    if ($login->status !== 200) {
        throw new CollectorError('The panel login page is unavailable.', 502);
    }
    $cookie = mergePanelCookies('', $login, true);
    $loginUrl = panelUrl($config, extractPanelFormAction($login->body));
    $captchaUrl = panelUrl($config, extractPanelCaptchaUrl($login->body));

    $captcha = panelRequest(
        $config,
        'GET',
        $captchaUrl,
        ['Cookie: ' . $cookie],
    );
    if ($captcha->status !== 200 || $captcha->body === '') {
        throw new CollectorError('The panel captcha is unavailable.', 502);
    }
    $cookie = mergePanelCookies($cookie, $captcha);
    $type = imageType($captcha->body, $captcha->header('content-type'));

    return [
        'id' => bin2hex(random_bytes(16)),
        'cookie' => $cookie,
        'login_url' => $loginUrl,
        'image' => $captcha->body,
        'mime' => $type['mime'],
        'extension' => $type['extension'],
        'issued_at' => time(),
    ];
}

function challengeIsUsable(mixed $challenge): bool
{
    return is_array($challenge)
        && isset(
            $challenge['id'],
            $challenge['cookie'],
            $challenge['login_url'],
            $challenge['image'],
            $challenge['mime'],
            $challenge['extension'],
            $challenge['issued_at'],
        )
        && is_string($challenge['id'])
        && is_string($challenge['cookie'])
        && is_string($challenge['login_url'])
        && is_string($challenge['image'])
        && is_string($challenge['mime'])
        && is_string($challenge['extension'])
        && is_int($challenge['issued_at'])
        && (time() - $challenge['issued_at']) <= CHALLENGE_MAX_AGE;
}

function ensurePanelChallenge(array $config, bool $force = false): array
{
    $current = $_SESSION['panel_challenge'] ?? null;
    if (!$force && challengeIsUsable($current)) {
        return $current;
    }

    unset($_SESSION['panel_challenge']);
    $challenge = fetchPanelChallenge($config);
    $_SESSION['panel_challenge'] = $challenge;
    return $challenge;
}

function challengeImageUrl(array $challenge): string
{
    return '?action=captcha&id=' . rawurlencode($challenge['id']);
}

function challengePayload(array $challenge): array
{
    return [
        'id' => $challenge['id'],
        'image_url' => challengeImageUrl($challenge),
    ];
}

function servePendingCaptcha(): never
{
    $id = scalarRequestValue('id', 64) ?? '';
    $challenge = $_SESSION['panel_challenge'] ?? null;
    if (
        !challengeIsUsable($challenge)
        || $id === ''
        || !hash_equals($challenge['id'], $id)
    ) {
        throw new CollectorError('Captcha not found or expired.', 404);
    }

    header('Content-Type: ' . $challenge['mime']);
    header('Content-Length: ' . strlen($challenge['image']));
    echo $challenge['image'];
    exit;
}

function validatePendingChallenge(array $config, string $challengeId, string $answer): array
{
    $challenge = $_SESSION['panel_challenge'] ?? null;
    if (
        !challengeIsUsable($challenge)
        || $challengeId === ''
        || !hash_equals($challenge['id'], $challengeId)
    ) {
        throw new CollectorError('This captcha has expired. Load a new one.', 409);
    }

    // A panel captcha can be used only once. Discard it before the network request
    // so an ambiguous timeout can never cause a guessed label to be submitted twice.
    unset($_SESSION['panel_challenge']);

    $body = http_build_query([
        'username' => $config['panel_username'],
        'password' => $config['panel_password'],
        'captcha' => $answer,
        'redirect' => '',
        'LoginFromWeb' => '1',
    ], '', '&', PHP_QUERY_RFC3986);

    $response = panelRequest(
        $config,
        'POST',
        $challenge['login_url'],
        [
            'Content-Type: application/x-www-form-urlencoded',
            'Content-Length: ' . strlen($body),
            'Cookie: ' . $challenge['cookie'],
        ],
        $body,
    );

    $location = $response->header('location');
    if (
        !in_array($response->status, [301, 302, 303, 307, 308], true)
        || $location === null
    ) {
        throw new CollectorError(
            'The panel returned an unexpected login response; nothing was saved.',
            502,
        );
    }

    $cookie = mergePanelCookies($challenge['cookie'], $response);
    $result = panelRequest(
        $config,
        'GET',
        panelUrl($config, $location),
        ['Cookie: ' . $cookie],
    );
    if ($result->status !== 200) {
        throw new CollectorError(
            'The panel login result page is unavailable; nothing was saved.',
            502,
        );
    }

    if (
        stripos($result->body, 'logout') !== false
        || str_contains($result->body, 'خروج')
    ) {
        return storeValidatedSample($config, $challenge, $answer);
    }

    if (
        str_contains($result->body, 'کد امنیتی وارد شده نادرست است')
        || str_contains($result->body, 'کد امنیتی منقضی شده است')
    ) {
        return ['stored' => false, 'rejected' => true];
    }

    if (
        str_contains($result->body, 'loginCaptchaImage')
        || preg_match('/<input\b[^>]*\bname\s*=\s*(["\'])password\1/is', $result->body) === 1
    ) {
        throw new CollectorError(
            'The panel rejected the configured username or password.',
            502,
        );
    }

    throw new CollectorError(
        'The panel returned an unexpected login response; nothing was saved.',
        502,
    );
}

function outcomeMessage(string $outcome): string
{
    return match ($outcome) {
        'saved' => 'Correct — saved.',
        'duplicate' => 'Correct — already saved.',
        'rejected' => 'Rejected — showing the next captcha.',
        'skipped' => 'New captcha loaded.',
        default => 'Ready.',
    };
}

function ensureDirectory(string $path): void
{
    if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
        throw new CollectorError("Cannot create dataset directory: {$path}", 500);
    }
}

function writeAtomic(string $path, string $bytes): void
{
    ensureDirectory(dirname($path));
    $temporary = dirname($path) . DIRECTORY_SEPARATOR
        . '.' . basename($path) . '.' . bin2hex(random_bytes(6)) . '.tmp';
    $handle = @fopen($temporary, 'xb');
    if ($handle === false) {
        throw new CollectorError('Cannot create a temporary dataset file.', 500);
    }

    $completed = false;
    try {
        try {
            $written = 0;
            $length = strlen($bytes);
            while ($written < $length) {
                $count = fwrite($handle, substr($bytes, $written));
                if ($count === false || $count === 0) {
                    throw new CollectorError('Could not write the captcha image.', 500);
                }
                $written += $count;
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new CollectorError('Could not safely flush the captcha image.', 500);
            }
        } finally {
            fclose($handle);
        }

        if (!@rename($temporary, $path)) {
            throw new CollectorError('Could not finalize the captcha image.', 500);
        }
        $completed = true;
    } finally {
        if (!$completed && is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

function csvContainsFilename($handle, string $filename): bool
{
    rewind($handle);
    fgetcsv($handle, null, ',', '"', '');
    while (($row = fgetcsv($handle, null, ',', '"', '')) !== false) {
        if (($row[0] ?? null) === $filename) {
            return true;
        }
    }
    return false;
}

function validateOrCreateCsvHeader($handle): void
{
    $stats = fstat($handle);
    if ($stats === false) {
        throw new CollectorError('Could not inspect labels.csv.', 500);
    }
    if ((int) $stats['size'] === 0) {
        if (fputcsv($handle, DATASET_COLUMNS, ',', '"', '', "\n") === false) {
            throw new CollectorError('Could not initialize labels.csv.', 500);
        }
        return;
    }

    rewind($handle);
    $header = fgetcsv($handle, null, ',', '"', '');
    if ($header !== DATASET_COLUMNS) {
        throw new CollectorError('labels.csv has an incompatible header.', 409);
    }
}

function appendCsvRow($handle, array $row): void
{
    if (fseek($handle, 0, SEEK_END) !== 0) {
        throw new CollectorError('Could not seek to the end of labels.csv.', 500);
    }
    $position = ftell($handle);
    if ($position === false) {
        throw new CollectorError('Could not inspect labels.csv.', 500);
    }
    if ($position > 0) {
        if (fseek($handle, -1, SEEK_END) !== 0) {
            throw new CollectorError('Could not inspect the end of labels.csv.', 500);
        }
        $last = fread($handle, 1);
        if (fseek($handle, 0, SEEK_END) !== 0) {
            throw new CollectorError('Could not seek to the end of labels.csv.', 500);
        }
        if ($last !== "\n" && fwrite($handle, "\n") !== 1) {
            throw new CollectorError('Could not repair the labels.csv line ending.', 500);
        }
    }
    if (fputcsv($handle, $row, ',', '"', '', "\n") === false) {
        throw new CollectorError('Could not append to labels.csv.', 500);
    }
    if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
        throw new CollectorError('Could not safely flush labels.csv.', 500);
    }
}

function storeValidatedSample(array $config, array $challenge, string $label): array
{
    $dataset = $config['dataset'];
    ensureDirectory($dataset);
    $hash = hash('sha256', $challenge['image']);
    $relative = 'images/panel/' . substr($hash, 0, 2) . '/'
        . $hash . $challenge['extension'];
    $imagePath = $dataset . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $marker = $dataset . DIRECTORY_SEPARATOR . '.panel-samples'
        . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
        . DIRECTORY_SEPARATOR . $hash . '.done';
    $csvPath = $dataset . DIRECTORY_SEPARATOR . 'labels.csv';
    $lockPath = $dataset . DIRECTORY_SEPARATOR . '.panel-collector.lock';

    $lock = @fopen($lockPath, 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        if (is_resource($lock)) {
            fclose($lock);
        }
        throw new CollectorError('Could not lock the dataset for writing.', 503);
    }

    try {
        $csv = @fopen($csvPath, 'c+b');
        if ($csv === false) {
            throw new CollectorError('Could not open labels.csv for writing.', 500);
        }
        try {
            validateOrCreateCsvHeader($csv);

            $csvChecked = false;
            if (is_file($marker)) {
                $csvChecked = true;
                if (csvContainsFilename($csv, $relative)) {
                    return ['stored' => false, 'hash' => $hash, 'filename' => $relative];
                }
                if (!@unlink($marker)) {
                    throw new CollectorError('Could not repair stale panel sample state.', 500);
                }
            }

            if (is_file($imagePath)) {
                $existingHash = @hash_file('sha256', $imagePath);
                if (!is_string($existingHash) || !hash_equals($hash, $existingHash)) {
                    throw new CollectorError('An existing captcha file failed its hash check.', 500);
                }
                if (!$csvChecked && csvContainsFilename($csv, $relative)) {
                    writeAtomic($marker, "saved\n");
                    return ['stored' => false, 'hash' => $hash, 'filename' => $relative];
                }
            } else {
                writeAtomic($imagePath, $challenge['image']);
            }

            appendCsvRow($csv, [$relative, $label, 'panel', '1', $hash]);
            writeAtomic($marker, "saved\n");
        } finally {
            fclose($csv);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }

    return ['stored' => true, 'hash' => $hash, 'filename' => $relative];
}
