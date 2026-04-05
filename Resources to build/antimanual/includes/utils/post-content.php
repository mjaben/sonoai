<?php
/**
 * Post Content Utilities
 *
 * Helper functions for extracting and processing WordPress post content.
 *
 * @package Antimanual
 * @since 2.8.0
 */

namespace Antimanual\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursively extract HTML from parsed WordPress blocks.
 *
 * @param array $blocks Parsed WordPress blocks.
 * @return string Complete HTML from all blocks.
 */
function extract_block_html( $blocks ) {
	$html_parts = [];
	
	foreach ( $blocks as $block ) {
		if ( empty( $block ) ) {
			continue;
		}
		
		$block_html = '';
		
		// If the block has innerContent, process it
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$inner_block_index = 0;
			
			// Process innerContent array, replacing nulls with rendered innerBlocks
			foreach ( $block['innerContent'] as $content ) {
				if ( $content === null ) {
					// This is a placeholder for an inner block
					if ( isset( $block['innerBlocks'][ $inner_block_index ] ) ) {
						$block_html .= extract_block_html( [ $block['innerBlocks'][ $inner_block_index ] ] );
						$inner_block_index++;
					}
				} else {
					$block_html .= $content;
				}
			}
		} elseif ( isset( $block['innerHTML'] ) ) {
			// Fallback to innerHTML if available
			$block_html .= $block['innerHTML'];
		}
		
		if ( ! empty( $block_html ) ) {
			$html_parts[] = $block_html;
		}
	}
	
	// Join blocks with newlines to prevent word concatenation
	return implode( "\n", $html_parts );
}

/**
 * Extract clean HTML from WordPress post content.
 *
 * This function parses WordPress block content and removes unwanted elements
 * like style tags, script tags, and SVG elements before returning the HTML.
 *
 * @param string $post_content Raw post_content from WordPress.
 * @param array  $options {
 *     Optional. Configuration options for content extraction.
 *
 *     @type bool $remove_styles  Remove style tags. Default true.
 *     @type bool $remove_scripts Remove script tags. Default true.
 *     @type bool $remove_svg     Remove SVG elements. Default true.
 * }
 * @return string Clean HTML content.
 */
function extract_post_html( $post_content, $options = [] ) {
	$defaults = [
		'remove_styles'  => true,
		'remove_scripts' => true,
		'remove_svg'     => true,
	];

	$options = wp_parse_args( $options, $defaults );

	// Parse WordPress blocks first
	$parsed_blocks = parse_blocks( $post_content );
	$html_content = extract_block_html( $parsed_blocks );

	// Parse as HTML with DOMDocument.
	$dom = new \DOMDocument();
	
	// Suppress warnings for malformed HTML.
	libxml_use_internal_errors( true );
	
	// Load HTML with UTF-8 encoding.
	$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html_content, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
	
	// Clear errors.
	libxml_clear_errors();

	// Build XPath for selecting unwanted elements.
	$xpath = new \DOMXPath( $dom );
	$selectors = [];

	if ( $options['remove_styles'] ) {
		$selectors[] = '//style';
	}
	if ( $options['remove_scripts'] ) {
		$selectors[] = '//script';
	}
	if ( $options['remove_svg'] ) {
		$selectors[] = '//svg';
	}

	// Remove unwanted elements.
	foreach ( $selectors as $selector ) {
		$nodes = $xpath->query( $selector );
		if ( $nodes ) {
			foreach ( $nodes as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	// Get the cleaned HTML.
	$html = $dom->saveHTML();

	// Remove the XML declaration and meta tags added by DOMDocument.
	$html = preg_replace( '/^<!DOCTYPE.+?>/', '', $html );
	$html = preg_replace( '/<\?xml.+?\?>/', '', $html );
	$html = str_replace( [ '<html>', '</html>', '<body>', '</body>' ], '', $html );

	return trim( $html );
}

/**
 * Extract plain text from WordPress post content.
 *
 * Similar to extract_post_html but returns plain text instead of HTML.
 *
 * @param string $post_content Raw post_content from WordPress.
 * @param array  $options      Same options as extract_post_html().
 * @return string Plain text content.
 */
function extract_post_text( $post_content, $options = [] ) {
	$clean_html = extract_post_html( $post_content, $options );
	
	// Strip all remaining HTML tags and decode entities.
	$text = wp_strip_all_tags( $clean_html );
	$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	
	// Normalize whitespace.
	$text = preg_replace( '/\s+/', ' ', $text );
	
	return trim( $text );
}

/**
 * Count words in WordPress post content.
 *
 * This provides an accurate word count by first removing unwanted elements
 * (styles, scripts, SVGs) before counting.
 *
 * @param string $post_content Raw post_content from WordPress.
 * @return int Number of words.
 */
function count_post_words( $post_content ) {
	$text = extract_post_text( $post_content );
	
	// Use universal word counting method that works with all languages
	// Split on whitespace and count non-empty segments
	$words = preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$word_count = count( $words );
	
	return $word_count;
}
