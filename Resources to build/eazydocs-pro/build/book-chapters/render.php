<?php
/**
 * Book Chapters/Tutorials Block - Server Side Render
 *
 * @package EazyDocsPro
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get block attributes
 */
$attributes = $attributes ?? [];

$docs_slug_format     = $attributes['docsSlugFormat'] ?? '1';
$exclude              = $attributes['exclude'] ?? [];
$show_section_count   = $attributes['showSectionCount'] ?? 6;
$ppp_doc_items        = $attributes['pppDocItems'] ?? -1;
$main_doc_excerpt     = $attributes['mainDocExcerpt'] ?? 15;
$is_masonry           = $attributes['masonry'] ?? false;
$order                = $attributes['order'] ?? 'ASC';
$book_chapter_prefix  = $attributes['bookChapterPrefix'] ?? '';
$prefix_auto_numbering = $attributes['prefixAutoNumbering'] ?? true;
$block_id             = $attributes['blockId'] ?? 'bc-' . uniqid();

// Style attributes
$tab_title_normal_color       = $attributes['tabTitleNormalColor'] ?? '';
$tab_title_active_color       = $attributes['tabTitleActiveColor'] ?? '';
$tab_title_active_border_color = $attributes['tabTitleActiveBorderColor'] ?? '';
$docs_title_color             = $attributes['docsTitleColor'] ?? '';
$docs_excerpt_color           = $attributes['docsExcerptColor'] ?? '';
$item_title_color             = $attributes['itemTitleColor'] ?? '';
$item_list_title_color        = $attributes['itemListTitleColor'] ?? '';
$item_list_title_hover_color  = $attributes['itemListTitleHoverColor'] ?? '';
$item_box_margin              = $attributes['itemBoxMargin'] ?? [];
$item_box_padding             = $attributes['itemBoxPadding'] ?? [];
$item_box_border              = $attributes['itemBoxBorder'] ?? [];
$item_box_border_radius       = $attributes['itemBoxBorderRadius'] ?? [];
$item_box_background          = $attributes['itemBoxBackground'] ?? '';
$item_box_background_hover    = $attributes['itemBoxBackgroundHover'] ?? '';
$item_box_border_hover_color  = $attributes['itemBoxBorderHoverColor'] ?? '';

// Parse exclude IDs
$exclude_ids = [];
if ( ! empty( $exclude ) ) {
	foreach ( $exclude as $token ) {
		$parts = explode( ' | ', $token );
		if ( ! empty( $parts[0] ) && is_numeric( $parts[0] ) ) {
			$exclude_ids[] = intval( $parts[0] );
		}
	}
}

// Masonry classes
$masonry_layout = $is_masonry ? 'ezd-column-3 ezd-masonry' : '';
$masonry_attr   = $is_masonry ? 'ezd-massonry-col="3"' : '';

/**
 * Get the parent docs with query
 */
$query_args = array(
	'post_type'  => 'docs',
	'parent'     => 0,
	'sort_order' => $order,
);

if ( ! empty( $exclude_ids ) ) {
	$query_args['exclude'] = $exclude_ids;
}

$parent_docs = get_pages( $query_args );

/**
 * Docs re-arrange according to menu order
 */
if ( $parent_docs ) {
	usort( $parent_docs, function ( $a, $b ) {
		return $a->menu_order - $b->menu_order;
	} );
}

/**
 * Get the doc sections
 */
$docs = [];
if ( $parent_docs ) {
	foreach ( $parent_docs as $root ) {
		$sections = get_children( array(
			'post_parent'    => $root->ID,
			'post_type'      => 'docs',
			'post_status'    => 'publish',
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
			'posts_per_page' => ! empty( $show_section_count ) && $show_section_count > 0 ? $show_section_count : -1,
		) );

		$docs[] = array(
			'doc'      => $root,
			'sections' => $sections,
		);
	}
}

/**
 * Generate dynamic styles
 */
$dynamic_styles = '';

if ( $tab_title_normal_color ) {
	$dynamic_styles .= "
		#{$block_id} .book-chapter-nav .nav-item a,
		#{$block_id} .book-chapter-nav .nav-item a span.chapter-part {
			color: {$tab_title_normal_color};
		}
	";
}

if ( $tab_title_active_color ) {
	$dynamic_styles .= "
		#{$block_id} .book-chapter-nav .nav-item.active a,
		#{$block_id} .book-chapter-nav .nav-item.active span.chapter-part {
			color: {$tab_title_active_color} !important;
		}
	";
}

