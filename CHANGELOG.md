# Changelog

All notable changes to **WP Edge Images** are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0]

### Added
- Initial release. A WordPress must-use plugin that disables intermediate image
  generation and serves every size on the fly from an AWS Serverless Image
  Handler or any Thumbor-compatible CDN:
  - Turns off `intermediate_image_sizes_advanced` so no thumbnails are generated
    on upload.
  - Rewrites `wp_get_attachment_image_src`, the responsive `srcset`, and inline
    content images (WP 6.0+) to edge URLs with the requested dimensions.
  - Synthesizes `srcset` and `sizes` from a configurable width set, never wider
    than the original, so responsive images keep working without recorded sizes.
  - URL layer only: no AWS credentials, configured with `WP_EDGE_IMAGES_BASE` and
    a set of filters (`wp_edge_images_base`, `wp_edge_images_quality`,
    `wp_edge_images_widths`, `wp_edge_images_url`, `wp_edge_images_disable_thumbnails`).
  - No-op when no base is configured, and skips non-raster files such as SVG.

[1.0.0]: https://github.com/mantekio/wp-edge-images/releases/tag/v1.0.0
