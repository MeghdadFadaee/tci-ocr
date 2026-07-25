<?php

declare(strict_types=1);

const CSV_COLUMNS = ['filename', 'label', 'batch_id', 'verified', 'hash'];
const SCHEMA_VERSION = '1';
const IMPORT_BATCH_SIZE = 20000;

final class AppError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 400,
    ) {
        parent::__construct($message);
    }
}

function config(): array
{
    $configuredDataset = $_SERVER['OCR_DATASET_DIR'] ?? getenv('OCR_DATASET_DIR') ?: '';
    $dataset = $configuredDataset !== ''
        ? $configuredDataset
        : dirname(__DIR__) . DIRECTORY_SEPARATOR . 'dataset';
    $dataset = rtrim($dataset, DIRECTORY_SEPARATOR);

    $configuredState = $_SERVER['OCR_STATE_PATH'] ?? getenv('OCR_STATE_PATH') ?: '';

    return [
        'dataset' => $dataset,
        'csv' => $dataset . DIRECTORY_SEPARATOR . 'labels.csv',
        'state' => $configuredState !== ''
            ? $configuredState
            : $dataset . DIRECTORY_SEPARATOR . 'verification.sqlite',
    ];
}

function h(string|int|float|null $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function jsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    exit;
}

function redirect(string $location): never
{
    header('Location: ' . $location, true, 303);
    exit;
}

function requestAction(): string
{
    return (string) ($_POST['action'] ?? $_GET['action'] ?? '');
}

function sourceStats(string $path): array
{
    clearstatcache(true, $path);
    $stats = @stat($path);
    if ($stats === false) {
        throw new AppError("Dataset CSV is not readable: {$path}", 500);
    }

    return [
        'size' => (string) $stats['size'],
        'mtime' => (string) $stats['mtime'],
        'inode' => (string) $stats['ino'],
    ];
}

function openDatabase(array $config, bool $create = false): PDO
{
    $state = $config['state'];
    if (!$create && !is_file($state)) {
        throw new AppError('The verifier has not been initialized.', 503);
    }

    if ($create) {
        $directory = dirname($state);
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new AppError("Cannot create the state directory: {$directory}", 500);
        }
    }

    try {
        $db = new PDO('sqlite:' . $state, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA busy_timeout = 5000');
        return $db;
    } catch (PDOException $error) {
        throw new AppError('Cannot open SQLite state: ' . $error->getMessage(), 500);
    }
}

function createSchema(PDO $db): void
{
    $db->exec(
        'CREATE TABLE IF NOT EXISTS metadata (
            key TEXT PRIMARY KEY,
            value TEXT NOT NULL
        )'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS labels (
            id INTEGER PRIMARY KEY,
            filename TEXT NOT NULL,
            label TEXT NOT NULL,
            batch_id TEXT NOT NULL,
            verified INTEGER NOT NULL CHECK (verified IN (0, 1)),
            hash TEXT NOT NULL,
            dirty INTEGER NOT NULL DEFAULT 0 CHECK (dirty IN (0, 1))
        )'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS labels_verified_id
         ON labels (verified, id)'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS labels_dirty
         ON labels (dirty) WHERE dirty = 1'
    );
    $db->exec(
        'CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            row_id INTEGER NOT NULL,
            old_label TEXT NOT NULL,
            old_verified INTEGER NOT NULL,
            new_label TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (row_id) REFERENCES labels (id)
        )'
    );
}

function metadata(PDO $db, string $key, ?string $default = null): ?string
{
    $statement = $db->prepare('SELECT value FROM metadata WHERE key = ?');
    $statement->execute([$key]);
    $value = $statement->fetchColumn();
    return $value === false ? $default : (string) $value;
}

function setMetadata(PDO $db, string $key, string|int $value): void
{
    $statement = $db->prepare(
        'INSERT INTO metadata (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
    $statement->execute([$key, (string) $value]);
}

function acquireLock(array $config)
{
    $path = $config['state'] . '.lock';
    $directory = dirname($path);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new AppError("Cannot create the state directory: {$directory}", 500);
    }
    $handle = @fopen($path, 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new AppError('Could not lock the verifier state. Try again.', 503);
    }
    return $handle;
}

function releaseLock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function beginImmediate(PDO $db): void
{
    $db->exec('BEGIN IMMEDIATE');
}

function verifySourceUnchanged(PDO $db, array $config, bool $allowSyncRecovery = false): void
{
    $current = sourceStats($config['csv']);
    $expectedSize = metadata($db, 'source_size');
    $expectedMtime = metadata($db, 'source_mtime');
    $expectedInode = metadata($db, 'source_inode');
    $recovering = metadata($db, 'sync_in_progress', '0') === '1';

    if (
        (
            $current['size'] !== $expectedSize
            || $current['mtime'] !== $expectedMtime
            || $current['inode'] !== $expectedInode
        )
        && !($allowSyncRecovery && $recovering)
    ) {
        throw new AppError(
            'labels.csv changed outside this verifier. Resolve the conflict before continuing.',
            409,
        );
    }
}