if ( $tab_title_active_border_color ) {
	$dynamic_styles .= "
		#{$block_id} .book-chapter-nav .nav-item.active {
			border-color: {$tab_title_active_border_color};
		}
	";
}

if ( $docs_title_color ) {
	$dynamic_styles .= "
		#{$block_id} .docs4-heading h3 {
			color: {$docs_title_color};
		}
	";
}

if ( $docs_excerpt_color ) {
	$dynamic_styles .= "
		#{$block_id} .docs4-heading p {
			color: {$docs_excerpt_color};
		}
	";
}

if ( $item_title_color ) {
	$dynamic_styles .= "
		#{$block_id} .ezd_item_title {
			color: {$item_title_color};
		}
	";
}

if ( $item_list_title_color ) {
	$dynamic_styles .= "
		#{$block_id} .ezd_item_list_title,
		#{$block_id} .ezd_item_list_title span {
			color: {$item_list_title_color};
		}
	";
}

if ( $item_list_title_hover_color ) {
	$dynamic_styles .= "
		#{$block_id} .ezd_item_list_title:hover,
		#{$block_id} .ezd_item_list_title span:hover {
			color: {$item_list_title_hover_color};
		}
	";
}

// Item box styles
$box_styles = [];

if ( ! empty( $item_box_margin ) ) {
	$margin = sprintf(
		'%s %s %s %s',
		$item_box_margin['top'] ?? '0',
		$item_box_margin['right'] ?? '0',
		$item_box_margin['bottom'] ?? '0',
		$item_box_margin['left'] ?? '0'
	);
	if ( trim( str_replace( '0', '', $margin ) ) !== '' ) {
		$box_styles[] = "margin: {$margin}";
	}
}

if ( ! empty( $item_box_padding ) ) {
	$padding = sprintf(
		'%s %s %s %s',
		$item_box_padding['top'] ?? '0',
		$item_box_padding['right'] ?? '0',
		$item_box_padding['bottom'] ?? '0',
		$item_box_padding['left'] ?? '0'
	);
	if ( trim( str_replace( '0', '', $padding ) ) !== '' ) {
		$box_styles[] = "padding: {$padding}";
	}
}

if ( ! empty( $item_box_border ) && ! empty( $item_box_border['width'] ) ) {
	$border_width = $item_box_border['width'] ?? '1px';
	$border_style = $item_box_border['style'] ?? 'solid';
	$border_color = $item_box_border['color'] ?? '#e5e7eb';
	$box_styles[] = "border: {$border_width} {$border_style} {$border_color}";
}

if ( ! empty( $item_box_border_radius ) ) {
	$radius = sprintf(
		'%s %s %s %s',
		$item_box_border_radius['top'] ?? '0',
		$item_box_border_radius['right'] ?? '0',
		$item_box_border_radius['bottom'] ?? '0',
		$item_box_border_radius['left'] ?? '0'
	);
	if ( trim( str_replace( '0', '', $radius ) ) !== '' ) {
		$box_styles[] = "border-radius: {$radius}";
	}
}

if ( $item_box_background ) {
	$box_styles[] = "background: {$item_box_background}";
}

if ( ! empty( $box_styles ) ) {
	$dynamic_styles .= "
		#{$block_id} .topic_list_item {
			" . implode( '; ', $box_styles ) . ";
		}
	";
}

if ( $item_box_background_hover ) {
	$dynamic_styles .= "
		#{$block_id} .topic_list_item:hover {
			background: {$item_box_background_hover};
		}
	";
}

if ( $item_box_border_hover_color ) {
	$dynamic_styles .= "
		#{$block_id} .topic_list_item:hover {
			border-color: {$item_box_border_hover_color};
		}
	";
}

// Output styles if any
if ( $dynamic_styles ) {
	echo '<style>' . wp_strip_all_tags( $dynamic_styles ) . '</style>';
}

// Check if we have docs
if ( empty( $parent_docs ) ) {
	echo '<div class="book-chapters-empty"><p>' . esc_html__( 'No documentation found. Please create some docs first.', 'eazydocs-pro' ) . '</p></div>';
	return;
}
?>

