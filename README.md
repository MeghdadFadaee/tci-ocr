# Persian number OCR dataset tools

These dependency-free Python scripts build and manually label an exact-hash-indexed
image dataset. They require Python 3.10 or newer. The verification script also
requires a [Kitty terminal](https://sw.kovidgoyal.net/kitty/) installation.

## 1. Download the images

The API is expected to return the raw bytes of one JPEG, PNG, GIF, BMP, or WebP
image per HTTP request. The default target is one million successfully downloaded
images:

```bash
python download_dataset.py --url 'https://example.com/random-number-image'
```

Headers and query parameters can be repeated. Quote URLs containing `{index}`;
the placeholder is replaced with the zero-based image number:

```bash
python download_dataset.py \
  --url 'https://example.com/captcha?id={index}' \
  --header 'Authorization=Bearer YOUR_TOKEN' \
  --param 'format=png' \
  --workers 16 \
  --count 1000000
```

Output is sharded to avoid putting one million files in one directory:

```text
dataset/
├── labels.csv
└── images/
    ├── 000000/
    ├── 000001/
    └── ...
```

`labels.csv` always has these columns:

```text
filename,label,batch_id,verified,hash
```

`hash` is the SHA-256 of the exact response bytes. `label` starts empty and
`verified` starts at `0`. The downloader appends and flushes incrementally, so
rerunning the same command resumes toward `--count`. Tune `--workers` and
`--delay` to respect the API's published rate limit.

## 2. Merge downloads from multiple machines or directories

Each source can be a dataset directory or its `labels.csv` file. The output must
not already exist:

```bash
python merge_datasets.py \
  /path/to/download-1/dataset \
  /path/to/download-2/dataset \
  /path/to/download-3/dataset \
  --output combined_dataset
```

The merge preserves every label, verification flag, and hash while assigning new
collision-free filenames and batch IDs. Source files are never changed. The
result is first built in a neighboring staging directory and moved into place
only after it completes. Exact duplicates are intentionally retained for review
with `find_duplicates.py`.

Copying is safest and is the default. If all datasets are on the same filesystem,
`--mode hardlink` makes the merge much faster and uses almost no additional image
storage. Hard-linked source and destination files share the same underlying data.

## 3. Find exact duplicates

```bash
python find_duplicates.py \
  --csv combined_dataset/labels.csv \
  --output combined_dataset/duplicates.csv
```

This reads stored hashes without reopening the images and writes
`dataset/duplicates.csv`. Every duplicate row points to the first filename with
the same hash. It reports exact byte-for-byte duplicates, not merely similar
images.

## 4. Verify labels in Kitty

Run this inside Kitty:

```bash
python verify_labels.py --csv combined_dataset/labels.csv
```

For each unverified image, enter its number. Persian (`۰۱۲۳`), Arabic (`٠١٢٣`),
and ASCII digits are accepted and normalized to ASCII by default. Press `s` or
Enter to skip, or `q`/Ctrl-C to save and quit. Existing verified rows are skipped.
Use `--reverify` to review them and `--start N` to begin after row `N`.

The verifier builds a complete temporary CSV and atomically replaces the original
when you quit, so the CSV cannot be left half-written. If it is interrupted while
copying or displaying rather than at the prompt, the original CSV remains unchanged.

## 5. Collect panel-validated captchas

The dependency-free PHP collector downloads a fresh captcha from the customer
panel and validates your transcription with a real login attempt. An image is
added to the dataset only when the panel responds with its successful login
redirect. Rejected, expired, or skipped captchas are discarded.

The collector requires PHP 8.1 or newer with sessions, OpenSSL, and the standard
HTTP stream wrapper enabled. Start it from the repository root:

```bash
php -S 127.0.0.1:8080 -t web
```

Open `http://127.0.0.1:8080`, enter the number, and press Enter. ASCII, Persian,
and Arabic digits are accepted and normalized to ASCII. The next challenge is
loaded without a page refresh so the numeric keyboard stays open on phones.

By default the collector connects to the TCI customer panel at
`https://internet.tci.ir/panel/` and appends to `dataset/labels.csv`. Create a
project-level `.env` file for your TCI credentials:

```bash
cp .env.example .env
```

Edit `.env` and replace `your-tci-username` and `your-tci-password`. Quoted
values may contain spaces, `#`, or `$`. The `.env` file is ignored by Git.
Existing PHP-FPM, Nginx FastCGI, or process environment settings take precedence
over values in `.env`.

The supported settings are:

- `PANEL_BASE_URL` — panel origin; defaults to `https://internet.tci.ir`.
- `PANEL_LOGIN_USERNAME` — login username; defaults to `username`.
- `PANEL_LOGIN_PASSWORD` — plaintext login password; defaults to `password`.
- `PANEL_REQUEST_TIMEOUT` — upstream timeout in seconds; defaults to `10`.
- `OCR_DATASET_DIR` — output dataset; defaults to the repository's `dataset/`.

Validated images use content-addressed paths below `images/panel/`. The collector
appends rows with `batch_id=panel` and `verified=1`, while preserving all existing
CSV rows. Writes are locked, flushed, and exact-image duplicates are idempotent.
If the dataset directory or CSV has been removed, it is recreated automatically.
Legacy `verification.sqlite` files are ignored.

Keep the collector private: it has no user authentication and holds the panel
credentials. The PHP user needs read permission for `.env` and write permission
for the dataset directory. On a typical Debian/Ubuntu PHP-FPM server, protect the
file with:

```bash
sudo chown root:www-data .env
sudo chmod 640 .env
```

Use your PHP-FPM worker group instead of `www-data` when it differs. Keep the
Nginx document root set to the repository's `web/` directory so `.env` and
`dataset/` cannot be downloaded.

### Minimal Nginx configuration

```nginx
server {
    listen 80;
    server_name collector.example.internal;
    root /srv/tci-ocr/web;
    index index.php;

    location / {
        try_files $uri /index.php?$query_string;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
        fastcgi_param OCR_DATASET_DIR /srv/tci-ocr/dataset;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ \.php$ {
        return 404;
    }
}
```