function initializeBatch(array $config): array
{
    if (!extension_loaded('pdo_sqlite')) {
        throw new AppError('The PDO SQLite PHP extension is required.', 500);
    }
    if (!is_file($config['csv'])) {
        throw new AppError("Dataset CSV was not found: {$config['csv']}", 500);
    }

    $lock = acquireLock($config);
    try {
        $db = openDatabase($config, true);
        createSchema($db);
        $status = metadata($db, 'status');

        if ($status === 'ready') {
            return [
                'done' => true,
                'rows' => (int) metadata($db, 'total_count', '0'),
                'percent' => 100,
            ];
        }

        $source = @fopen($config['csv'], 'rb');
        if ($source === false) {
            throw new AppError("Cannot open dataset CSV: {$config['csv']}", 500);
        }

        try {
            if ($status === null) {
                $header = fgetcsv($source, null, ',', '"', '');
                if ($header !== CSV_COLUMNS) {
                    throw new AppError(
                        'CSV columns are ' . json_encode($header) .
                        '; expected ' . json_encode(CSV_COLUMNS) . '.',
                        422,
                    );
                }

                $stats = sourceStats($config['csv']);
                beginImmediate($db);
                setMetadata($db, 'schema_version', SCHEMA_VERSION);
                setMetadata($db, 'status', 'importing');
                setMetadata($db, 'source_size', $stats['size']);
                setMetadata($db, 'source_mtime', $stats['mtime']);
                setMetadata($db, 'source_inode', $stats['inode']);
                setMetadata($db, 'import_offset', (string) ftell($source));
                setMetadata($db, 'imported_count', '0');
                setMetadata($db, 'verified_count', '0');
                setMetadata($db, 'pending_count', '0');
                setMetadata($db, 'revision', '0');
                setMetadata($db, 'cursor_after_id', '-1');
                setMetadata($db, 'sync_in_progress', '0');
                $db->exec('COMMIT');
            } else {
                verifySourceUnchanged($db, $config);
                $offset = (int) metadata($db, 'import_offset', '0');
                if (fseek($source, $offset) !== 0) {
                    throw new AppError('Could not resume the CSV import.', 500);
                }
            }

            $insert = $db->prepare(
                'INSERT INTO labels
                 (id, filename, label, batch_id, verified, hash, dirty)
                 VALUES (?, ?, ?, ?, ?, ?, 0)'
            );
            $imported = (int) metadata($db, 'imported_count', '0');
            $verified = (int) metadata($db, 'verified_count', '0');
            $processed = 0;

            beginImmediate($db);
            while ($processed < IMPORT_BATCH_SIZE && ($row = fgetcsv($source, null, ',', '"', '')) !== false) {
                if (count($row) !== count(CSV_COLUMNS)) {
                    throw new AppError('Malformed CSV row ' . ($imported + 2) . '.', 422);
                }
                [$filename, $label, $batchId, $isVerified, $hash] = $row;
                if ($filename === '') {
                    throw new AppError('CSV row ' . ($imported + 2) . ' has an empty filename.', 422);
                }
                if ($isVerified !== '0' && $isVerified !== '1') {
                    throw new AppError(
                        'CSV row ' . ($imported + 2) . ' has an invalid verified value.',
                        422,
                    );
                }

                $insert->execute([
                    $imported,
                    $filename,
                    $label,
                    $batchId,
                    (int) $isVerified,
                    $hash,
                ]);
                $verified += (int) $isVerified;
                $imported++;
                $processed++;
            }

            $offset = (int) ftell($source);
            setMetadata($db, 'import_offset', $offset);
            setMetadata($db, 'imported_count', $imported);
            setMetadata($db, 'verified_count', $verified);

            $done = feof($source);
            if ($done) {
                setMetadata($db, 'status', 'ready');
                setMetadata($db, 'total_count', $imported);
            }
            $db->exec('COMMIT');

            $size = max(1, (int) metadata($db, 'source_size', '1'));
            return [
                'done' => $done,
                'rows' => $imported,
                'verified' => $verified,
                'percent' => $done ? 100 : min(99.9, round($offset * 100 / $size, 1)),
            ];
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        } finally {
            fclose($source);
        }
    } finally {
        releaseLock($lock);
    }
}

function restartImport(array $config): void
{
    $lock = acquireLock($config);
    try {
        if (is_file($config['state'])) {
            $db = openDatabase($config);
            $status = metadata($db, 'status');
            $pending = (int) metadata($db, 'pending_count', '0');
            $db = null;
            if ($status === 'ready') {
                $reason = $pending > 0
                    ? 'It also contains unsynced labels.'
                    : 'Restart is only available for an incomplete import.';
                throw new AppError('Cannot discard initialized state. ' . $reason, 409);
            }
        }

        foreach ([$config['state'], $config['state'] . '-wal', $config['state'] . '-shm'] as $path) {
            if (is_file($path) && !@unlink($path)) {
                throw new AppError("Cannot remove incomplete state: {$path}", 500);
            }
        }
    } finally {
        releaseLock($lock);
    }
}

function normalizeLabel(string $label): string
{
    $normalized = strtr(trim($label), [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
    ]);
    if ($normalized === '' || strlen($normalized) > 64 || preg_match('/^[0-9]+$/D', $normalized) !== 1) {
        throw new AppError('Enter digits only (ASCII, Persian, or Arabic).', 422);
    }
    return $normalized;
}

