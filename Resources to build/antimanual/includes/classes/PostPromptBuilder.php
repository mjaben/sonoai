<?php
/**
 * PostPromptBuilder Class
 *
 * Centralizes all AI prompt-building logic for Advanced Features and SEO Settings
 * so that every feature that generates posts (Generate Post, Auto Posting, etc.)
 * produces consistent, maintainable prompts from one place.
 *
 * @package Antimanual
 * @since   2.7.0
 */

namespace Antimanual;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PostPromptBuilder
 *
 * Builds reusable prompt instruction strings for post generation features.
 * All methods are static so they can be called without instantiation.
 *
 * @since 2.7.0
 */
class PostPromptBuilder {
	/**
	 * Normalize an article word-count range.
	 *
	 * @param int $min_words   Requested minimum words.
	 * @param int $max_words   Requested maximum words.
	 * @param int $default_min Fallback minimum words.
	 * @param int $default_max Fallback maximum words.
	 * @return array{min:int,max:int}
	 */
	public static function normalize_word_length_range( int $min_words, int $max_words, int $default_min = 800, int $default_max = 1200 ): array {
		$min = $min_words > 0 ? $min_words : $default_min;
		$max = $max_words > 0 ? $max_words : $default_max;

		$min = max( 100, min( 5000, $min ) );
		$max = max( 100, min( 5000, $max ) );

		if ( $max < $min ) {
			$max = $min;
		}

		return [
			'min' => $min,
			'max' => $max,
		];
	}

