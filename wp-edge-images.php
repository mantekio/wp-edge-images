<?php
/**
 * Plugin Name: WP Edge Images
 * Plugin URI:  https://github.com/mantekio/wp-edge-images
 * Description: Stop WordPress generating intermediate image sizes and serve every
 *              size on the fly from an AWS Serverless Image Handler (or any
 *              Thumbor-compatible CDN). URL layer only: no AWS credentials, and it
 *              pairs with any S3 offload.
 * Version:     1.0.0
 * Author:      ManTek Technologies
 * Author URI:  https://www.mantek.io
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Full write-up: https://www.mantek.io/insights/wordpress-s3-media-pipeline
 *
 * Quick start (install as a must-use plugin in wp-content/mu-plugins/):
 *
 *     define( 'WP_EDGE_IMAGES_BASE', 'https://images.example.com' );
 *
 * With no base configured the plugin is a no-op, so it is safe to install first
 * and configure second.
 *
 * @package wp-edge-images
 */

defined( 'ABSPATH' ) || exit;

/**
 * The Serverless Image Handler / Thumbor CDN base URL, or '' to disable rewriting.
 */
function wpei_base() {
	$base = defined( 'WP_EDGE_IMAGES_BASE' ) ? WP_EDGE_IMAGES_BASE : '';
	return rtrim( (string) apply_filters( 'wp_edge_images_base', $base ), '/' );
}

/* 1. Stop WordPress generating intermediate sizes on upload. */
if ( apply_filters( 'wp_edge_images_disable_thumbnails', true ) ) {
	add_filter( 'intermediate_image_sizes_advanced', '__return_empty_array' );
}

/* 2. Helpers. */

/** Raster formats the handler can transform (skip SVG and anything else). */
function wpei_is_raster( $attachment_id ) {
	return in_array(
		get_post_mime_type( $attachment_id ),
		array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' ),
		true
	);
}

/** Resolve a requested size to a [width, height] request box (0 means auto). */
function wpei_box( $size, $meta = array() ) {
	if ( is_array( $size ) ) {
		return array( (int) ( $size[0] ?? 0 ), (int) ( $size[1] ?? 0 ) );
	}
	if ( 'full' === $size ) {
		return array( (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );
	}
	if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
		$sizes = wp_get_registered_image_subsizes();
		if ( isset( $sizes[ $size ] ) ) {
			return array( (int) $sizes[ $size ]['width'], (int) $sizes[ $size ]['height'] );
		}
	}
	return array( 0, 0 );
}

/** Concrete rendered dimensions (for the width/height attributes), keeping aspect. */
function wpei_rendered( $w, $h, $ow, $oh ) {
	if ( ! $ow || ! $oh ) {
		return array( $w, $h );
	}
	if ( $w && ! $h ) {
		return array( $w, (int) round( $w * $oh / $ow ) );
	}
	if ( $h && ! $w ) {
		return array( (int) round( $h * $ow / $oh ), $h );
	}
	if ( $w && $h ) {
		$scale = min( $w / $ow, $h / $oh );
		return array( (int) round( $ow * $scale ), (int) round( $oh * $scale ) );
	}
	return array( $ow, $oh );
}

/** Build an edge URL (Thumbor style by default; fully filterable). */
function wpei_url( $key, $w, $h ) {
	$base = wpei_base();
	if ( ! $base || ! $key ) {
		return '';
	}
	$key     = ltrim( $key, '/' );
	$quality = (int) apply_filters( 'wp_edge_images_quality', 80 );
	if ( $w || $h ) {
		$url = sprintf( '%s/fit-in/%dx%d/filters:format(auto):quality(%d)/%s', $base, (int) $w, (int) $h, $quality, $key );
	} else {
		$url = sprintf( '%s/filters:format(auto):quality(%d)/%s', $base, $quality, $key );
	}
	return apply_filters( 'wp_edge_images_url', $url, $key, $w, $h );
}