function currentRow(PDO $db): ?array
{
    $after = (int) metadata($db, 'cursor_after_id', '-1');
    $statement = $db->prepare(
        'SELECT * FROM labels
         WHERE verified = 0 AND id > ?
         ORDER BY id LIMIT 1'
    );
    $statement->execute([$after]);
    $row = $statement->fetch();
    if ($row !== false) {
        return $row;
    }

    $row = $db->query(
        'SELECT * FROM labels WHERE verified = 0 ORDER BY id LIMIT 1'
    )->fetch();
    return $row === false ? null : $row;
}

function nextRowAfter(PDO $db, int $id): ?array
{
    $statement = $db->prepare(
        'SELECT * FROM labels
         WHERE verified = 0 AND id > ?
         ORDER BY id LIMIT 1'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    if ($row !== false) {
        return $row;
    }

    $statement = $db->prepare(
        'SELECT * FROM labels
         WHERE verified = 0 AND id <> ?
         ORDER BY id LIMIT 1'
    );
    $statement->execute([$id]);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function rowById(PDO $db, int $id): array
{
    $statement = $db->prepare('SELECT * FROM labels WHERE id = ?');
    $statement->execute([$id]);
    $row = $statement->fetch();
    if ($row === false) {
        throw new AppError('Dataset row was not found.', 404);
    }
    return $row;
}

function requireReady(PDO $db): void
{
    if (metadata($db, 'status') !== 'ready') {
        throw new AppError('Dataset initialization is not complete.', 503);
    }
    if (metadata($db, 'schema_version') !== SCHEMA_VERSION) {
        throw new AppError('The verifier state was created by an incompatible version.', 409);
    }
}

function requireRevision(PDO $db, int $submitted): int
{
    $current = (int) metadata($db, 'revision', '0');
    if ($submitted !== $current) {
        throw new AppError('This page is stale; it has been refreshed without applying the old action.', 409);
    }
    return $current;
}

function saveLabel(array $config): string
{
    $id = filter_input(INPUT_POST, 'row_id', FILTER_VALIDATE_INT);
    $revision = filter_input(INPUT_POST, 'revision', FILTER_VALIDATE_INT);
    $mode = ($_POST['mode'] ?? 'queue') === 'edit' ? 'edit' : 'queue';
    if ($id === false || $id === null || $revision === false || $revision === null) {
        throw new AppError('Invalid row or revision.', 422);
    }
    $label = normalizeLabel((string) ($_POST['label'] ?? ''));

    $lock = acquireLock($config);
    try {
        $db = openDatabase($config);
        requireReady($db);
        verifySourceUnchanged($db, $config);
        beginImmediate($db);
        try {
            $currentRevision = requireRevision($db, $revision);
            $row = rowById($db, $id);
            if ($mode === 'queue') {
                $current = currentRow($db);
                if ($current === null || (int) $current['id'] !== $id) {
                    throw new AppError('The verification queue has already advanced.', 409);
                }
            }

            $db->prepare(
                'UPDATE labels SET label = ?, verified = 1, dirty = 1 WHERE id = ?'
            )->execute([$label, $id]);
            $db->prepare(
                'INSERT INTO events
                 (row_id, old_label, old_verified, new_label, created_at)
                 VALUES (?, ?, ?, ?, ?)'
            )->execute([
                $id,
                $row['label'],
                $row['verified'],
                $label,
                gmdate('c'),
            ]);

            if ((int) $row['verified'] === 0) {
                setMetadata(
                    $db,
                    'verified_count',
                    (int) metadata($db, 'verified_count', '0') + 1,
                );
            }
            if ((int) $row['dirty'] === 0) {
                setMetadata(
                    $db,
                    'pending_count',
                    (int) metadata($db, 'pending_count', '0') + 1,
                );
            }
            if ($mode === 'queue') {
                setMetadata($db, 'cursor_after_id', $id);
            }
            setMetadata($db, 'revision', $currentRevision + 1);
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    } finally {
        releaseLock($lock);
    }

    return $mode === 'edit' ? 'corrected' : 'saved';
}

function skipRow(array $config): void
{
    $id = filter_input(INPUT_POST, 'row_id', FILTER_VALIDATE_INT);
    $revision = filter_input(INPUT_POST, 'revision', FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $revision === false || $revision === null) {
        throw new AppError('Invalid row or revision.', 422);
    }

    $lock = acquireLock($config);
    try {
        $db = openDatabase($config);
        requireReady($db);
        verifySourceUnchanged($db, $config);
        beginImmediate($db);
        try {
            $currentRevision = requireRevision($db, $revision);
            $current = currentRow($db);
            if ($current === null || (int) $current['id'] !== $id) {
                throw new AppError('The verification queue has already advanced.', 409);
            }
            setMetadata($db, 'cursor_after_id', $id);
            setMetadata($db, 'revision', $currentRevision + 1);
            $db->exec('COMMIT');
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }
    } finally {
        releaseLock($lock);
    }
}

function syncCsv(array $config): int
{
    $lock = acquireLock($config);
    $temporary = null;
    $replaced = false;
    try {
        $db = openDatabase($config);
        requireReady($db);
        verifySourceUnchanged($db, $config, true);

        beginImmediate($db);
        setMetadata($db, 'sync_in_progress', '1');
        $db->exec('COMMIT');

        $temporary = dirname($config['csv']) . DIRECTORY_SEPARATOR
            . '.' . basename($config['csv']) . '.web-' . getmypid() . '.tmp';
        $output = @fopen($temporary, 'xb');
        if ($output === false) {
            throw new AppError("Cannot create temporary CSV: {$temporary}", 500);
        }

        try {
            if (fputcsv($output, CSV_COLUMNS, ',', '"', '', "\n") === false) {
                throw new AppError('Could not write the CSV header.', 500);
            }
            $rows = $db->query(
                'SELECT filename, label, batch_id, verified, hash
                 FROM labels ORDER BY id'
            );
            $count = 0;
            while ($row = $rows->fetch()) {
                $values = [
                    $row['filename'],
                    $row['label'],
                    $row['batch_id'],
                    (string) $row['verified'],
                    $row['hash'],
                ];
                if (fputcsv($output, $values, ',', '"', '', "\n") === false) {
                    throw new AppError('Could not write labels.csv.', 500);
                }
                $count++;
            }
            if (!fflush($output)) {
                throw new AppError('Could not flush labels.csv.', 500);
            }
            if (function_exists('fsync') && !fsync($output)) {
                throw new AppError('Could not safely flush labels.csv to disk.', 500);
            }
        } finally {
            fclose($output);
        }

        $permissions = @fileperms($config['csv']);
        if ($permissions !== false) {
            @chmod($temporary, $permissions & 0777);
        }
        if (!@rename($temporary, $config['csv'])) {
            throw new AppError('Could not atomically replace labels.csv.', 500);
        }
        $replaced = true;
        $temporary = null;

        $stats = sourceStats($config['csv']);
        beginImmediate($db);
        $db->exec('UPDATE labels SET dirty = 0 WHERE dirty = 1');
        setMetadata($db, 'pending_count', '0');
        setMetadata($db, 'source_size', $stats['size']);
        setMetadata($db, 'source_mtime', $stats['mtime']);
        setMetadata($db, 'source_inode', $stats['inode']);
        setMetadata($db, 'sync_in_progress', '0');
        setMetadata($db, 'revision', (int) metadata($db, 'revision', '0') + 1);
        $db->exec('COMMIT');
        return $count;
    } catch (Throwable $error) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        if (!$replaced && isset($db) && $db instanceof PDO) {
            try {
                beginImmediate($db);
                setMetadata($db, 'sync_in_progress', '0');
                $db->exec('COMMIT');
            } catch (Throwable) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
            }
        }
        throw $error;
    } finally {
        if ($temporary !== null && is_file($temporary)) {
            @unlink($temporary);
        }
        releaseLock($lock);
    }
}

