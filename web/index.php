<?php

declare(strict_types=1);

require_once __DIR__ . '/collector.php';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set(
    'session.cookie_secure',
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0',
);
session_name('OCR_PANEL_COLLECTOR');
session_start();

sendSecurityHeaders();

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = scalarRequestValue('action', 32) ?? '';

try {
    $config = collectorConfig();

    if ($method === 'GET' && $action === 'captcha') {
        servePendingCaptcha();
    }

    if ($method === 'POST') {
        requireLocalCsrf();

        if ($action === 'submit') {
            $answer = normalizeAnswer(scalarPostValue('label', 16) ?? '');
            $challengeId = scalarPostValue('challenge_id', 64) ?? '';
            $result = validatePendingChallenge($config, $challengeId, $answer);
            $outcome = ($result['rejected'] ?? false)
                ? 'rejected'
                : ($result['stored'] ? 'saved' : 'duplicate');
            $_SESSION['saved_this_session'] = (int) ($_SESSION['saved_this_session'] ?? 0)
                + ($result['stored'] ? 1 : 0);
            respondWithNextChallenge($config, $outcome);
        }

        if ($action === 'next') {
            unset($_SESSION['panel_challenge']);
            respondWithNextChallenge($config, 'skipped');
        }

        throw new CollectorError('Unknown action.', 404);
    }

    if ($method !== 'GET') {
        header('Allow: GET, POST');
        throw new CollectorError('Method not allowed.', 405);
    }

    $challenge = null;
    $error = null;
    try {
        $challenge = ensurePanelChallenge($config);
    } catch (CollectorError $problem) {
        $error = $problem->getMessage();
    }
    renderCollectorPage(
        $challenge,
        $error,
        scalarRequestValue('status', 24) ?? '',
    );
} catch (CollectorError $problem) {
    if (wantsJson()) {
        $payload = ['error' => $problem->getMessage()];
        if (!challengeIsUsable($_SESSION['panel_challenge'] ?? null)) {
            $payload['challenge'] = null;
        }
        jsonResponse($payload, $problem->status);
    }

    http_response_code($problem->status);
    $fallbackChallenge = challengeIsUsable($_SESSION['panel_challenge'] ?? null)
        ? $_SESSION['panel_challenge']
        : null;
    renderCollectorPage($fallbackChallenge, $problem->getMessage(), '');
} catch (Throwable $problem) {
    error_log((string) $problem);
    if (wantsJson()) {
        jsonResponse(['error' => 'Unexpected server error. Check the PHP error log.'], 500);
    }

    http_response_code(500);
    renderCollectorPage(null, 'Unexpected server error. Check the PHP error log.', '');
}

function respondWithNextChallenge(array $config, string $outcome): never
{
    $challenge = null;
    $warning = null;
    try {
        $challenge = ensurePanelChallenge($config, true);
    } catch (CollectorError $problem) {
        $warning = $problem->getMessage();
    }

    if (wantsJson()) {
        jsonResponse([
            'outcome' => $outcome,
            'message' => outcomeMessage($outcome),
            'saved_this_session' => (int) ($_SESSION['saved_this_session'] ?? 0),
            'challenge' => $challenge === null ? null : challengePayload($challenge),
            'warning' => $warning,
        ]);
    }

    redirect('./?status=' . rawurlencode($outcome));
}

function renderCollectorPage(?array $challenge, ?string $error, string $status): never
{
    $notices = [
        'saved' => 'Correct — the captcha was saved.',
        'duplicate' => 'Correct — this exact image was already saved.',
        'rejected' => 'That answer was rejected. Here is a new captcha.',
        'skipped' => 'A new captcha is ready.',
    ];
    $notice = $error ?? ($notices[$status] ?? '');
    $noticeClass = $error === null ? '' : ' error';
    $saved = (int) ($_SESSION['saved_this_session'] ?? 0);
    $csrf = localCsrfToken();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#0d1726">
    <title>Panel captcha collector</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="stylesheet" href="app.css">
</head>
<body>
<main class="shell">
    <header class="topbar">
        <div>
            <p class="eyebrow">OCR dataset</p>
            <h1>Captcha collector</h1>
        </div>
        <div class="counter" aria-live="polite">
            <strong id="saved-count"><?= number_format($saved) ?></strong>
            <span>saved this session</span>
        </div>
    </header>

    <p
        id="status"
        class="status<?= $noticeClass ?>"
        role="status"
        aria-live="polite"
        <?= $notice === '' ? 'hidden' : '' ?>
    ><?= h($notice) ?></p>

    <section class="card" id="collector-card">
        <?php if ($challenge === null): ?>
            <div class="unavailable">
                <h2>No captcha is available</h2>
                <p>The panel could not provide a usable challenge. Check the message above and try again.</p>
                <a class="primary button-link" href="./">Try again</a>
            </div>
        <?php else: ?>
            <div class="image-stage">
                <img
                    id="captcha-image"
                    src="<?= h(challengeImageUrl($challenge)) ?>"
                    width="220"
                    height="72"
                    alt="Security code to transcribe"
                >
            </div>

            <form method="post" action="./" id="answer-form">
                <input type="hidden" name="action" value="submit">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <input type="hidden" name="challenge_id" value="<?= h($challenge['id']) ?>">
                <label for="label">Number in the image</label>
                <div class="entry-row">
                    <input
                        id="label"
                        name="label"
                        type="text"
                        inputmode="numeric"
                        enterkeyhint="go"
                        autocomplete="off"
                        autocapitalize="off"
                        spellcheck="false"
                        maxlength="16"
                        pattern="[0-9۰-۹٠-٩]+"
                        placeholder="Enter captcha"
                        required
                        autofocus
                    >
                    <button class="primary" type="submit" id="submit-button">Check &amp; next</button>
                </div>
            </form>

            <form method="post" action="./" id="next-form">
                <input type="hidden" name="action" value="next">
                <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                <button class="secondary" type="submit">New captcha</button>
            </form>
        <?php endif; ?>
    </section>

    <p class="privacy">
        Answers are stored only after the panel confirms a successful login.
    </p>
</main>
<script src="app.js" defer></script>
</body>
</html>
<?php
    exit;
}
