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