function serveImage(array $config): never
{
    $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
    if ($id === false || $id === null || $id < 0) {
        throw new AppError('Invalid image id.', 404);
    }
    $db = openDatabase($config);
    requireReady($db);
    $row = rowById($db, $id);

    $root = realpath($config['dataset']);
    $image = realpath($config['dataset'] . DIRECTORY_SEPARATOR . $row['filename']);
    if (
        $root === false
        || $image === false
        || !is_file($image)
        || !str_starts_with($image, $root . DIRECTORY_SEPARATOR)
    ) {
        throw new AppError('Image file is missing or outside the dataset.', 404);
    }

    if (!class_exists(finfo::class)) {
        throw new AppError('The PHP Fileinfo extension is required.', 500);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($image);
    $allowed = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/bmp',
        'image/x-ms-bmp',
        'image/webp',
    ];
    if ($mime === false || !in_array($mime, $allowed, true)) {
        throw new AppError('Unsupported image type.', 415);
    }

    $etagValue = preg_match('/^[a-f0-9]{64}$/i', $row['hash']) === 1
        ? strtolower($row['hash'])
        : hash('sha256', $row['filename'] . '|' . filemtime($image) . '|' . filesize($image));
    $etag = '"' . $etagValue . '"';
    if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
        http_response_code(304);
        exit;
    }

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($image));
    header('Cache-Control: private, max-age=31536000, immutable');
    header('ETag: ' . $etag);
    header('X-Content-Type-Options: nosniff');
    readfile($image);
    exit;
}

function recentRows(PDO $db): array
{
    return $db->query(
        'SELECT labels.id, labels.filename, labels.label
         FROM labels
         JOIN (
             SELECT row_id, MAX(id) AS last_event
             FROM events GROUP BY row_id
         ) recent ON recent.row_id = labels.id
         ORDER BY recent.last_event DESC
         LIMIT 8'
    )->fetchAll();
}