<div id="<?php echo esc_attr( $block_id ); ?>" class="docs4 wp-block-eazydocs-pro-book-chapters">
	<div id="bookchapter" class="doc4-nav-bar">
		<div class="ezd-container p-0">
			<ul id="bcNav" class="book-chapter-nav ezd-list-unstyled">
				<?php
				$part_no = 1;
				if ( $parent_docs ) :
					foreach ( $parent_docs as $i => $doc ):
						$active          = ( $i == 0 ) ? ' active' : '';
						$post_title_slug = $doc->post_name;

						if ( $docs_slug_format == 1 ) {
							$href = '#doc-4' . $post_title_slug;
						} else {
							$href = '#doc-4' . $block_id . '-' . $doc->ID;
						}
						?>
						<li class="nav-item<?php echo esc_attr( $active ); ?>">
							<a href="<?php echo esc_attr( $href ); ?>" class="nav-link ezd_tab_title">
								<?php if ( ! empty( $book_chapter_prefix ) ) : ?>
									<span class="chapter-part">
										<?php
										if ( $prefix_auto_numbering ) {
											echo esc_html( $book_chapter_prefix . ' ' . $part_no++ );
										} else {
											echo esc_html( $book_chapter_prefix ) . ' ';
										}
										?>
									</span>
								<?php endif; ?>
								<?php echo wp_kses_post( $doc->post_title ); ?>
							</a>
						</li>
					<?php
					endforeach;
				endif;
				?>
			</ul>
		</div>
	</div>

	<div class="copic-contentn ezd-container p-0">
		<?php
		if ( ! empty( $docs ) ):
			foreach ( $docs as $i => $main_doc ):
				// Active Doc ID
				if ( $docs_slug_format == 1 ) {
					$doc_id = $main_doc['doc']->post_name;
				} else {
					$doc_id = "{$block_id}-{$main_doc['doc']->ID}";
				}
				?>
				<div id="doc-4<?php echo esc_attr( $doc_id ); ?>" class="doc_section_wrap">
					<div class="ezd-grid ezd-grid-cols-12">
						<div class="ezd-lg-col-12 ezd-md-col-12 ezd-grid-column-full">
							<div class="docs4-heading">
								<h3><?php echo wp_kses_post( $main_doc['doc']->post_title ); ?></h3>
								<?php
								if ( strlen( trim( $main_doc['doc']->post_excerpt ) ) != 0 ) {
									echo wp_kses_post( wpautop( wp_trim_words( $main_doc['doc']->post_excerpt, $main_doc_excerpt, '' ) ) );
								} else {
									echo wp_kses_post( wpautop( wp_trim_words( $main_doc['doc']->post_content, $main_doc_excerpt, '' ) ) );
								}
								?>
							</div>
						</div>
					</div>

					<div>
						<div class="ezd-grid ezd-grid-cols-12 <?php echo esc_attr( $masonry_layout ); ?>" <?php echo wp_kses_post( $masonry_attr ); ?>>
							<?php
							$sections = 1;
							if ( ! empty( $main_doc['sections'] ) ):
								foreach ( $main_doc['sections'] as $section ):
									$section_count = $sections++;
									?>
									<div class="ezd-lg-col-4 ezd-md-col-6 ezd-grid-column-full">
										<div class="topic_list_item">
											<?php if ( ! empty( $section->post_title ) ): ?>
												<a class="doc4-section-title" href="<?php echo esc_url( get_permalink( $section->ID ) ); ?>">
													<h4 class="ezd_item_title"><?php echo wp_kses_post( $section->post_title ); ?></h4>
												</a>
											<?php endif; ?>
											<ul class="navbar-nav">
												<?php
												$doc_items = get_children(
													array(
														'post_parent'    => $section->ID,
														'post_type'      => 'docs',
														'post_status'    => 'publish',
														'orderby'        => 'menu_order',
														'order'          => 'ASC',
														'posts_per_page' => ! empty( $ppp_doc_items ) && $ppp_doc_items > 0 ? $ppp_doc_items : -1,
													)
												);
												$child = 1;
												foreach ( $doc_items as $doc_item ):
													$child_count = $child++;
													?>
													<li>
														<a class="ezd_item_list_title" href="<?php echo esc_url( get_permalink( $doc_item->ID ) ); ?>">
															<span class="chapter_counter"><?php echo esc_html( $section_count . '.' . $child_count . ' ' ); ?></span>
															<?php echo wp_kses_post( $doc_item->post_title ); ?>
														</a>
													</li>
												<?php
												endforeach;
												?>
											</ul>
										</div>
									</div>
								<?php
								endforeach;
							endif;
							?>
						</div>
					</div>
				</div>
			<?php
			endforeach;
		endif;
		?>
	</div>
</div>