	/**
	 * Rewrite Gutenberg content so it lands within the requested word range.
	 *
	 * @param string $content   Gutenberg content.
	 * @param int    $min_words Minimum words.
	 * @param int    $max_words Maximum words.
	 * @param array  $context   Optional prompt context.
	 * @return string|\WP_Error
	 */
	public static function fit_content_to_word_range( string $content, int $min_words, int $max_words, array $context = [] ) {
		$range         = self::normalize_word_length_range( $min_words, $max_words, $min_words, $max_words );
		$current_words = \Antimanual\Utils\count_post_words( $content );

		if ( $current_words >= $range['min'] && $current_words <= $range['max'] ) {
			return $content;
		}

		$language      = sanitize_text_field( (string) ( $context['language'] ?? 'English' ) );
		$tone          = sanitize_text_field( (string) ( $context['tone'] ?? '' ) );
		$focus_keyword = sanitize_text_field( (string) ( $context['focus_keyword'] ?? '' ) );
		$action        = $current_words < $range['min'] ? 'expand' : 'trim';
		$keyword_rule  = '';

		if ( '' !== $focus_keyword ) {
			$keyword_rule = sprintf(
				'Keep the focus keyword "%s" naturally present and preserve any existing SEO intent around it.',
				$focus_keyword
			);
		}

		$system_prompt = 'You are an expert WordPress content editor.

Rewrite the provided post content so the final result lands within the requested word-count range.

Rules:
- Return ONLY valid WordPress/Gutenberg post content.
- Preserve the existing block structure, headings, lists, FAQ blocks, placeholders, and links whenever possible.
- Keep the same language, tone, topic, and factual meaning.
- Do not add explanations or markdown fences.
- The final content MUST be between ' . $range['min'] . ' and ' . $range['max'] . ' words inclusive.';

		$user_prompt = 'Current word count: ' . $current_words . '
Requested range: ' . $range['min'] . '-' . $range['max'] . ' words
Primary action: ' . $action . '
Language: ' . $language . '
' . ( '' !== $tone ? 'Tone: ' . $tone . "\n" : '' ) . $keyword_rule . '

CONTENT TO REWRITE:
' . $content;

		$input = [
			[
				'role'    => 'system',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $system_prompt,
					],
				],
			],
			[
				'role'    => 'user',
				'content' => [
					[
						'type' => 'input_text',
						'text' => $user_prompt,
					],
				],
			],
		];

		$max_output_tokens = 4000 + intval( $range['max'] * 1.5 );
		$response          = AIProvider::get_reply( $input, '', '', $max_output_tokens );

		if ( ! is_string( $response ) ) {
			return new \WP_Error(
				'content_length_adjust_failed',
				__( 'Failed to adjust the generated content to the requested word-count range.', 'antimanual' )
			);
		}

		$adjusted       = AIResponseCleaner::clean_gutenberg_content( $response );
		$adjusted_words = \Antimanual\Utils\count_post_words( $adjusted );

		if ( $adjusted_words < $range['min'] || $adjusted_words > $range['max'] ) {
			return new \WP_Error(
				'content_length_out_of_range',
				sprintf(
					/* translators: 1: actual word count, 2: minimum words, 3: maximum words */
					__( 'Generated content ended at %1$d words, which is outside the requested %2$d-%3$d word range.', 'antimanual' ),
					$adjusted_words,
					$range['min'],
					$range['max']
				)
			);
		}

		return $adjusted;
	}

	// ─── SEO Settings ─────────────────────────────────────────────────────────

	/**
	 * Build the `advanced_instructions` block for SEO-related settings.
	 *
	 * Produces the same instructions that were previously duplicated inside
	 * PostGenerator and AutoPosting so both features behave identically.
	 *
	 * @param array $options {
	 *     SEO options.
	 *
	 *     @type string       $focus_keyword             The primary focus keyword. Empty string if none.
	 *     @type bool         $optimize_for_seo          Whether to inject full SEO optimisation rules.
	 *     @type bool         $generate_meta_description Whether to request a meta description from the AI.
	 *     @type bool         $generate_featured_image   Whether to request an image generation prompt.
	 *     @type string|array $focus_keywords            Alias for multi-keyword support. Merged with $focus_keyword.
	 * }
	 * @return array {
	 *     @type string $instructions  Prose instructions appended to the system/advanced block.
	 *     @type string $json_fields   JSON example fields to include in the JSON schema hint.
	 * }
	 */
	public static function build_seo_instructions( array $options ): array {
		$focus_keyword             = $options['focus_keyword']             ?? '';
		$optimize_for_seo          = (bool) ( $options['optimize_for_seo']          ?? false );
		$generate_meta_description = (bool) ( $options['generate_meta_description'] ?? false );
		$generate_featured_image   = (bool) ( $options['generate_featured_image']   ?? false );

		// Support both single keyword string and multi-keyword array.
		if ( empty( $focus_keyword ) && ! empty( $options['focus_keywords'] ) ) {
			$kws           = (array) $options['focus_keywords'];
			$focus_keyword = implode( ', ', array_filter( array_map( 'sanitize_text_field', $kws ) ) );
		}

		$instructions = '';
		$json_fields  = '';

		// Featured image prompt.
		if ( $generate_featured_image ) {
			$instructions .= 'Generate a detailed image generation prompt for a featured image that visually represents the article topic. The prompt should describe the scene, style, lighting, composition, and mood in detail. ';
			$json_fields  .= '"image_prompt": "A professional photorealistic image showing...",' . "\n";
		}

		// Meta description.
		if ( $generate_meta_description ) {
			$meta_desc_instruction = 'Generate an SEO meta description (120-160 characters).';
			if ( $optimize_for_seo && ! empty( $focus_keyword ) ) {
				$meta_desc_instruction .= sprintf(
					' The meta description MUST contain the focus keyword "%s" within the first 120 characters.',
					$focus_keyword
				);
			}
			$instructions .= $meta_desc_instruction . ' ';
			$json_fields  .= '"meta_description": "Discover how to...",' . "\n";
		}

		// Full SEO optimisation rules.
		if ( $optimize_for_seo && ! empty( $focus_keyword ) ) {
			$instructions .= sprintf(
				'
=== SEO OPTIMIZATION RULES (Score 100/100 in Rank Math / Yoast) ===
Focus keyword: "%1$s"

**Basic SEO (MANDATORY):**
- TITLE: Place the focus keyword "%1$s" within the FIRST 50%% of the title. The title should ideally start with or lead with the keyword.
- SLUG: The slug MUST contain the focus keyword "%1$s".
- CONTENT START: The focus keyword "%1$s" MUST appear in the first 10%% of the content (first paragraph).
- CONTENT BODY: Use the focus keyword naturally throughout the content.
- KEYWORD DENSITY: Maintain a keyword density of 1-1.5%% for "%1$s" (roughly 1-2 uses per 100 words). Do NOT exceed 2.5%%.

**Additional SEO (MANDATORY):**
- SUBHEADINGS: Include the focus keyword "%1$s" in at least one H2 or H3 subheading.
- EXTERNAL LINKS: Include 2-3 links to authoritative external sources/references using proper anchor tags inside paragraph blocks (e.g., <a href="https://example.com">descriptive text</a>). At least one must be a followed link (no rel="nofollow").
- IMAGE ALT TEXT: If images are included, at least one image alt text should contain the focus keyword.

**Title Readability (MANDATORY):**
- SENTIMENT: Use at least one positive or negative sentiment word in the title (e.g., "ultimate", "essential", "proven", "critical", "powerful", "effortless").
- POWER WORD: Include a power word in the title (e.g., "ultimate", "proven", "essential", "guaranteed", "exclusive", "comprehensive", "incredible", "remarkable").
- NUMBER: Include a number in the title when the topic permits (e.g., "7 Ways to...", "Top 10...", "5 Essential..."). This is strongly recommended but can be skipped if it does not fit the topic naturally.

**Content Readability (MANDATORY):**
- SHORT PARAGRAPHS: Keep every paragraph under 120 words. Break long paragraphs into shorter ones.
- Use varied sentence lengths for better readability.
',
				$focus_keyword
			);
		} elseif ( $optimize_for_seo ) {
			// SEO enabled but no specific focus keyword provided.
			$instructions .= '
=== SEO OPTIMIZATION ===
- Use keywords naturally in headings and the first paragraph.
- Write descriptive, engaging headings.
- Structure content for featured snippets where appropriate.
- Keep paragraphs under 120 words.
';
		}

		return [
			'instructions' => $instructions,
			'json_fields'  => $json_fields,
		];
	}

	// ─── Advanced Feature Instructions ────────────────────────────────────────

	/**
	 * Build the `advanced_instructions` block for Advanced Features settings.
	 *
	 * Produces identical instructions regardless of which feature (Generate Post,
	 * Auto Posting, etc.) calls this method.
	 *
	 * @param array $options {
	 *     Advanced feature options.
	 *
	 *     @type bool   $include_toc             Include a Table of Contents block.
	 *     @type bool   $include_faq             Include an FAQ section.
	 *     @type string $faq_block_type          'default' or 'advanced' (AAB plugin).
	 *     @type bool   $include_conclusion      Include a conclusion section.
	 *     @type bool   $suggest_internal_links  Add internal link placeholders.
	 *     @type bool   $include_content_images  Add content image placeholders.
	 *     @type string $pov                     Point of view slug (see POV_LABELS).
	 *     @type string $target_audience         Target audience label.
	 * }
	 * @return array {
	 *     @type string $instructions  Prose instructions appended to the system/advanced block.
	 *     @type string $json_fields   JSON example fields to include in the JSON schema hint.
	 * }
	 */
	public static function build_advanced_instructions( array $options ): array {
		$include_toc            = (bool) ( $options['include_toc']            ?? false );
		$include_faq            = (bool) ( $options['include_faq']            ?? false );
		$faq_block_type         = $options['faq_block_type']                   ?? 'default';
		$include_conclusion     = (bool) ( $options['include_conclusion']     ?? true );
		$suggest_internal_links = (bool) ( $options['suggest_internal_links'] ?? false );
		$include_content_images = (bool) ( $options['include_content_images'] ?? false );

		$instructions = '';
		$json_fields  = '';

		// Table of Contents.
		if ( $include_toc ) {
			$instructions .= 'Include a Table of Contents at the beginning using a Gutenberg core/list block with an unordered bullet list (<ul>) only. Do NOT use an ordered/numbered list (<ol>). ';
		}

		// FAQ section.
		if ( $include_faq ) {
			if ( 'advanced' === $faq_block_type ) {
				$instructions .= '

=== FAQ SECTION (MUST appear exactly ONCE, at the very end of the content) ===

Output ONE FAQ section using the Advanced Accordion Block (AAB) format below.
The entire FAQ must be inside a SINGLE group-accordion wrapper.
Inside that wrapper, add 5-8 accordion-item blocks (one per question).
Copy the structure EXACTLY — do not invent tags or change class names.

TEMPLATE (showing 2 items — add more items following the same pattern):

<!-- wp:aab/group-accordion -->
<div class="wp-block-aab-group-accordion">
  <!-- wp:aab/accordion-item -->
  <div class="wp-block-aab-accordion-item aagb__accordion_container panel">
    <div class="aagb__accordion_head">
      <div class="aagb__accordion_heading">
        <div class="head_content_wrapper">
          <div class="title_wrapper">
            <h5 class="aagb__accordion_title">First question here?</h5>
          </div>
        </div>
      </div>
      <div class="aagb__accordion_icon">
        <div class="aagb__icon_dashicons_box">
          <span class="aagb__icon dashicons dashicons-plus-alt2"></span>
        </div>
      </div>
    </div>
    <div class="aagb__accordion_body" role="region">
      <div class="aagb__accordion_component">
        <!-- wp:paragraph -->
        <p>Answer to the first question.</p>
        <!-- /wp:paragraph -->
      </div>
    </div>
  </div>
  <!-- /wp:aab/accordion-item -->
  <!-- wp:aab/accordion-item -->
  <div class="wp-block-aab-accordion-item aagb__accordion_container panel">
    <div class="aagb__accordion_head">
      <div class="aagb__accordion_heading">
        <div class="head_content_wrapper">
          <div class="title_wrapper">
            <h5 class="aagb__accordion_title">Second question here?</h5>
          </div>
        </div>
      </div>
      <div class="aagb__accordion_icon">
        <div class="aagb__icon_dashicons_box">
          <span class="aagb__icon dashicons dashicons-plus-alt2"></span>
        </div>
      </div>
    </div>
    <div class="aagb__accordion_body" role="region">
      <div class="aagb__accordion_component">
        <!-- wp:paragraph -->
        <p>Answer to the second question.</p>
        <!-- /wp:paragraph -->
      </div>
    </div>
  </div>
  <!-- /wp:aab/accordion-item -->
</div>
<!-- /wp:aab/group-accordion -->

RULES:
- Output the FAQ section ONCE — never repeat it.
- Do NOT output any content after the closing group-accordion tag.
- Do NOT add <!-- /wp:post-content --> or any other tags after the FAQ.
';
			} else {
				$instructions .= '

=== FAQ SECTION (MUST appear exactly ONCE, at the very end of the content) ===

Output ONE FAQ section using the WordPress Accordion block format below.
Each question/answer pair is its own standalone accordion block — they do NOT share a wrapper.
Output 5-8 separate accordion blocks (one per question).
Copy the structure EXACTLY — do not invent tags or change class names.

TEMPLATE (showing 2 items — add more items following the same pattern):

<!-- wp:accordion -->
<div role="group" class="wp-block-accordion">
  <!-- wp:accordion-item -->
  <div class="wp-block-accordion-item">
    <!-- wp:accordion-heading -->
    <h3 class="wp-block-accordion-heading">
      <button class="wp-block-accordion-heading__toggle">
        <span class="wp-block-accordion-heading__toggle-title">First question here?</span>
        <span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>
      </button>
    </h3>
    <!-- /wp:accordion-heading -->
    <!-- wp:accordion-panel -->
    <div role="region" class="wp-block-accordion-panel">
      <!-- wp:paragraph -->
      <p>Answer to the first question.</p>
      <!-- /wp:paragraph -->
    </div>
    <!-- /wp:accordion-panel -->
  </div>
  <!-- /wp:accordion-item -->
</div>
<!-- /wp:accordion -->

<!-- wp:accordion -->
<div role="group" class="wp-block-accordion">
  <!-- wp:accordion-item -->
  <div class="wp-block-accordion-item">
    <!-- wp:accordion-heading -->
    <h3 class="wp-block-accordion-heading">
      <button class="wp-block-accordion-heading__toggle">
        <span class="wp-block-accordion-heading__toggle-title">Second question here?</span>
        <span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>
      </button>
    </h3>
    <!-- /wp:accordion-heading -->
    <!-- wp:accordion-panel -->
    <div role="region" class="wp-block-accordion-panel">
      <!-- wp:paragraph -->
      <p>Answer to the second question.</p>
      <!-- /wp:paragraph -->
    </div>
    <!-- /wp:accordion-panel -->
  </div>
  <!-- /wp:accordion-item -->
</div>
<!-- /wp:accordion -->

RULES:
- Output the FAQ section ONCE — never repeat it.
- Do NOT output any content after the last closing accordion tag.
- Do NOT add <!-- /wp:post-content --> or any other tags after the FAQ.
';
			}
		}

		// Content images.
		if ( $include_content_images ) {
			$instructions .= 'Include 2-4 relevant images embedded within the post content. For each image, place a [CONTENT_IMAGE: brief description of the image] placeholder as its OWN standalone paragraph block — do NOT place it inline within a sentence or inside another paragraph. Use this exact format:

<!-- wp:paragraph --><p>[CONTENT_IMAGE: brief description of the image]</p><!-- /wp:paragraph -->

Place these image placeholders at logical locations (e.g., after an introduction paragraph or before a key section). The placeholder will be replaced with a real AI-generated image. ';
			$json_fields .= '"content_image_prompts": ["A professional image showing..."],' . "\n";
		}

		// Internal link placeholders.
		if ( $suggest_internal_links ) {
			$instructions .= 'Suggest 2-3 locations for internal links within the content using [INTERNAL_LINK: topic] placeholder. ';
		}

		return [
			'instructions' => $instructions,
			'json_fields'  => $json_fields,
		];
	}

	// ─── FAQ Section Context (for chunked generation) ─────────────────────────

	/**
	 * Build the section-context string for a FAQ section in chunked generation.
	 *
	 * Used by PostGenerator::generate_section_content() when the last section
	 * is a FAQ. Returns a self-contained prompt describing the block format
	 * so the AI outputs valid Gutenberg accordion blocks.
	 *
	 * @param string $faq_block_type 'default' or 'advanced' (AAB plugin).
	 * @return string
	 */
	public static function build_faq_section_context( string $faq_block_type ): string {
		if ( 'advanced' === $faq_block_type ) {
			return 'FAQ SECTION - Output ONLY the FAQ blocks using the Advanced Accordion Block plugin.

=== FAQ SECTION (output exactly ONCE) ===

Place ALL items inside a SINGLE group-accordion wrapper. Add 5-8 accordion-item blocks.
Copy the structure EXACTLY:

<!-- wp:aab/group-accordion -->
<div class="wp-block-aab-group-accordion">
  <!-- wp:aab/accordion-item -->
  <div class="wp-block-aab-accordion-item aagb__accordion_container panel">
    <div class="aagb__accordion_head">
      <div class="aagb__accordion_heading">
        <div class="head_content_wrapper">
          <div class="title_wrapper">
            <h5 class="aagb__accordion_title">First question here?</h5>
          </div>
        </div>
      </div>
      <div class="aagb__accordion_icon">
        <div class="aagb__icon_dashicons_box">
          <span class="aagb__icon dashicons dashicons-plus-alt2"></span>
        </div>
      </div>
    </div>
    <div class="aagb__accordion_body" role="region">
      <div class="aagb__accordion_component">
        <!-- wp:paragraph -->
        <p>Answer to the first question.</p>
        <!-- /wp:paragraph -->
      </div>
    </div>
  </div>
  <!-- /wp:aab/accordion-item -->
  <!-- wp:aab/accordion-item -->
  <div class="wp-block-aab-accordion-item aagb__accordion_container panel">
    <div class="aagb__accordion_head">
      <div class="aagb__accordion_heading">
        <div class="head_content_wrapper">
          <div class="title_wrapper">
            <h5 class="aagb__accordion_title">Second question here?</h5>
          </div>
        </div>
      </div>
      <div class="aagb__accordion_icon">
        <div class="aagb__icon_dashicons_box">
          <span class="aagb__icon dashicons dashicons-plus-alt2"></span>
        </div>
      </div>
    </div>
    <div class="aagb__accordion_body" role="region">
      <div class="aagb__accordion_component">
        <!-- wp:paragraph -->
        <p>Answer to the second question.</p>
        <!-- /wp:paragraph -->
      </div>
    </div>
  </div>
  <!-- /wp:aab/accordion-item -->
</div>
<!-- /wp:aab/group-accordion -->

RULES: Output the FAQ ONCE. Do NOT output any content after the closing group-accordion tag. Do not include any heading block before the accordion.';
		}

		return 'FAQ SECTION - Output ONLY the FAQ blocks using the WordPress Accordion block.

=== FAQ SECTION (output exactly ONCE) ===

Each question/answer pair is its own standalone accordion block. Output 5-8 separate blocks.
Copy the structure EXACTLY:

<!-- wp:accordion -->
<div role="group" class="wp-block-accordion">
  <!-- wp:accordion-item -->
  <div class="wp-block-accordion-item">
    <!-- wp:accordion-heading -->
    <h3 class="wp-block-accordion-heading">
      <button class="wp-block-accordion-heading__toggle">
        <span class="wp-block-accordion-heading__toggle-title">First question here?</span>
        <span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>
      </button>
    </h3>
    <!-- /wp:accordion-heading -->
    <!-- wp:accordion-panel -->
    <div role="region" class="wp-block-accordion-panel">
      <!-- wp:paragraph -->
      <p>Answer to the first question.</p>
      <!-- /wp:paragraph -->
    </div>
    <!-- /wp:accordion-panel -->
  </div>
  <!-- /wp:accordion-item -->
</div>
<!-- /wp:accordion -->

<!-- wp:accordion -->
<div role="group" class="wp-block-accordion">
  <!-- wp:accordion-item -->
  <div class="wp-block-accordion-item">
    <!-- wp:accordion-heading -->
    <h3 class="wp-block-accordion-heading">
      <button class="wp-block-accordion-heading__toggle">
        <span class="wp-block-accordion-heading__toggle-title">Second question here?</span>
        <span class="wp-block-accordion-heading__toggle-icon" aria-hidden="true">+</span>
      </button>
    </h3>
    <!-- /wp:accordion-heading -->
    <!-- wp:accordion-panel -->
    <div role="region" class="wp-block-accordion-panel">
      <!-- wp:paragraph -->
      <p>Answer to the second question.</p>
      <!-- /wp:paragraph -->
    </div>
    <!-- /wp:accordion-panel -->
  </div>
  <!-- /wp:accordion-item -->
</div>
<!-- /wp:accordion -->

RULES: Output the FAQ ONCE. Do NOT output any content after the last closing accordion tag. Do not include any heading block before the accordion items.';
	}

	// ─── Article Structure (Conclusion) ───────────────────────────────────────

	/**
	 * Build the conclusion instruction line for the content structure block.
	 *
	 * Returns the exact string to embed inside the numbered content-structure
	 * requirements list in the user prompt.
	 *
	 * @param bool   $include_conclusion Whether to require a conclusion.
	 * @param int    $min_length         Minimum word count (used to size the conclusion).
	 * @param string $bullet_prefix      The bullet label (e.g. "4." or "5.") — caller supplies.
	 * @return string
	 */
	public static function build_conclusion_instruction( bool $include_conclusion, int $min_length, string $bullet_prefix = '4.' ): string {
		if ( $include_conclusion ) {
			$conclusion_words = max( 50, intval( $min_length * 0.12 ) );
			return "{$bullet_prefix} **Comprehensive Conclusion** (at least {$conclusion_words} words)";
		}

		// Explicitly tell the AI NOT to add one.
		return "{$bullet_prefix} Do NOT add a conclusion section — end with the final content section.";
	}

	// ─── POV / Audience helpers ────────────────────────────────────────────────

	/**
	 * Human-readable labels for the point-of-view slug values.
	 *
	 * @return array<string, string>
	 */
	public static function pov_labels(): array {
		return [
			'first_person_singular' => 'first person singular (I, me, my)',
			'first_person_plural'   => 'first person plural (we, us, our)',
			'second_person'         => 'second person (you, your)',
			'third_person'          => 'third person (they, them, the reader)',
		];
	}

	/**
	 * Resolve a POV slug to its human-readable label.
	 *
	 * @param string $pov POV slug (e.g. 'first_person_singular').
	 * @return string
	 */
	public static function pov_label( string $pov ): string {
		$labels = self::pov_labels();
		return $labels[ $pov ] ?? 'third person (they, them, the reader)';
	}

	/**
	 * Build the target audience + POV lines for the user prompt mandatory parameters.
	 *
	 * @param string $pov             POV slug.
	 * @param string $target_audience Target audience label.
	 * @return string
	 */
	public static function build_audience_and_pov_prompt( string $pov, string $target_audience ): string {
		$pov_label = self::pov_label( $pov );
		$output    = '';

		if ( ! empty( $target_audience ) && 'general' !== strtolower( $target_audience ) ) {
			$output .= "* 🎯 TARGET AUDIENCE: \"{$target_audience}\"\n";
		} else {
			$output .= "* 🎯 TARGET AUDIENCE: General audience\n";
		}

		$output .= "* 👁️ POINT OF VIEW: Use {$pov_label} throughout the article.\n";

		return $output;
	}
}