function renderHeader(string $title): void
{
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <title><?= h($title) ?> · OCR verifier</title>
    <style>
        :root {
            color-scheme: dark;
            --ink: #f7f6f2;
            --muted: #aaa9a4;
            --panel: rgba(27, 28, 31, .84);
            --panel-solid: #1b1c1f;
            --line: rgba(255, 255, 255, .10);
            --accent: #ffcc66;
            --accent-2: #fe8f62;
            --success: #83dbb5;
            --danger: #ff8d91;
            --shadow: 0 32px 80px rgba(0, 0, 0, .38);
        }
        * { box-sizing: border-box; }
        html { min-height: 100%; background: #101114; }
        body {
            min-height: 100vh;
            margin: 0;
            color: var(--ink);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at 12% 10%, rgba(254, 143, 98, .14), transparent 30rem),
                radial-gradient(circle at 88% 85%, rgba(255, 204, 102, .12), transparent 32rem),
                #101114;
        }
        button, input { font: inherit; }
        button, .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            min-height: 3rem;
            padding: .75rem 1.15rem;
            border: 1px solid var(--line);
            border-radius: .85rem;
            color: var(--ink);
            background: rgba(255,255,255,.06);
            text-decoration: none;
            font-weight: 750;
            cursor: pointer;
            transition: transform .15s ease, border-color .15s ease, background .15s ease;
        }
        button:hover, .button:hover { transform: translateY(-1px); border-color: rgba(255,255,255,.24); }
        button:focus-visible, input:focus-visible, .button:focus-visible { outline: 3px solid rgba(255,204,102,.30); outline-offset: 2px; }
        button.primary {
            border-color: transparent;
            color: #17130c;
            background: linear-gradient(135deg, var(--accent), #f4a95a);
            box-shadow: 0 10px 28px rgba(255, 184, 90, .18);
        }
        button.danger { color: var(--danger); }
        .shell { width: min(1080px, calc(100% - 2rem)); margin: 0 auto; padding: 2rem 0 4rem; }
        .topbar { display: flex; align-items: center; justify-content: space-between; gap: 1rem; margin-bottom: 1.5rem; }
        .brand { display: flex; align-items: center; gap: .8rem; font-weight: 850; letter-spacing: -.02em; }
        .brand-mark { width: .8rem; height: .8rem; border-radius: 50%; background: var(--accent); box-shadow: 0 0 24px var(--accent); }
        .pill {
            display: inline-flex; align-items: center; gap: .4rem; padding: .42rem .7rem;
            border: 1px solid var(--line); border-radius: 999px; color: var(--muted);
            background: rgba(255,255,255,.035); font-size: .83rem; font-weight: 700;
        }
        .panel {
            border: 1px solid var(--line);
            border-radius: 1.4rem;
            background: var(--panel);
            box-shadow: var(--shadow);
            backdrop-filter: blur(18px);
        }
        .setup { max-width: 680px; margin: 10vh auto 0; padding: clamp(1.5rem, 5vw, 3.5rem); }
        .eyebrow { color: var(--accent); font-size: .75rem; font-weight: 850; letter-spacing: .14em; text-transform: uppercase; }
        h1 { margin: .6rem 0 1rem; font-size: clamp(2rem, 6vw, 3.7rem); line-height: .98; letter-spacing: -.055em; }
        h2 { margin: 0; letter-spacing: -.025em; }
        p { color: var(--muted); line-height: 1.65; }
        code { color: var(--accent); }
        .progress-track { height: .65rem; overflow: hidden; border-radius: 999px; background: rgba(255,255,255,.07); }
        .progress-fill { height: 100%; width: 0; border-radius: inherit; background: linear-gradient(90deg, var(--accent-2), var(--accent)); transition: width .25s ease; }
        .setup-actions { display: flex; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
        .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: .8rem; margin-bottom: .8rem; }
        .stat { padding: 1rem 1.1rem; }
        .stat-label { color: var(--muted); font-size: .74rem; font-weight: 800; text-transform: uppercase; letter-spacing: .1em; }
        .stat-value { margin-top: .2rem; font-size: clamp(1.25rem, 3vw, 1.75rem); font-weight: 850; font-variant-numeric: tabular-nums; }
        .progress-panel { padding: .9rem 1.1rem; margin-bottom: .8rem; }
        .progress-copy { display: flex; justify-content: space-between; gap: 1rem; margin-bottom: .6rem; color: var(--muted); font-size: .82rem; font-weight: 700; }
        .workspace { display: grid; grid-template-columns: minmax(0, 1fr) 270px; gap: .8rem; }
        .verify-card { min-height: 460px; padding: clamp(1.2rem, 4vw, 2.2rem); display: flex; flex-direction: column; }
        .card-meta { display: flex; align-items: center; justify-content: space-between; gap: 1rem; color: var(--muted); font-size: .82rem; }
        .filename { max-width: 70%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-family: ui-monospace, monospace; }
        .image-stage {
            flex: 1; min-height: 190px; display: grid; place-items: center; margin: 1.25rem 0;
            border: 1px solid var(--line); border-radius: 1.1rem; overflow: hidden;
            background:
                linear-gradient(45deg, #dedede 25%, transparent 25%),
                linear-gradient(-45deg, #dedede 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #dedede 75%),
                linear-gradient(-45deg, transparent 75%, #dedede 75%),
                #fff;
            background-size: 18px 18px;
            background-position: 0 0, 0 9px, 9px -9px, -9px 0;
        }
        .image-stage img { display: block; width: min(92%, 720px); height: auto; image-rendering: auto; }
        .label-form { display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: .7rem; }
        .label-input {
            width: 100%; min-height: 3.5rem; padding: .7rem 1rem; border: 1px solid rgba(255,255,255,.16);
            border-radius: .9rem; color: var(--ink); background: rgba(0,0,0,.24);
            font-size: 1.45rem; font-weight: 800; letter-spacing: .12em; direction: ltr;
        }
        .secondary-actions { display: flex; align-items: center; justify-content: space-between; gap: .75rem; margin-top: .7rem; }
        .hint { color: var(--muted); font-size: .78rem; }
        kbd { padding: .15rem .38rem; border: 1px solid var(--line); border-bottom-width: 2px; border-radius: .35rem; color: #d5d3cc; background: rgba(255,255,255,.05); font: inherit; font-size: .72rem; }
        .sidebar { padding: 1.1rem; }
        .sidebar-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: .9rem; }
        .recent-list { display: grid; gap: .45rem; }
        .recent {
            display: flex; align-items: center; justify-content: space-between; gap: .75rem;
            padding: .75rem; border-radius: .75rem; color: var(--ink); background: rgba(255,255,255,.035);
            text-decoration: none; border: 1px solid transparent;
        }
        .recent:hover { border-color: var(--line); }
        .recent-name { min-width: 0; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .72rem; }
        .recent-label { font-weight: 850; letter-spacing: .06em; }
        .sync { margin-top: 1rem; padding-top: 1rem; border-top: 1px solid var(--line); }
        .sync button { width: 100%; }
        .notice { margin-bottom: .8rem; padding: .85rem 1rem; border: 1px solid rgba(131,219,181,.25); border-radius: .85rem; color: var(--success); background: rgba(131,219,181,.07); }
        .notice.error { color: var(--danger); border-color: rgba(255,141,145,.25); background: rgba(255,141,145,.07); }
        .complete { min-height: 460px; display: grid; place-items: center; padding: 2rem; text-align: center; }
        .complete-mark { margin: 0 auto 1rem; display: grid; place-items: center; width: 4rem; height: 4rem; border-radius: 50%; color: #102018; background: var(--success); font-size: 2rem; font-weight: 900; }
        .error-details { overflow-wrap: anywhere; color: var(--danger); }
        .visually-hidden { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0; }
        @media (max-width: 760px) {
            .shell { width: min(100% - 1rem, 680px); padding-top: 1rem; }
            .workspace { grid-template-columns: 1fr; }
            .stats { gap: .45rem; }
            .stat { padding: .8rem; }
            .stat-value { font-size: 1.1rem; }
            .label-form { grid-template-columns: 1fr; }
            .label-form button { width: 100%; }
            .sidebar { order: 2; }
            .image-stage { min-height: 160px; }
            .topbar { margin-bottom: 1rem; }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { scroll-behavior: auto !important; transition: none !important; }
        }
    </style>
</head>
<body>
<?php
}

function renderFooter(): void
{
    ?>
</body>
</html>
<?php
}

function renderSetup(array $config, ?string $error = null): never
{
    $exists = is_file($config['state']);
    renderHeader('Set up');
    ?>
<main class="shell">
    <section class="panel setup">
        <div class="eyebrow">Browser verifier</div>
        <h1>Make the dataset ready.</h1>
        <p>
            The first run builds a fast local SQLite index from
            <code><?= h($config['csv']) ?></code>. The CSV and all images stay in place.
            Importing can be paused and safely resumed.
        </p>
        <?php if ($error !== null): ?>
            <div class="notice error" id="setup-error"><?= h($error) ?></div>
        <?php else: ?>
            <div class="notice error" id="setup-error" hidden></div>
        <?php endif; ?>
        <div id="setup-progress" hidden>
            <div class="progress-copy">
                <span id="setup-status">Preparing…</span>
                <span id="setup-percent">0%</span>
            </div>
            <div class="progress-track">
                <div class="progress-fill" id="setup-fill"></div>
            </div>
        </div>
        <div class="setup-actions">
            <button class="primary" type="button" id="initialize">
                <?= $exists ? 'Resume initialization' : 'Initialize verifier' ?>
            </button>
            <?php if ($exists): ?>
                <form method="post" onsubmit="return confirm('Discard the incomplete import and start again?')">
                    <input type="hidden" name="action" value="restart">
                    <button class="danger" type="submit">Restart import</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="hint">Requires PHP 8.1+, PDO SQLite, and write access to the dataset directory.</p>
    </section>
</main>
<script>
(() => {
    const button = document.querySelector('#initialize');
    const progress = document.querySelector('#setup-progress');
    const fill = document.querySelector('#setup-fill');
    const percent = document.querySelector('#setup-percent');
    const status = document.querySelector('#setup-status');
    const error = document.querySelector('#setup-error');
    let running = false;

    async function initialize() {
        if (running) return;
        running = true;
        button.disabled = true;
        progress.hidden = false;
        error.hidden = true;
        try {
            while (true) {
                const body = new URLSearchParams({action: 'initialize'});
                const response = await fetch('', {
                    method: 'POST',
                    body,
                    headers: {'Accept': 'application/json'}
                });
                const payload = await response.json();
                if (!response.ok) throw new Error(payload.error || 'Initialization failed.');
                fill.style.width = `${payload.percent}%`;
                percent.textContent = `${payload.percent}%`;
                status.textContent = `${Number(payload.rows).toLocaleString()} rows indexed`;
                if (payload.done) {
                    window.location.reload();
                    return;
                }
            }
        } catch (problem) {
            error.textContent = problem.message;
            error.hidden = false;
            button.disabled = false;
            button.textContent = 'Resume initialization';
            running = false;
        }
    }
    button.addEventListener('click', initialize);
})();
</script>
<?php
    renderFooter();
    exit;
}

function renderConflict(array $config, PDO $db, string $message): never
{
    http_response_code(409);
    $pending = (int) metadata($db, 'pending_count', '0');
    $recovering = metadata($db, 'sync_in_progress', '0') === '1';
    renderHeader('CSV conflict');
    ?>
<main class="shell">
    <section class="panel setup">
        <div class="eyebrow">Attention required</div>
        <h1>The source CSV changed.</h1>
        <p class="error-details"><?= h($message) ?></p>
        <p>
            SQLite currently has <strong><?= number_format($pending) ?></strong>
            unsynced row<?= $pending === 1 ? '' : 's' ?>. The verifier stopped to avoid replacing
            changes made by another process.
        </p>
        <?php if ($recovering): ?>
            <form method="post">
                <input type="hidden" name="action" value="sync">
                <button class="primary" type="submit">Resume interrupted sync</button>
            </form>
        <?php endif; ?>
        <p class="hint">Do not run verify_labels.py or modify labels.csv while the web verifier is active.</p>
    </section>
</main>
<?php
    renderFooter();
    exit;
}

function renderVerifier(array $config, ?string $error = null): never
{
    $db = openDatabase($config);
    requireReady($db);
    try {
        verifySourceUnchanged($db, $config);
    } catch (AppError $conflict) {
        renderConflict($config, $db, $conflict->getMessage());
    }

    $editId = filter_input(INPUT_GET, 'edit', FILTER_VALIDATE_INT);
    $editing = $editId !== false && $editId !== null && $editId >= 0;
    $row = $editing ? rowById($db, $editId) : currentRow($db);
    $total = (int) metadata($db, 'total_count', '0');
    $verified = (int) metadata($db, 'verified_count', '0');
    $remaining = max(0, $total - $verified);
    $pending = (int) metadata($db, 'pending_count', '0');
    $revision = (int) metadata($db, 'revision', '0');
    $progress = $total > 0 ? $verified * 100 / $total : 100;
    $recent = recentRows($db);
    $status = (string) ($_GET['status'] ?? '');
    $notices = [
        'saved' => 'Label saved. Progress is autosaved in SQLite.',
        'corrected' => 'The previous label was corrected.',
        'skipped' => 'Image skipped for now. It will return after the queue wraps.',
        'synced' => 'labels.csv was updated atomically.',
    ];

    renderHeader($editing ? 'Correct label' : 'Verify labels');
    ?>
<main class="shell">
    <header class="topbar">
        <div class="brand"><span class="brand-mark"></span> OCR verifier</div>
        <span class="pill"><?= $pending > 0 ? number_format($pending) . ' unsynced' : 'CSV up to date' ?></span>
    </header>

    <?php if ($error !== null): ?>
        <div class="notice error"><?= h($error) ?></div>
    <?php elseif (isset($notices[$status])): ?>
        <div class="notice"><?= h($notices[$status]) ?></div>
    <?php endif; ?>

    <section class="stats" aria-label="Dataset progress">
        <div class="panel stat">
            <div class="stat-label">Verified</div>
            <div class="stat-value"><?= number_format($verified) ?></div>
        </div>
        <div class="panel stat">
            <div class="stat-label">Remaining</div>
            <div class="stat-value"><?= number_format($remaining) ?></div>
        </div>
        <div class="panel stat">
            <div class="stat-label">Complete</div>
            <div class="stat-value"><?= number_format($progress, 2) ?>%</div>
        </div>
    </section>
    <section class="panel progress-panel">
        <div class="progress-copy">
            <span><?= number_format($verified) ?> of <?= number_format($total) ?></span>
            <span><?= number_format($pending) ?> pending CSV sync</span>
        </div>
        <div class="progress-track">
            <div class="progress-fill" style="width: <?= h(min(100, $progress)) ?>%"></div>
        </div>
    </section>

    <div class="workspace">
        <?php if ($row === null): ?>
            <section class="panel complete">
                <div>
                    <div class="complete-mark">✓</div>
                    <h1>Everything is verified.</h1>
                    <p>All <?= number_format($total) ?> labels are complete. Sync the CSV if any changes are pending.</p>
                </div>
            </section>
        <?php else: ?>
            <section class="panel verify-card">
                <div class="card-meta">
                    <span><?= $editing ? 'Correcting a recent label' : 'Row ' . number_format((int) $row['id'] + 1) ?></span>
                    <span class="filename" title="<?= h($row['filename']) ?>"><?= h($row['filename']) ?></span>
                </div>
                <div class="image-stage">
                    <img
                        src="?action=image&amp;id=<?= (int) $row['id'] ?>"
                        alt="Number image for row <?= (int) $row['id'] + 1 ?>"
                        width="612"
                        height="128"
                    >
                </div>
                <form method="post" id="label-form">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
                    <input type="hidden" name="revision" value="<?= $revision ?>">
                    <input type="hidden" name="mode" value="<?= $editing ? 'edit' : 'queue' ?>">
                    <label class="visually-hidden" for="label">Number shown in the image</label>
                    <div class="label-form">
                        <input
                            class="label-input"
                            id="label"
                            name="label"
                            value="<?= h($row['label']) ?>"
                            inputmode="numeric"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="64"
                            pattern="[0-9۰-۹٠-٩]+"
                            placeholder="Type the number"
                            autofocus
                        >
                        <button class="primary" type="submit"><?= $editing ? 'Save correction' : 'Save & next' ?></button>
                    </div>
                </form>
                <div class="secondary-actions">
                    <?php if ($editing): ?>
                        <a class="button" href="./">Back to queue</a>
                    <?php else: ?>
                        <form method="post" id="skip-form">
                            <input type="hidden" name="action" value="skip">
                            <input type="hidden" name="row_id" value="<?= (int) $row['id'] ?>">
                            <input type="hidden" name="revision" value="<?= $revision ?>">
                            <button type="submit">Skip for now</button>
                        </form>
                    <?php endif; ?>
                    <span class="hint"><kbd>Enter</kbd> save · empty <kbd>Enter</kbd> skip</span>
                </div>
            </section>
        <?php endif; ?>

        <aside class="panel sidebar">
            <div class="sidebar-head">
                <h2>Recent</h2>
                <span class="pill"><?= count($recent) ?></span>
            </div>
            <?php if ($recent === []): ?>
                <p class="hint">Saved labels will appear here for quick correction.</p>
            <?php else: ?>
                <div class="recent-list">
                    <?php foreach ($recent as $recentRow): ?>
                        <a class="recent" href="?edit=<?= (int) $recentRow['id'] ?>">
                            <span class="recent-name">#<?= number_format((int) $recentRow['id'] + 1) ?></span>
                            <span class="recent-label"><?= h($recentRow['label']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="sync">
                <form method="post" onsubmit="return confirm('Rewrite labels.csv with all saved labels?')">
                    <input type="hidden" name="action" value="sync">
                    <button type="submit" <?= $pending === 0 ? 'disabled' : '' ?>>
                        Sync labels.csv
                    </button>
                </form>
                <p class="hint">SQLite is already saved. Sync before training, copying, or using the Python tools.</p>
            </div>
        </aside>
    </div>
</main>
<?php if ($row !== null): ?>
<script>
(() => {
    const input = document.querySelector('#label');
    const form = document.querySelector('#label-form');
    const skip = document.querySelector('#skip-form');
    if (!input || !form) return;

    const digitMap = {
        '۰':'0','۱':'1','۲':'2','۳':'3','۴':'4','۵':'5','۶':'6','۷':'7','۸':'8','۹':'9',
        '٠':'0','١':'1','٢':'2','٣':'3','٤':'4','٥':'5','٦':'6','٧':'7','٨':'8','٩':'9'
    };
    input.addEventListener('input', () => {
        input.value = Array.from(input.value, character => digitMap[character] ?? character)
            .join('')
            .replace(/[^0-9]/g, '');
    });
    form.addEventListener('submit', event => {
        if (input.value.trim() === '' && skip) {
            event.preventDefault();
            skip.requestSubmit();
        }
    });
    input.focus();
    input.select();

    <?php if (!$editing):
        $next = nextRowAfter($db, (int) $row['id']);
        if ($next !== null): ?>
            const preload = new Image();
            preload.src = '?action=image&id=<?= (int) $next['id'] ?>';
        <?php endif;
    endif; ?>
})();
</script>
<?php endif; ?>
<?php
    renderFooter();
    exit;
}

function handleRequest(): never
{
    $config = config();
    $action = requestAction();

    try {
        if ($action === 'image') {
            serveImage($config);
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if ($action === 'initialize') {
                jsonResponse(initializeBatch($config));
            }
            if ($action === 'restart') {
                restartImport($config);
                redirect('./');
            }
            if ($action === 'save') {
                $status = saveLabel($config);
                redirect('./?status=' . $status);
            }
            if ($action === 'skip') {
                skipRow($config);
                redirect('./?status=skipped');
            }
            if ($action === 'sync') {
                syncCsv($config);
                redirect('./?status=synced');
            }
            throw new AppError('Unknown action.', 404);
        }

        if (!is_file($config['state'])) {
            renderSetup($config);
        }
        $db = openDatabase($config);
        if (metadata($db, 'status') !== 'ready') {
            renderSetup($config);
        }
        renderVerifier($config);
    } catch (AppError $error) {
        if ($action === 'initialize') {
            jsonResponse(['error' => $error->getMessage()], $error->status);
        }
        if ($action === 'image') {
            http_response_code($error->status);
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store');
            echo $error->getMessage();
            exit;
        }
        http_response_code($error->status);
        if (is_file($config['state'])) {
            try {
                $db = openDatabase($config);
                if (metadata($db, 'status') === 'ready') {
                    renderVerifier($config, $error->getMessage());
                }
            } catch (Throwable) {
                // Fall through to the setup/error page.
            }
        }
        renderSetup($config, $error->getMessage());
    } catch (Throwable $error) {
        error_log((string) $error);
        if ($action === 'initialize') {
            jsonResponse(['error' => 'Unexpected initialization error. Check the PHP error log.'], 500);
        }
        http_response_code(500);
        renderSetup($config, 'Unexpected server error. Check the PHP error log.');
    }
}

handleRequest();