/** A srcset of edge URLs, never wider than the original. */
function wpei_srcset( $key, $orig_w ) {
	$orig_w = (int) $orig_w;
	$widths = apply_filters( 'wp_edge_images_widths', array( 320, 480, 640, 768, 1024, 1280, 1536, 1920, 2048 ) );
	if ( $orig_w ) {
		$widths[] = $orig_w;
	}
	$widths = array_unique( array_filter( array_map( 'intval', $widths ) ) );
	sort( $widths );
	$parts = array();
	foreach ( $widths as $w ) {
		if ( $orig_w && $w > $orig_w ) {
			continue;
		}
		$url = wpei_url( $key, $w, 0 );
		if ( $url ) {
			$parts[] = $url . ' ' . $w . 'w';
		}
	}
	return implode( ', ', $parts );
}

/** The stored relative key for an attachment, or '' . */
function wpei_key( $attachment_id ) {
	return (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
}

/* 3. Rewrite the single-image src (featured images, blocks, the_post_thumbnail). */
add_filter( 'wp_get_attachment_image_src', function ( $image, $attachment_id, $size ) {
	if ( ! $image || ! wpei_base() || ! wpei_is_raster( $attachment_id ) ) {
		return $image;
	}
	$key = wpei_key( $attachment_id );
	if ( ! $key ) {
		return $image;
	}
	$meta = (array) wp_get_attachment_metadata( $attachment_id );
	list( $bw, $bh ) = wpei_box( $size, $meta );
	$url = wpei_url( $key, $bw, $bh );
	if ( ! $url ) {
		return $image;
	}
	list( $rw, $rh ) = wpei_rendered( $bw, $bh, (int) ( $meta['width'] ?? 0 ), (int) ( $meta['height'] ?? 0 ) );
	$image[0] = $url;
	$image[1] = $rw ? $rw : $image[1];
	$image[2] = $rh ? $rh : $image[2];
	return $image;
}, 10, 3 );

/* 4. Synthesize srcset and sizes, since intermediate sizes no longer exist. */
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment, $size ) {
	if ( ! wpei_base() || ! wpei_is_raster( $attachment->ID ) ) {
		return $attr;
	}
	$key = wpei_key( $attachment->ID );
	if ( ! $key ) {
		return $attr;
	}
	$meta   = (array) wp_get_attachment_metadata( $attachment->ID );
	$srcset = wpei_srcset( $key, (int) ( $meta['width'] ?? 0 ) );
	if ( $srcset ) {
		$attr['srcset'] = $srcset;
		if ( empty( $attr['sizes'] ) ) {
			list( $bw ) = wpei_box( $size, $meta );
			$attr['sizes'] = $bw ? '(max-width: ' . $bw . 'px) 100vw, ' . $bw . 'px' : '100vw';
		}
	}
	return $attr;
}, 10, 3 );

/* 5. Rewrite inline content images (WP 6.0+), which have no recorded sizes to use. */
add_filter( 'wp_content_img_tag', function ( $tag, $context, $attachment_id ) {
	if ( ! wpei_base() || ! $attachment_id || ! wpei_is_raster( $attachment_id ) ) {
		return $tag;
	}
	$key = wpei_key( $attachment_id );
	if ( ! $key ) {
		return $tag;
	}
	$meta   = (array) wp_get_attachment_metadata( $attachment_id );
	$orig_w = (int) ( $meta['width'] ?? 0 );

	$full = wpei_url( $key, $orig_w, 0 );
	if ( $full ) {
		$tag = preg_replace( '/\bsrc=("|\')[^"\']*\1/', 'src="' . esc_url( $full ) . '"', $tag, 1 );
	}
	$srcset = wpei_srcset( $key, $orig_w );
	if ( $srcset ) {
		if ( preg_match( '/\bsrcset=("|\')/', $tag ) ) {
			$tag = preg_replace( '/\bsrcset=("|\')[^"\']*\1/', 'srcset="' . esc_attr( $srcset ) . '"', $tag, 1 );
		} else {
			$tag = preg_replace( '/<img\b/', '<img srcset="' . esc_attr( $srcset ) . '"', $tag, 1 );
		}
	}
	return $tag;
}, 10, 3 );
