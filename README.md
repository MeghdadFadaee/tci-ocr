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

## 5. Verify labels in a web browser

The PHP verifier provides the same sequential labeling workflow without Kitty,
Python packages, Composer, or a framework. It requires PHP 8.1 or newer with
PDO SQLite and Fileinfo enabled.

Start it locally from the repository root:

```bash
php -S 127.0.0.1:8080 -t web
```

Then open `http://127.0.0.1:8080`. On the first visit, click **Initialize
verifier**. The browser incrementally imports `dataset/labels.csv` into
`dataset/verification.sqlite`; progress is resumable if the page or server is
stopped.

Each accepted label is immediately committed to SQLite. ASCII, Persian, and
Arabic digits are accepted and normalized to ASCII. Enter saves and advances;
an empty Enter or **Skip for now** leaves the row unverified and advances. Recent
labels can be opened and corrected without moving the main queue.

The page deliberately does not rewrite the large CSV after every answer. The
header shows how many rows have not yet been copied back. Click **Sync
labels.csv** before training, copying the dataset, or using another Python tool.
Sync writes a complete neighboring temporary file and atomically replaces
`labels.csv`; a failed write leaves the existing CSV unchanged.

Do not run `verify_labels.py`, the downloader, or another process that changes
`labels.csv` while the web verifier is active. The page detects an external CSV
change and stops rather than overwriting it. To move the dataset to another
machine, sync first and copy the dataset normally; the destination can rebuild
its SQLite state from the synced CSV.

### Use another dataset directory

The default dataset is the repository's `dataset/` directory. Set
`OCR_DATASET_DIR` to use another location:

```bash
OCR_DATASET_DIR=/data/persian-ocr \
  php -S 127.0.0.1:8080 -t web
```

`OCR_STATE_PATH` can optionally place `verification.sqlite` elsewhere. The PHP
process must be able to read the CSV and images and write the SQLite database,
its lock/WAL files, and temporary CSV files. Grant the PHP-FPM user ownership or
a targeted ACL; do not make the directory world-writable.

### Minimal Nginx configuration

Use `web/` as the public document root so the CSV, SQLite state, images, and
Python source files are not directly downloadable. The page safely streams the
selected image itself.

```nginx
server {
    listen 80;
    server_name verifier.example.internal;
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

Adjust the PHP-FPM socket and paths for the server. The application intentionally
contains no authentication; add Nginx basic authentication to the `server` or
`location /` block, or expose it only on a private network.
