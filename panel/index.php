<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

const DEFAULT_TRAFFIC_GB = '12.5';
const DEFAULT_PLAN_NAME = 'سرویس طلایی';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set(
    'session.cookie_secure',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0'
);
session_name('CUSTOMER_PORTAL_SESSION');
session_start();

sendSecurityHeaders();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($method === 'GET' && $path === '/') {
    header('Location: /panel', true, 302);
    exit;
}

if ($method === 'GET' && $path === '/captcha') {
    renderCaptcha();
}

if ($method === 'POST' && ($path === '/login' || $path === '/panel')) {
    handleLogin();
}

if ($method === 'POST' && $path === '/logout') {
    logout();
}

if ($method === 'GET' && $path === '/panel') {
    if (($_SESSION['authenticated'] ?? false) === true) {
        renderPanel();
    }

    renderLogin();
}

if (in_array($path, ['/panel', '/login', '/logout', '/captcha'], true)) {
    header('Allow: GET, POST');
    renderErrorPage(405, 'روش درخواست پشتیبانی نمی‌شود.');
}

renderErrorPage(404, 'صفحه مورد نظر پیدا نشد.');

function sendSecurityHeaders(): void
{
    header_remove('X-Powered-By');
    header('Cache-Control: no-store, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

function envValue(string $key, string $default = ''): string
{
    $value = getenv($key);

    return $value === false || $value === '' ? $default : $value;
}

function scalarPostValue(string $key, int $maxLength): ?string
{
    if (!array_key_exists($key, $_POST) || !is_string($_POST[$key])) {
        return null;
    }

    $value = $_POST[$key];

    return strlen($value) <= $maxLength ? $value : null;
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrfIsValid(?string $token): bool
{
    $storedToken = $_SESSION['csrf_token'] ?? null;

    return is_string($token)
        && is_string($storedToken)
        && hash_equals($storedToken, $token);
}

function handleLogin(): never
{
    $username = scalarPostValue('username', 128);
    $password = scalarPostValue('password', 256);
    $captcha = scalarPostValue('captcha', 16);
    $csrf = scalarPostValue('csrf_token', 64);

    if ($username === null || $password === null || $captcha === null || !csrfIsValid($csrf)) {
        renderLogin('درخواست ورود معتبر نیست. لطفاً صفحه را تازه‌سازی و دوباره تلاش کنید.', 400, $username ?? '');
    }

    $captchaHash = $_SESSION['captcha_hash'] ?? null;
    $captchaIssuedAt = $_SESSION['captcha_issued_at'] ?? 0;
    unset($_SESSION['captcha_hash'], $_SESSION['captcha_issued_at']);

    $captchaIsValid = is_string($captchaHash)
        && is_int($captchaIssuedAt)
        && (time() - $captchaIssuedAt) <= 300
        && password_verify(strtoupper(trim($captcha)), $captchaHash);

    if (!$captchaIsValid) {
        renderLogin('کد امنیتی اشتباه است یا اعتبار آن به پایان رسیده است.', 401, $username);
    }

    $expectedUsername = envValue('PANEL_USERNAME', 'username');
    $expectedPasswordHash = envValue('PANEL_PASSWORD_HASH', password_hash("password", PASSWORD_DEFAULT));

    if ($expectedUsername === '' || $expectedPasswordHash === '') {
        error_log('Customer portal credentials are not configured.');
        renderLogin('سرویس ورود موقتاً در دسترس نیست. لطفاً کمی بعد تلاش کنید.', 503, $username);
    }

    $usernameIsValid = hash_equals($expectedUsername, trim($username));
    $passwordIsValid = password_verify($password, $expectedPasswordHash);
    $credentialsAreValid = $usernameIsValid && $passwordIsValid;

    if (!$credentialsAreValid) {
        renderLogin('نام کاربری یا رمز عبور صحیح نیست.', 401, $username);
    }

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = trim($username);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    header('Location: /panel', true, 303);
    exit;
}

function logout(): never
{
    $csrf = scalarPostValue('csrf_token', 64);
    if (!csrfIsValid($csrf)) {
        renderErrorPage(403, 'اعتبار درخواست به پایان رسیده است.');
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    session_destroy();
    header('Location: /panel', true, 303);
    exit;
}

function generateCaptchaCode(): string
{
    $alphabet = '123456789';
    $code = '';

    for ($i = 0; $i < 5; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }

    return $code;
}

function renderCaptcha(): never
{
    $code = generateCaptchaCode();
    $_SESSION['captcha_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['captcha_issued_at'] = time();

    if (!function_exists('imagecreatetruecolor')) {
        header('Content-Type: image/svg+xml; charset=UTF-8');
        $escapedCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        echo <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="220" height="72" viewBox="0 0 220 72">
  <defs><linearGradient id="bg" x1="0" x2="1"><stop stop-color="#f4f7fb"/><stop offset="1" stop-color="#e8eef7"/></linearGradient></defs>
  <rect width="220" height="72" rx="10" fill="url(#bg)"/>
  <path d="M3 17L217 55M8 63L212 11M28 4L191 68M5 40L215 29" stroke="#9badc4" stroke-width="1.5" opacity=".7"/>
  <text x="110" y="48" text-anchor="middle" font-family="monospace" font-size="32"
        font-weight="bold" letter-spacing="8" fill="#19324d">{$escapedCode}</text>
</svg>
SVG;
        exit;
    }

    $image = imagecreatetruecolor(220, 72);
    $background = imagecolorallocate($image, 241, 245, 250);
    $foreground = imagecolorallocate($image, 25, 50, 77);
    $noise = imagecolorallocate($image, 139, 160, 185);
    imagefilledrectangle($image, 0, 0, 220, 72, $background);

    for ($i = 0; $i < 9; $i++) {
        imageline(
            $image,
            random_int(0, 219),
            random_int(0, 71),
            random_int(0, 219),
            random_int(0, 71),
            $noise
        );
    }

    $font = 5;
    $textWidth = imagefontwidth($font) * strlen($code);
    $textHeight = imagefontheight($font);
    $textLayer = imagecreatetruecolor($textWidth, $textHeight);
    imagefilledrectangle($textLayer, 0, 0, $textWidth, $textHeight, $background);
    imagecolortransparent($textLayer, $background);
    imagestring($textLayer, $font, 0, 0, $code, $foreground);
    $scaledTextLayer = imagescale(
        $textLayer,
        $textWidth * 3,
        $textHeight * 3,
        IMG_NEAREST_NEIGHBOUR
    );
    imagecopy(
        $image,
        $scaledTextLayer,
        (int) ((220 - imagesx($scaledTextLayer)) / 2),
        (int) ((72 - imagesy($scaledTextLayer)) / 2),
        0,
        0,
        imagesx($scaledTextLayer),
        imagesy($scaledTextLayer)
    );
    header('Content-Type: image/jpeg');
    imagejpeg($image, null, 92);
    exit;
}

function renderLogin(string $error = '', int $status = 200, string $username = ''): never
{
    http_response_code($status);
    $safeUsername = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCsrf = htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $errorHtml = $error === ''
        ? ''
        : '<div class="alert" role="alert"><span class="alert-icon">!</span><span>'
            . htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</span></div>';
    $captchaUrl = '/captcha?nonce=' . rawurlencode(bin2hex(random_bytes(8)));

    echo pageStart('ورود به حساب کاربری', 'login-page');
    echo <<<HTML
<main class="login-shell">
  <section class="login-card" aria-labelledby="login-title">
    <div class="login-brand">
      <a class="brand brand-light" href="/panel" aria-label="صفحه اصلی آبان‌نت">
        <span class="brand-mark" aria-hidden="true">
          <svg viewBox="0 0 48 48"><path d="M24 5a19 19 0 1 0 19 19A19 19 0 0 0 24 5Zm0 7a12 12 0 0 1 10.4 6H13.6A12 12 0 0 1 24 12Zm0 24a12 12 0 0 1-10.4-6h20.8A12 12 0 0 1 24 36Z"/></svg>
        </span>
        <span><b>آبان‌نت</b><small>اینترنت، همیشه در دسترس</small></span>
      </a>
      <div class="brand-message">
        <span class="eyebrow">سامانه مشترکین</span>
        <h2>همه‌چیز برای مدیریت بهتر سرویس شما</h2>
        <p>مصرف اینترنت، وضعیت سرویس و جزئیات حساب خود را یک‌جا مشاهده و مدیریت کنید.</p>
      </div>
      <div class="brand-support">
        <span>پشتیبانی شبانه‌روزی</span>
        <b dir="ltr">۰۲۱-۹۱۰۰ ۱۰۱۰</b>
      </div>
    </div>

    <div class="login-form-wrap">
      <div class="mobile-brand">
        <span class="brand-mark" aria-hidden="true">
          <svg viewBox="0 0 48 48"><path d="M24 5a19 19 0 1 0 19 19A19 19 0 0 0 24 5Zm0 7a12 12 0 0 1 10.4 6H13.6A12 12 0 0 1 24 12Zm0 24a12 12 0 0 1-10.4-6h20.8A12 12 0 0 1 24 36Z"/></svg>
        </span>
        <b>آبان‌نت</b>
      </div>
      <div class="form-heading">
        <span class="eyebrow">خوش آمدید</span>
        <h1 id="login-title">ورود به حساب کاربری</h1>
        <p>برای مشاهده و مدیریت سرویس وارد حساب خود شوید.</p>
      </div>
      {$errorHtml}
      <form method="post" action="/login" autocomplete="on">
        <input type="hidden" name="csrf_token" value="{$safeCsrf}">

        <label for="username">نام کاربری</label>
        <div class="input-wrap">
          <span class="field-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.42 0-8 2.01-8 4.5V20h16v-1.5c0-2.49-3.58-4.5-8-4.5Z"/></svg>
          </span>
          <input id="username" name="username" type="text" value="{$safeUsername}" maxlength="128"
                 autocomplete="username" placeholder="نام کاربری خود را وارد کنید" required autofocus>
        </div>

        <div class="label-row">
          <label for="password">رمز عبور</label>
          <a href="#" class="subtle-link">رمز عبور را فراموش کرده‌اید؟</a>
        </div>
        <div class="input-wrap">
          <span class="field-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M17 9h-1V7a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v9h14v-9a2 2 0 0 0-2-2Zm-5 7.75A1.75 1.75 0 1 1 13.75 15 1.75 1.75 0 0 1 12 16.75ZM14 9h-4V7a2 2 0 0 1 4 0Z"/></svg>
          </span>
          <input id="password" name="password" type="password" maxlength="256"
                 autocomplete="current-password" placeholder="رمز عبور خود را وارد کنید" required>
        </div>

        <label for="captcha">کد امنیتی</label>
        <div class="captcha-row">
          <div class="input-wrap">
            <input id="captcha" name="captcha" type="text" maxlength="16" inputmode="text"
                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                   placeholder="کد تصویر" required>
          </div>
          <a class="captcha-image" href="/panel" title="دریافت کد جدید">
            <img src="{$captchaUrl}" width="220" height="72" alt="کد امنیتی">
          </a>
        </div>

        <button class="primary-button" type="submit">
          <span>ورود به پنل</span>
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.7 6.3-1.4 1.4 3.3 3.3H4v2h12.6l-3.3 3.3 1.4 1.4 5.7-5.7Z"/></svg>
        </button>
      </form>
      <p class="privacy-note">
        ورود شما به سامانه به معنای پذیرش
        <a href="#">شرایط استفاده و حریم خصوصی</a>
        است.
      </p>
    </div>
  </section>
  <footer class="page-footer">© ۱۴۰۵ آبان‌نت — تمامی حقوق محفوظ است.</footer>
</main>
HTML;
    echo pageEnd();
    exit;
}

function renderPanel(): never
{
    $rawUsername = (string) ($_SESSION['username'] ?? '');
    $username = htmlspecialchars(
        $rawUsername,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
    $avatarCharacter = 'ک';
    if (preg_match('/^./u', $rawUsername, $avatarMatch) === 1) {
        $avatarCharacter = htmlspecialchars($avatarMatch[0], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    $traffic = envValue('PANEL_TRAFFIC_GB', DEFAULT_TRAFFIC_GB);
    if (preg_match('/^\d+(?:\.\d+)?$/', $traffic) !== 1) {
        $traffic = DEFAULT_TRAFFIC_GB;
    }

    $totalTraffic = envValue('PANEL_TOTAL_TRAFFIC_GB', '50');
    if (preg_match('/^\d+(?:\.\d+)?$/', $totalTraffic) !== 1 || (float) $totalTraffic <= 0) {
        $totalTraffic = '50';
    }

    $trafficPercent = min(100, max(0, ((float) $traffic / (float) $totalTraffic) * 100));
    $trafficPercentCss = number_format($trafficPercent, 1, '.', '');
    $usedTraffic = max(0, (float) $totalTraffic - (float) $traffic);
    $usedTrafficText = number_format($usedTraffic, 1, '.', '');
    $safeTraffic = htmlspecialchars($traffic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeTotalTraffic = htmlspecialchars($totalTraffic, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $planName = htmlspecialchars(envValue('PANEL_PLAN_NAME', DEFAULT_PLAN_NAME), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $expiryDate = htmlspecialchars(envValue('PANEL_EXPIRY_DATE', '۱۴۰۵/۰۶/۲۸'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeCsrf = htmlspecialchars(csrfToken(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    echo pageStart('داشبورد مشترکین', 'dashboard-page');
    echo <<<HTML
<div class="dashboard-layout">
  <aside class="sidebar">
    <a class="brand brand-dark" href="/panel">
      <span class="brand-mark" aria-hidden="true">
        <svg viewBox="0 0 48 48"><path d="M24 5a19 19 0 1 0 19 19A19 19 0 0 0 24 5Zm0 7a12 12 0 0 1 10.4 6H13.6A12 12 0 0 1 24 12Zm0 24a12 12 0 0 1-10.4-6h20.8A12 12 0 0 1 24 36Z"/></svg>
      </span>
      <span><b>آبان‌نت</b><small>پنل مشترکین</small></span>
    </a>
    <nav class="side-nav" aria-label="منوی اصلی">
      <a class="active" href="/panel">
        <svg viewBox="0 0 24 24"><path d="M4 13h6V4H4Zm0 7h6v-5H4Zm8 0h8v-9h-8Zm0-16v5h8V4Z"/></svg>
        <span>داشبورد</span>
      </a>
      <a href="#">
        <svg viewBox="0 0 24 24"><path d="M4 4h16v4H4Zm0 6h16v10H4Zm3 3v2h4v-2Z"/></svg>
        <span>سرویس‌های من</span>
      </a>
      <a href="#">
        <svg viewBox="0 0 24 24"><path d="M3 13h2v-2H3Zm0 4h2v-2H3Zm0-8h2V7H3Zm4 4h14v-2H7Zm0 4h14v-2H7ZM7 7v2h14V7Z"/></svg>
        <span>ریز مصرف</span>
      </a>
      <a href="#">
        <svg viewBox="0 0 24 24"><path d="M11 8h2v2h-2Zm0 4h2v6h-2Zm1-10a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z"/></svg>
        <span>درخواست پشتیبانی</span>
      </a>
      <a href="#">
        <svg viewBox="0 0 24 24"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4.42 0-8 2.01-8 4.5V20h16v-1.5c0-2.49-3.58-4.5-8-4.5Z"/></svg>
        <span>حساب کاربری</span>
      </a>
    </nav>
    <div class="sidebar-help">
      <span class="help-icon">؟</span>
      <b>نیاز به راهنمایی دارید؟</b>
      <small>کارشناسان ما شبانه‌روزی پاسخگوی شما هستند.</small>
      <a href="#">ارتباط با پشتیبانی</a>
    </div>
    <form method="post" action="/logout" class="logout-form">
      <input type="hidden" name="csrf_token" value="{$safeCsrf}">
      <button type="submit">
        <svg viewBox="0 0 24 24"><path d="M10 17v3H4V4h6v3h2V2H2v20h10v-5Zm10-5-4-4v3H8v2h8v3Z"/></svg>
        خروج از حساب
      </button>
    </form>
  </aside>

  <main class="dashboard-main">
    <header class="topbar">
      <div class="mobile-logo"><span class="brand-mark"><svg viewBox="0 0 48 48"><path d="M24 5a19 19 0 1 0 19 19A19 19 0 0 0 24 5Zm0 7a12 12 0 0 1 10.4 6H13.6A12 12 0 0 1 24 12Zm0 24a12 12 0 0 1-10.4-6h20.8A12 12 0 0 1 24 36Z"/></svg></span><b>آبان‌نت</b></div>
      <div class="top-actions">
        <button class="icon-button" type="button" aria-label="اعلان‌ها">
          <svg viewBox="0 0 24 24"><path d="M18 8a6 6 0 0 0-5-5.91V1h-2v1.09A6 6 0 0 0 6 8c0 7-3 7-3 9h18c0-2-3-2-3-9Zm-8 11a2 2 0 0 0 4 0Z"/></svg>
          <span class="notification-dot"></span>
        </button>
        <div class="user-menu">
          <span class="avatar">{$avatarCharacter}</span>
          <span><small>مشترک آبان‌نت</small><b>{$username}</b></span>
        </div>
      </div>
    </header>

    <div class="dashboard-content">
      <div class="welcome-row">
        <div>
          <p class="eyebrow">داشبورد مشترکین</p>
          <h1>سلام {$username}، خوش آمدید</h1>
          <p>در این صفحه می‌توانید وضعیت سرویس و مصرف خود را بررسی کنید.</p>
        </div>
        <span class="status-pill"><i></i> سرویس شما فعال است</span>
      </div>

      <section class="summary-grid" aria-label="خلاصه سرویس">
        <article class="service-card">
          <div class="card-head">
            <div>
              <span class="card-label">سرویس فعال</span>
              <h2>{$planName}</h2>
            </div>
            <span class="round-icon blue"><svg viewBox="0 0 24 24"><path d="M4 6h16v12H4Zm2 2v8h12V8Zm2 2h5v2H8Z"/></svg></span>
          </div>
          <div class="service-meta">
            <div><small>سرعت سرویس</small><b>۱۶ مگابیت</b></div>
            <div><small>تاریخ انقضا</small><b>{$expiryDate}</b></div>
          </div>
          <a class="text-link" href="#">مشاهده جزئیات سرویس <span>←</span></a>
        </article>

        <article class="usage-card">
          <div class="card-head">
            <div>
              <span class="card-label">حجم باقی‌مانده</span>
              <h2><strong>{$safeTraffic}</strong> گیگابایت</h2>
            </div>
            <span class="round-icon green"><svg viewBox="0 0 24 24"><path d="M11 2v20a10 10 0 0 1 0-20Zm2 0a10 10 0 0 1 0 20Z"/></svg></span>
          </div>
          <div class="progress" aria-label="درصد حجم باقی‌مانده"><span style="width: {$trafficPercentCss}%"></span></div>
          <div class="usage-meta"><span>{$usedTrafficText} گیگابایت مصرف‌شده</span><span>از {$safeTotalTraffic} گیگابایت</span></div>
          <a class="text-link" href="#">مشاهده ریز مصرف <span>←</span></a>
        </article>
      </section>

      <section class="quick-section">
        <div class="section-heading"><h2>دسترسی سریع</h2><p>عملیات پرکاربرد حساب شما</p></div>
        <div class="quick-grid">
          <a href="#"><span class="quick-icon purple"><svg viewBox="0 0 24 24"><path d="M19 7h-1V6a3 3 0 0 0-6 0v1H5a2 2 0 0 0-2 2v10h18V9a2 2 0 0 0-2-2Zm-5-1a1 1 0 0 1 2 0v1h-2Z"/></svg></span><span><b>تمدید سرویس</b><small>تمدید یا تغییر طرح فعلی</small></span><i>‹</i></a>
          <a href="#"><span class="quick-icon orange"><svg viewBox="0 0 24 24"><path d="M12 2a10 10 0 1 0 10 10A10 10 0 0 0 12 2Zm1 15h-2v-2h2Zm1.8-6.1-.9.9A3.1 3.1 0 0 0 13 14h-2v-.5a4 4 0 0 1 1.2-2.8l1.2-1.2A1.6 1.6 0 1 0 10.7 8H8.6a3.7 3.7 0 1 1 6.2 2.9Z"/></svg></span><span><b>پشتیبانی</b><small>ثبت و پیگیری درخواست</small></span><i>‹</i></a>
          <a href="#"><span class="quick-icon cyan"><svg viewBox="0 0 24 24"><path d="M3 3v18h18v-2H5V3Zm4 12h3v2H7Zm0-4h5v2H7Zm0-4h10v2H7Zm7 4h3v6h-3Z"/></svg></span><span><b>گزارش مصرف</b><small>بررسی جزئیات مصرف دوره</small></span><i>‹</i></a>
        </div>
      </section>

      <section class="notice">
        <span class="notice-icon"><svg viewBox="0 0 24 24"><path d="M11 8h2v2h-2Zm0 4h2v5h-2Zm1-10a10 10 0 1 0 10 10A10 10 0 0 0 12 2Z"/></svg></span>
        <div><b>یادآوری تمدید سرویس</b><p>برای جلوگیری از قطع سرویس، بهتر است پیش از پایان دوره نسبت به تمدید آن اقدام کنید.</p></div>
        <a href="#">تمدید سرویس</a>
      </section>
    </div>
  </main>
</div>
HTML;
    echo pageEnd();
    exit;
}

function renderErrorPage(int $status, string $message): never
{
    http_response_code($status);
    $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo pageStart((string) $status, 'error-page');
    echo <<<HTML
<main class="error-shell">
  <section class="error-card">
    <a class="brand" href="/panel"><span class="brand-mark"><svg viewBox="0 0 48 48"><path d="M24 5a19 19 0 1 0 19 19A19 19 0 0 0 24 5Zm0 7a12 12 0 0 1 10.4 6H13.6A12 12 0 0 1 24 12Zm0 24a12 12 0 0 1-10.4-6h20.8A12 12 0 0 1 24 36Z"/></svg></span><b>آبان‌نت</b></a>
    <span class="error-code">{$status}</span>
    <h1>خطایی رخ داده است</h1>
    <p>{$safeMessage}</p>
    <a class="primary-button link-button" href="/panel">بازگشت به پنل</a>
  </section>
</main>
HTML;
    echo pageEnd();
    exit;
}

function pageStart(string $title, string $bodyClass = ''): string
{
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeBodyClass = htmlspecialchars($bodyClass, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return <<<HTML
<!doctype html>
<html lang="fa" dir="rtl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#102e50">
  <title>{$safeTitle} | آبان‌نت</title>
  <style>
    :root {
      color-scheme: light;
      font-family: Tahoma, Arial, sans-serif;
      --navy: #102e50;
      --navy-deep: #09213b;
      --blue: #1769e0;
      --blue-hover: #0d57c2;
      --text: #182537;
      --muted: #718096;
      --line: #e5eaf0;
      --surface: #fff;
      --page: #f5f7fa;
      --green: #1ea672;
    }
    * { box-sizing: border-box; }
    html { min-height: 100%; }
    body { margin: 0; min-height: 100vh; color: var(--text); background: var(--page); }
    a { color: inherit; }
    button, input { font: inherit; }
    svg { display: block; width: 1em; height: 1em; fill: currentColor; }
    .eyebrow { color: var(--blue); font-size: 12px; font-weight: 700; letter-spacing: .02em; }
    .brand { display: inline-flex; align-items: center; gap: 11px; text-decoration: none; }
    .brand-mark { display: inline-grid; width: 45px; height: 45px; place-items: center; color: #fff; background: var(--blue); border-radius: 14px; box-shadow: 0 8px 24px rgba(23, 105, 224, .24); }
    .brand-mark svg { width: 29px; height: 29px; }
    .brand b { display: block; font-size: 20px; }
    .brand small { display: block; margin-top: 4px; font-size: 10px; font-weight: 400; opacity: .68; }
    .primary-button {
      display: inline-flex; min-height: 52px; align-items: center; justify-content: center; gap: 10px;
      border: 0; border-radius: 10px; color: #fff; background: var(--blue); padding: 12px 20px;
      font-weight: 700; cursor: pointer; transition: background .18s, transform .18s, box-shadow .18s;
    }
    .primary-button:hover { background: var(--blue-hover); box-shadow: 0 8px 22px rgba(23, 105, 224, .23); transform: translateY(-1px); }
    .primary-button svg { width: 21px; height: 21px; }
    .link-button { text-decoration: none; }

    /* Login */
    .login-shell {
      min-height: 100vh; display: grid; place-items: center; padding: 34px 24px 62px;
      background:
        radial-gradient(circle at 10% 15%, rgba(28, 111, 227, .08), transparent 28%),
        radial-gradient(circle at 87% 83%, rgba(16, 46, 80, .08), transparent 26%),
        #f4f7fb;
    }
    .login-card {
      display: grid; grid-template-columns: .95fr 1.05fr; width: min(100%, 990px); min-height: 630px;
      overflow: hidden; background: var(--surface); border: 1px solid rgba(217, 225, 235, .8);
      border-radius: 22px; box-shadow: 0 28px 75px rgba(22, 48, 78, .12);
    }
    .login-brand {
      position: relative; display: flex; flex-direction: column; overflow: hidden; padding: 46px;
      color: #fff; background: linear-gradient(150deg, #0d2a49 0%, #123f6e 58%, #1765ab 100%);
    }
    .login-brand::before, .login-brand::after {
      content: ""; position: absolute; border: 1px solid rgba(255, 255, 255, .08); border-radius: 50%;
    }
    .login-brand::before { width: 390px; height: 390px; left: -230px; bottom: -160px; }
    .login-brand::after { width: 270px; height: 270px; right: -160px; top: 80px; box-shadow: 0 0 0 70px rgba(255,255,255,.025), 0 0 0 140px rgba(255,255,255,.018); }
    .brand-light { position: relative; z-index: 1; color: #fff; }
    .brand-light .brand-mark { background: rgba(255, 255, 255, .13); box-shadow: none; backdrop-filter: blur(8px); }
    .brand-message { position: relative; z-index: 1; margin: auto 0; max-width: 330px; }
    .brand-message .eyebrow { color: #78b8ff; }
    .brand-message h2 { margin: 12px 0 16px; font-size: clamp(27px, 3vw, 37px); line-height: 1.55; letter-spacing: -.02em; }
    .brand-message p { margin: 0; color: rgba(255,255,255,.72); font-size: 14px; line-height: 2; }
    .brand-support { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; padding-top: 24px; border-top: 1px solid rgba(255,255,255,.12); color: rgba(255,255,255,.67); font-size: 12px; }
    .brand-support b { color: #fff; font-size: 14px; }
    .login-form-wrap { display: flex; flex-direction: column; justify-content: center; padding: 52px 66px; }
    .mobile-brand { display: none; align-items: center; gap: 10px; margin-bottom: 38px; color: var(--navy); }
    .mobile-brand b { font-size: 21px; }
    .form-heading { margin-bottom: 24px; }
    .form-heading h1 { margin: 8px 0 9px; color: var(--navy-deep); font-size: 27px; }
    .form-heading p { margin: 0; color: var(--muted); font-size: 13px; line-height: 1.8; }
    label { display: block; margin: 15px 0 7px; color: #334155; font-size: 13px; font-weight: 700; }
    .label-row { display: flex; align-items: center; justify-content: space-between; margin-top: 15px; }
    .label-row label { margin-top: 0; }
    .subtle-link { color: var(--blue); font-size: 11px; text-decoration: none; }
    .input-wrap { position: relative; }
    .input-wrap input {
      width: 100%; min-height: 48px; padding: 11px 44px 11px 13px; color: var(--text);
      background: #fbfcfe; border: 1px solid #d8e0e9; border-radius: 9px; outline: 0;
      font-size: 13px; transition: border .18s, box-shadow .18s, background .18s;
    }
    .input-wrap input::placeholder { color: #a5afbd; }
    .input-wrap input:focus { background: #fff; border-color: var(--blue); box-shadow: 0 0 0 3px rgba(23, 105, 224, .1); }
    .field-icon { position: absolute; z-index: 1; top: 50%; right: 14px; color: #8a98aa; transform: translateY(-50%); pointer-events: none; }
    .field-icon svg { width: 19px; height: 19px; }
    .captcha-row { display: grid; grid-template-columns: 1fr 145px; gap: 10px; align-items: stretch; }
    .captcha-row .input-wrap input { height: 54px; padding-right: 13px; text-align: center; direction: ltr; letter-spacing: .15em; }
    .captcha-image { display: block; overflow: hidden; height: 54px; border: 1px solid #d8e0e9; border-radius: 9px; background: #f1f5fa; }
    .captcha-image img { width: 100%; height: 100%; object-fit: cover; }
    .login-form-wrap form > .primary-button { width: 100%; margin-top: 24px; }
    .alert { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 6px; padding: 11px 12px; color: #9b2635; background: #fff1f3; border: 1px solid #ffdce2; border-radius: 9px; font-size: 12px; line-height: 1.7; }
    .alert-icon { display: inline-grid; flex: 0 0 20px; height: 20px; place-items: center; margin-top: 1px; color: #fff; background: #c73549; border-radius: 50%; font-weight: 700; }
    .privacy-note { margin: 19px 0 0; color: #8a96a6; text-align: center; font-size: 10px; line-height: 1.8; }
    .privacy-note a { color: #687789; text-decoration: underline; text-underline-offset: 2px; }
    .page-footer { position: fixed; bottom: 18px; color: #8a96a6; font-size: 10px; text-align: center; }

    /* Dashboard */
    .dashboard-layout { display: grid; grid-template-columns: 255px minmax(0, 1fr); min-height: 100vh; }
    .sidebar { position: sticky; top: 0; display: flex; height: 100vh; flex-direction: column; padding: 28px 20px 20px; color: #dbe7f4; background: var(--navy-deep); }
    .brand-dark { padding: 0 8px 27px; color: #fff; }
    .brand-dark .brand-mark { background: var(--blue); }
    .side-nav { display: grid; gap: 5px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,.08); }
    .side-nav a { display: flex; align-items: center; gap: 13px; min-height: 46px; padding: 0 14px; color: #aebed0; border-radius: 9px; font-size: 13px; text-decoration: none; transition: .18s; }
    .side-nav a:hover { color: #fff; background: rgba(255,255,255,.06); }
    .side-nav a.active { color: #fff; background: var(--blue); box-shadow: 0 8px 22px rgba(0,0,0,.14); }
    .side-nav svg { width: 19px; height: 19px; }
    .sidebar-help { display: flex; flex-direction: column; align-items: center; margin-top: auto; padding: 18px 14px; background: rgba(255,255,255,.055); border: 1px solid rgba(255,255,255,.06); border-radius: 12px; text-align: center; }
    .help-icon { display: grid; width: 34px; height: 34px; place-items: center; margin-bottom: 10px; color: #fff; background: rgba(255,255,255,.1); border-radius: 50%; font-weight: 700; }
    .sidebar-help b { font-size: 12px; }
    .sidebar-help small { margin: 8px 0 12px; color: #8fa5bc; font-size: 9px; line-height: 1.8; }
    .sidebar-help a { color: #76b6ff; font-size: 10px; font-weight: 700; text-decoration: none; }
    .logout-form { margin-top: 12px; }
    .logout-form button { display: flex; width: 100%; align-items: center; gap: 12px; padding: 10px 14px; color: #94a9bf; background: transparent; border: 0; border-radius: 8px; font-size: 11px; cursor: pointer; }
    .logout-form button:hover { color: #fff; background: rgba(255,255,255,.05); }
    .logout-form svg { width: 18px; height: 18px; }
    .dashboard-main { min-width: 0; }
    .topbar { display: flex; height: 76px; align-items: center; justify-content: flex-end; padding: 0 38px; background: #fff; border-bottom: 1px solid var(--line); }
    .mobile-logo { display: none; align-items: center; gap: 9px; color: var(--navy); }
    .mobile-logo .brand-mark { width: 36px; height: 36px; border-radius: 10px; }
    .mobile-logo .brand-mark svg { width: 23px; height: 23px; }
    .top-actions { display: flex; align-items: center; gap: 21px; }
    .icon-button { position: relative; display: grid; width: 38px; height: 38px; place-items: center; color: #6b7b8e; background: #f5f7fa; border: 0; border-radius: 10px; cursor: pointer; }
    .icon-button svg { width: 18px; height: 18px; }
    .notification-dot { position: absolute; top: 8px; right: 8px; width: 6px; height: 6px; background: #eb4c5c; border: 1px solid #fff; border-radius: 50%; }
    .user-menu { display: flex; align-items: center; gap: 10px; padding-right: 20px; border-right: 1px solid var(--line); }
    .avatar { display: grid; width: 39px; height: 39px; place-items: center; color: var(--blue); background: #eaf2ff; border-radius: 12px; font-size: 14px; font-weight: 700; }
    .user-menu small, .user-menu b { display: block; }
    .user-menu small { margin-bottom: 4px; color: #94a0af; font-size: 9px; }
    .user-menu b { color: #26374a; font-size: 11px; }
    .dashboard-content { width: min(100%, 1180px); margin: 0 auto; padding: 34px 38px 50px; }
    .welcome-row { display: flex; align-items: center; justify-content: space-between; gap: 24px; margin-bottom: 27px; }
    .welcome-row .eyebrow { margin: 0 0 7px; }
    .welcome-row h1 { margin: 0 0 8px; color: var(--navy); font-size: 23px; }
    .welcome-row > div > p:last-child { margin: 0; color: var(--muted); font-size: 11px; }
    .status-pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; color: #167d58; background: #eaf9f3; border: 1px solid #d4f1e5; border-radius: 999px; font-size: 10px; font-weight: 700; white-space: nowrap; }
    .status-pill i { width: 7px; height: 7px; background: var(--green); border-radius: 50%; box-shadow: 0 0 0 4px rgba(30,166,114,.12); }
    .summary-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
    .service-card, .usage-card { padding: 23px; background: #fff; border: 1px solid var(--line); border-radius: 14px; box-shadow: 0 5px 18px rgba(25, 49, 76, .035); }
    .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 18px; }
    .card-label { color: #8996a7; font-size: 10px; }
    .card-head h2 { margin: 7px 0 0; color: var(--navy); font-size: 17px; }
    .card-head h2 strong { font-size: 23px; }
    .round-icon { display: grid; flex: 0 0 43px; height: 43px; place-items: center; border-radius: 12px; }
    .round-icon svg { width: 21px; height: 21px; }
    .round-icon.blue { color: var(--blue); background: #eaf2ff; }
    .round-icon.green { color: var(--green); background: #e8f8f2; }
    .service-meta { display: grid; grid-template-columns: 1fr 1fr; margin: 21px 0 15px; padding: 15px 0; border-top: 1px solid var(--line); border-bottom: 1px solid var(--line); }
    .service-meta > div + div { padding-right: 20px; border-right: 1px solid var(--line); }
    .service-meta small, .service-meta b { display: block; }
    .service-meta small { margin-bottom: 7px; color: #919dac; font-size: 9px; }
    .service-meta b { color: #34465a; font-size: 11px; }
    .text-link { display: flex; align-items: center; justify-content: space-between; color: var(--blue); font-size: 10px; font-weight: 700; text-decoration: none; }
    .text-link span { font-size: 17px; }
    .progress { height: 7px; overflow: hidden; margin: 27px 0 10px; background: #edf1f5; border-radius: 999px; direction: ltr; }
    .progress span { display: block; height: 100%; background: linear-gradient(90deg, #27b17d, #58c99d); border-radius: inherit; }
    .usage-meta { display: flex; justify-content: space-between; margin-bottom: 22px; color: #8a96a6; font-size: 9px; }
    .quick-section { margin-top: 29px; }
    .section-heading h2 { margin: 0 0 5px; color: var(--navy); font-size: 16px; }
    .section-heading p { margin: 0; color: var(--muted); font-size: 10px; }
    .quick-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-top: 15px; }
    .quick-grid > a { display: flex; align-items: center; gap: 12px; min-width: 0; padding: 17px; background: #fff; border: 1px solid var(--line); border-radius: 12px; text-decoration: none; transition: transform .18s, box-shadow .18s, border .18s; }
    .quick-grid > a:hover { border-color: #ccdaeb; box-shadow: 0 9px 25px rgba(25,49,76,.07); transform: translateY(-2px); }
    .quick-icon { display: grid; flex: 0 0 39px; height: 39px; place-items: center; border-radius: 10px; }
    .quick-icon svg { width: 19px; height: 19px; }
    .quick-icon.purple { color: #7e58d7; background: #f1edfc; }
    .quick-icon.orange { color: #e98537; background: #fff1e6; }
    .quick-icon.cyan { color: #1c93aa; background: #e7f7fa; }
    .quick-grid b, .quick-grid small { display: block; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .quick-grid b { margin-bottom: 5px; color: #33465a; font-size: 11px; }
    .quick-grid small { color: #929eac; font-size: 8px; }
    .quick-grid i { margin-right: auto; color: #9eabb9; font-size: 22px; font-style: normal; }
    .notice { display: flex; align-items: center; gap: 14px; margin-top: 24px; padding: 17px 20px; color: #5f4a17; background: #fff9e9; border: 1px solid #f7eac3; border-radius: 12px; }
    .notice-icon { display: grid; flex: 0 0 35px; height: 35px; place-items: center; color: #d79021; background: #ffefc4; border-radius: 9px; }
    .notice-icon svg { width: 18px; height: 18px; }
    .notice b { display: block; margin-bottom: 4px; font-size: 10px; }
    .notice p { margin: 0; color: #8b7543; font-size: 9px; line-height: 1.7; }
    .notice a { margin-right: auto; padding: 8px 13px; color: #8a5c0c; background: #fff; border: 1px solid #eedcae; border-radius: 8px; font-size: 9px; font-weight: 700; text-decoration: none; white-space: nowrap; }

    /* Error */
    .error-shell { min-height: 100vh; display: grid; place-items: center; padding: 24px; background: #f4f7fb; }
    .error-card { width: min(100%, 430px); padding: 42px; background: #fff; border: 1px solid var(--line); border-radius: 18px; box-shadow: 0 22px 55px rgba(22,48,78,.1); text-align: center; }
    .error-card .brand { color: var(--navy); }
    .error-code { display: block; margin: 32px 0 3px; color: var(--blue); font-size: 64px; font-weight: 800; }
    .error-card h1 { margin: 0; color: var(--navy); font-size: 23px; }
    .error-card p { margin: 13px 0 25px; color: var(--muted); font-size: 13px; line-height: 1.8; }

    @media (max-width: 900px) {
      .login-card { grid-template-columns: 1fr; width: min(100%, 510px); min-height: auto; }
      .login-brand { display: none; }
      .login-form-wrap { padding: 45px 48px; }
      .mobile-brand { display: flex; }
      .dashboard-layout { grid-template-columns: 1fr; }
      .sidebar { display: none; }
      .topbar { justify-content: space-between; padding: 0 24px; }
      .mobile-logo { display: flex; }
      .dashboard-content { padding: 28px 24px 44px; }
    }
    @media (max-width: 650px) {
      .login-shell { padding: 0; place-items: stretch; background: #fff; }
      .login-card { width: 100%; min-height: 100vh; border: 0; border-radius: 0; box-shadow: none; }
      .login-form-wrap { justify-content: flex-start; padding: 34px 24px; }
      .page-footer { display: none; }
      .captcha-row { grid-template-columns: 1fr 130px; }
      .topbar { height: 66px; padding: 0 17px; }
      .user-menu > span:not(.avatar) { display: none; }
      .user-menu { padding-right: 13px; }
      .dashboard-content { padding: 23px 17px 35px; }
      .welcome-row { align-items: flex-start; flex-direction: column; gap: 14px; }
      .summary-grid { grid-template-columns: 1fr; }
      .quick-grid { grid-template-columns: 1fr; }
      .notice { align-items: flex-start; flex-wrap: wrap; }
      .notice a { margin-right: 49px; }
    }
  </style>
</head>
<body class="{$safeBodyClass}">
HTML;
}

function pageEnd(): string
{
    return "</body>\n</html>\n";
}