<script>
;(function($) {
	'use strict';

	$(document).ready(function() {
		var blockId = '<?php echo esc_js( $block_id ); ?>';
		var $block = $('#' + blockId);
		
		if (!$block.length) return;

		// Sticky Nav Function
		function navFixed() {
			var windowWidth = $(window).width();
			var $navBar = $block.find('.doc4-nav-bar');
			
			if ($navBar.length && windowWidth > 330) {
				var navBarTop = $navBar.offset().top;
				var navBarHeight = $navBar.outerHeight();

				$(window).on('scroll.bookChapter' + blockId, function() {
					var scroll = $(window).scrollTop();
					if (scroll >= navBarTop) {
						$navBar.addClass('dock4-nav-sticky');
					} else {
						$navBar.removeClass('dock4-nav-sticky');
					}
				});
			}
		}
		navFixed();

		// Masonry layout
		function ezd_docs4_masonry() {
			$block.find('.ezd-masonry').each(function() {
				var $masonryContainer = $(this);
				var parentId = $masonryContainer.closest('.doc_section_wrap').attr('id');

				if (!parentId) {
					return;
				}

				var masonryCols = $masonryContainer.attr('ezd-massonry-col');
				var masonryColumns = parseInt(masonryCols) || 3;

				if ($(window).width() <= 1024) {
					masonryColumns = 2;
				}

				if ($(window).width() <= 768) {
					masonryColumns = 1;
				}

				var count = 0;
				var content = $masonryContainer.children();

				if ($masonryContainer.prev('.ezd-masonry-columns').length === 0) {
					$masonryContainer.before('<div id="ezd-masonry-columns-' + parentId + '" class="ezd-masonry-columns"></div>');
				}

				content.each(function(index) {
					count++;
					$(this).addClass(parentId + '-ezd-masonry-sort-' + count);

					if (count == masonryColumns) {
						count = 0;
					}
				});

				var $columnsWrapper = $('#ezd-masonry-columns-' + parentId);
				$columnsWrapper.empty();

				for (var i = 1; i <= masonryColumns; i++) {
					$columnsWrapper.append('<div class="' + parentId + '-ezd-masonry-' + i + '"></div>');
					$('.' + parentId + '-ezd-masonry-sort-' + i).appendTo('.' + parentId + '-ezd-masonry-' + i);
				}
			});
		}
		ezd_docs4_masonry();

		// ScrollSpy - Fixed version
		var $topMenu = $block.find('.book-chapter-nav');
		var $menuItems = $topMenu.find('a');
		var topMenuHeight = $topMenu.outerHeight() + 50;
		var lastId = '';

		// Build scroll items array properly
		var scrollItems = [];
		$menuItems.each(function() {
			var href = $(this).attr('href');
			if (href && href !== '#' && href.indexOf('#') === 0) {
				var $target = $(href);
				if ($target.length) {
					scrollItems.push({
						id: href.substring(1),
						element: $target,
						link: $(this)
					});
				}
			}
		});

		// ScrollSpy on scroll
		$(window).on('scroll.scrollspy' + blockId, function() {
			var fromTop = $(window).scrollTop() + topMenuHeight;
			var currentItem = null;

			// Find current section
			for (var i = 0; i < scrollItems.length; i++) {
				var item = scrollItems[i];
				if (item.element.offset().top <= fromTop) {
					currentItem = item;
				}
			}

			// Update active state
			var currentId = currentItem ? currentItem.id : '';
			
			if (lastId !== currentId) {
				lastId = currentId;
				
				// Remove active from all
				$topMenu.find('.nav-item').removeClass('active');
				
				if (currentItem) {
					// Add active to current
					currentItem.link.closest('.nav-item').addClass('active');
				} else if (scrollItems.length > 0) {
					// Default to first item if nothing is active
					scrollItems[0].link.closest('.nav-item').addClass('active');
				}
			}
		});

		// Smooth scroll on tab click
		$menuItems.on('click', function(e) {
			e.preventDefault();
			var href = $(this).attr('href');
			var $target = $(href);
			
			if ($target.length) {
				// Update active state immediately
				$topMenu.find('.nav-item').removeClass('active');
				$(this).closest('.nav-item').addClass('active');
				lastId = href.substring(1);
				
				// Smooth scroll
				$('html, body').animate({
					scrollTop: $target.offset().top - topMenuHeight + 20
				}, 500);
			}
		});

		// Trigger initial scroll check
		$(window).trigger('scroll.scrollspy' + blockId);
	});
})(jQuery);
</script>
