# WP Edge Images

[![Packagist Version](https://img.shields.io/packagist/v/mantekio/wp-edge-images)](https://packagist.org/packages/mantekio/wp-edge-images) [![License: GPL v2](https://img.shields.io/badge/License-GPLv2-blue.svg)](LICENSE)

A WordPress **must-use plugin** that stops core from generating intermediate image
sizes and instead serves every size on the fly from an [AWS Serverless Image
Handler](https://aws.amazon.com/solutions/implementations/serverless-image-handler/)
(or any Thumbor-compatible CDN).

It is **URL layer only**: no AWS credentials, no SDK. It rewrites the image URLs
WordPress emits and disables thumbnail generation, so it pairs with any storage
backend (your own S3 offload, WP Offload Media, Humanmade S3 Uploads, or a manual
sync).

> 📖 **Full write-up:** [Make WordPress do less: offloading media to S3 and the edge](https://www.mantek.io/insights/wordpress-s3-media-pipeline)

## The problem

On every upload WordPress generates a stack of resized copies (thumbnail, medium,
medium_large, large, 1536, 2048, plus theme sizes) and stores them all. Most are
never requested. If a CDN can produce any size on demand, pre-generating a fixed
menu of sizes is wasted CPU at upload time and wasted storage forever.

## What it does

- **Turns off intermediate sizes.** No more thumbnail generation on upload.
- **Rewrites image URLs to the edge.** `wp_get_attachment_image_src` (featured
  images, blocks, `the_post_thumbnail`), the responsive `srcset`, and inline
  content images (WP 6.0+) all point at the Image Handler, with the requested
  dimensions encoded in the URL.
- **Synthesizes `srcset` and `sizes`.** Because there are no recorded sub-sizes to
  build responsive markup from, the plugin generates a width set (never wider than
  the original) pointing at the edge, so responsive images keep working.
- **Stays out of the way.** Non-raster files (SVG, etc.) are skipped, and with no
  base URL configured the plugin is a no-op.

## Installation

This is a must-use plugin, so it loads before output is rendered and cannot be
deactivated by accident.

**Manual**
```bash
mkdir -p wp-content/mu-plugins
cp wp-edge-images.php wp-content/mu-plugins/
```

**Composer**
```bash
composer require mantekio/wp-edge-images
```

Composer installs it as a `wordpress-muplugin` via `composer/installers`, which
has two consequences worth knowing:

- **Allow the installer plugin.** On a fresh project Composer blocks
  `composer/installers` until you permit it. Add to your root `composer.json`
  (most WordPress-Composer stacks already have it):
  ```json
  { "config": { "allow-plugins": { "composer/installers": true } } }
  ```
- **Make sure it loads.** It installs into `wp-content/mu-plugins/wp-edge-images/`
  (a subfolder), and vanilla WordPress only auto-loads `*.php` placed *directly* in
  `mu-plugins/`, not in subfolders
  ([WordPress docs](https://developer.wordpress.org/advanced-administration/plugins/mu-plugins/)).
  Either use a mu-plugins autoloader (Bedrock and similar stacks ship one), use the
  Manual method above instead, or keep Composer and add a one-line loader directly
  in `mu-plugins/`:
  ```php
  <?php // wp-content/mu-plugins/edge-images-loader.php
  require WPMU_PLUGIN_DIR . '/wp-edge-images/wp-edge-images.php';
  ```
  Pick one: with the Manual copy and a loader both in place, the plugin loads twice
  and PHP stops with a fatal error.

## Configuration

Point the plugin at your Image Handler / CDN base. Everything else has sensible
defaults and is filterable.

```php
// Required: the Serverless Image Handler / Thumbor CDN base.
define( 'WP_EDGE_IMAGES_BASE', 'https://images.example.com' );
```

| Filter | Default | Purpose |
|---|---|---|
| `wp_edge_images_base` | `WP_EDGE_IMAGES_BASE` | The CDN base URL (overrides the constant) |
| `wp_edge_images_disable_thumbnails` | `true` | Set `false` to keep WordPress generating sizes |
| `wp_edge_images_quality` | `80` | Output quality |
| `wp_edge_images_widths` | `320 ... 2048` | The candidate widths used to build `srcset` |
| `wp_edge_images_url` | (Thumbor URL) | Full override of the generated URL, for a non-Thumbor handler |

The default URL form is Thumbor style, which the AWS Serverless Image Handler
understands:

```text
https://images.example.com/fit-in/800x0/filters:format(auto):quality(80)/2025/07/20250727-1753632511534.jpg
```

`format(auto)` lets the handler return AVIF or WebP by the browser's `Accept`
header from a single master.

## How it works

The full architecture (two-copy S3 storage, on-the-fly derivatives, clean
generated keys, browser-direct video uploads, and platform-independent playback)
is in the write-up:

**→ [Make WordPress do less: offloading media to S3 and the edge](https://www.mantek.io/insights/wordpress-s3-media-pipeline)**

## Changelog

Every release is documented in the [changelog](https://github.com/mantekio/wp-edge-images/blob/main/CHANGELOG.md), and the notes for each version are on the [releases page](https://github.com/mantekio/wp-edge-images/releases).

## License

[GPL-2.0-or-later](LICENSE): same as WordPress.

---

Built and maintained by **[ManTek Technologies](https://www.mantek.io)**: WordPress + AWS at scale, for newsrooms and beyond.
