[![Latest Stable Version](https://poser.pugx.org/derhansen/processed-images-cleaner/v/stable)](https://packagist.org/packages/derhansen/processed-images-cleaner)
[![TYPO3 12](https://img.shields.io/badge/TYPO3-12-orange.svg)](https://get.typo3.org/version/12)
[![TYPO3 13](https://img.shields.io/badge/TYPO3-13-orange.svg)](https://get.typo3.org/version/13)
[![TYPO3 14](https://img.shields.io/badge/TYPO3-14-orange.svg)](https://get.typo3.org/version/14)
[![Monthly Downloads](https://poser.pugx.org/derhansen/processed-images-cleaner/d/monthly)](https://packagist.org/packages/derhansen/processed-images-cleaner)
[![License](https://poser.pugx.org/derhansen/processed-images-cleaner/license)](https://packagist.org/packages/derhansen/processed-images-cleaner)
[![Project Status: Active – The project has reached a stable, usable state and is being actively developed.](https://www.repostatus.org/badges/latest/active.svg)](https://www.repostatus.org/#active)

Processed Images Cleaner
========================

## What is it?

Processed Images Cleaner is an extension for TYPO3 CMS that provides a Symfony console
command to clean up **processed image files** (records in `sys_file_processedfile` and their
physical files) by several conditions.

TYPO3 creates a processed file every time an image is rendered in a specific size, crop or
format. Over time – and especially after changing image cropping, breakpoints or processing
instructions – the `_processed_` folder accumulates a large number of stale files that are no
longer referenced. Deleting a processed file is safe: TYPO3 transparently regenerates it the
next time it is requested. This extension lets you remove those files in a targeted way.

**Summary of features**

* Clean up processed images by original file storage, folder, file UID or file identifier
* Filter by the processing configuration itself (e.g. `width`, `maxWidth`, `cropVariant`)
* Lenient numeric matching, so `width=800` also matches the TypoScript modifiers `800m` / `800c`
* Match unset configuration values with `key=null`
* `--dry-run` mode to preview what would be deleted without touching any file
* Only ever touches processed files whose **original is an image**
* Never touches the "uses original file" placeholder rows (empty identifier)
* Refuses to run without at least one filter, to prevent accidental mass deletion
* Combinable filters – all provided filters must match (logical AND)

**Background**

* Based on a single, dependency-free Symfony console command
* Covered with functional tests
* Actively maintained

## Requirements

| Version  | TYPO3         | PHP         |
|----------|---------------|-------------|
| 1.0      | 12.4, 13.4 & 14.3 | 8.2 - 8.5 |

## Installation

### Installation using Composer

The recommended way to install the extension is by using [Composer](https://getcomposer.org/).
In your Composer based TYPO3 project root, just do
`composer require derhansen/processed-images-cleaner`.

### Installation as extension from TYPO3 Extension Repository (TER)

Download and install the extension with the TYPO3 extension manager module.

## Usage

The extension registers the console command `cleanup:processedimages`. In a Composer based
installation it is run via the TYPO3 binary:

```bash
./vendor/bin/typo3 cleanup:processedimages [options]
```

In a classic (non-Composer) installation use:

```bash
./typo3/sysext/core/bin/typo3 cleanup:processedimages [options]
```

> **The command refuses to run without at least one filter.** You must provide at least one
> file filter (`--storage`, `--folder`, `--file-uid`, `--identifier`) or a configuration
> filter (`--config`). This is a safety measure to avoid deleting *all* processed files by
> accident.

### Options

| Option         | Shortcut | Repeatable | Description                                                                                  |
|----------------|----------|------------|----------------------------------------------------------------------------------------------|
| `--dry-run`    |          | no         | Do not delete anything, only report what would be deleted.                                   |
| `--storage`    | `-s`     | no         | Storage UID of the original files.                                                           |
| `--folder`     | `-f`     | no         | Folder identifier within the storage, e.g. `user_upload/`.                                   |
| `--file-uid`   | `-u`     | yes        | UID of an original `sys_file` record.                                                        |
| `--identifier` | `-i`     | yes        | Full original file identifier incl. path, e.g. `/user_upload/foo/bar/image.jpg`.             |
| `--config`     | `-c`     | yes        | Processing configuration filter as `key=value`, e.g. `-c width=1000 -c cropVariant=default`. |
| `--limit`      | `-l`     | no         | Maximum number of processed files to delete (positive integer). Files are processed ordered by uid. |

**File filters** (`--storage`, `--folder`, `--file-uid`, `--identifier`) narrow the database
query based on the *original* file. **Configuration filters** (`--config`) are evaluated per
processed file against its stored processing configuration. When several filters are given,
**all of them must match**.

### Examples

**Preview (dry-run) all processed files below a folder – nothing is deleted:**

```bash
./vendor/bin/typo3 cleanup:processedimages --folder="user_upload/" --dry-run
```

**Delete all processed images of a specific file storage:**

```bash
./vendor/bin/typo3 cleanup:processedimages --storage=1
```

**Delete processed images for one or more original files (by UID):**

```bash
./vendor/bin/typo3 cleanup:processedimages --file-uid=42 --file-uid=43
# or using the shortcut
./vendor/bin/typo3 cleanup:processedimages -u 42 -u 43
```

**Delete processed images for a specific original file (by identifier):**

```bash
./vendor/bin/typo3 cleanup:processedimages --identifier="/user_upload/campaigns/hero.jpg"
```

**Delete processed images by processing configuration (width):**

```bash
./vendor/bin/typo3 cleanup:processedimages --config=width=1000
```

Numeric values match leniently, so `width=800` also matches processed files stored with the
TypoScript modifiers `800m` (max) or `800c` (crop-scale).

**Delete processed images of a specific crop variant:**

```bash
./vendor/bin/typo3 cleanup:processedimages -c cropVariant=default
```

**Match records where a configuration value is unset (`null`):**

```bash
./vendor/bin/typo3 cleanup:processedimages -c height=null
```

**Combine several configuration filters (all must match):**

```bash
./vendor/bin/typo3 cleanup:processedimages -c width=1000 -c maxWidth=1800 -c cropVariant=default
```

**Combine file and configuration filters, preview first:**

```bash
./vendor/bin/typo3 cleanup:processedimages -f "user_upload/campaigns/" -c cropVariant=square --dry-run
```

**Limit how many files are deleted per run:**

```bash
./vendor/bin/typo3 cleanup:processedimages --storage=1 --limit=500
```

All matching files are still counted and reported as *selected*, but only the first `--limit`
files (ordered by uid) are actually deleted. This is useful to spread a large cleanup over
several runs. The final summary shows both figures, e.g.
`1234 processed file(s) selected, 500 deleted, 42.0 M freed.`

A typical, safe workflow is to first run the command with `--dry-run` to review the matched
files and the amount of disk space that would be freed, and then run the same command again
without `--dry-run` to actually delete the files.

## Scheduling in the TYPO3 backend

The command can be scheduled as a recurring task via the TYPO3 Scheduler
(*"Execute console commands"* task, task type `cleanup:processedimages`). All options are
available in the task configuration form.

Repeatable options (`--file-uid`, `--identifier`, `--config`) work in the backend as well –
they are just entered differently than on the command line. Since the Scheduler cannot render
a repeatable input field, TYPO3 core maps array options to a **single comma-separated value
field**. Enable the option's checkbox and enter the multiple values separated by commas:

| Option         | On the CLI                          | In the Scheduler task field         |
|----------------|-------------------------------------|-------------------------------------|
| `--file-uid`   | `-u 42 -u 43`                       | `42,43`                             |
| `--identifier` | `-i /a/one.jpg -i /a/two.jpg`       | `/a/one.jpg,/a/two.jpg`             |
| `--config`     | `-c width=1000 -c cropVariant=default` | `width=1000,cropVariant=default` |

Please note:

* A configuration value must not itself contain a comma – the comma is the fixed separator,
  and there is no way to escape it.
* Do not leave a trailing comma (e.g. `width=1000,`). It produces an empty item that the
  command rejects with an *"Invalid --config value"* error.

This is standard TYPO3 core behaviour and not a limitation of this extension.

## How it works

* Only processed files whose **original file is an image** are considered.
* Rows that only cache the "no processing needed / uses original file" decision (empty
  identifier) are never touched – there is no physical file to remove.
* The processing configuration is read from the `configuration` column of
  `sys_file_processedfile` and matched against the given `--config` filters. Numeric values
  are compared leniently (ignoring the `m`/`c` TypoScript modifiers), and `key=null` also
  matches configuration shapes that omit the key entirely.
* Deleted processed files are removed both as database record and as physical file. TYPO3
  regenerates them on demand the next time the image is rendered.

## Contributing

Pull requests are welcome. Please open an issue first to discuss larger changes.

## Reporting a Vulnerability

Please report vulnerabilities to [security@typo3.org](mailto:security@typo3.org) only.
