<?php
namespace eazyDocsPro\Frontend;

class Assistant {

	public function __construct() {

		add_action( 'eazydocs_assistant', [ $this, 'render_assistant' ] );
		add_action( 'assistant_tabs', [ $this, 'assistant_tabs' ] );

		// kbase and contact
		add_action( 'eazydocs_assistant_tab', [ $this, 'assistant_tab_kbase' ], 1, 10 );
		add_action( 'eazydocs_assistant_tab', [ $this, 'eazydocs_assistant_tab_contact' ], 2, 20 );

		add_action( 'wp_ajax_eazydocs_kbase_instant', [ $this, 'eazydocs_kbase_instant' ] );
		add_action( 'wp_ajax_nopriv_eazydocs_kbase_instant', [ $this, 'eazydocs_kbase_instant' ] );

		$this->contact_support();
	}

	/**
	 * Add Knowledge Base Tab
	 */
	function assistant_tab_kbase( $tabs = [] ) {
		$ed_options    = get_option( 'eazydocs_settings' ); // prefix of framework
		$kb_visibility = $ed_options['assistant_tab_settings']['kb_visibility'] ?? '1';

		if ( '1' === $kb_visibility ) {
			$tabs[] = [
				'id'      => 'kbase',
				'heading' => __( 'Knowledge Base', 'eazydocs-pro' ),
				'content' => $this->default_posts()
			];
		}

		return $tabs;
	}


	/**
	 * Default Posts
	 */
	function default_posts() {
		ob_start();

		$ed_options            = get_option( 'eazydocs_settings' ); // prefix of framework
		$kb_contact            = $ed_options['assistant_tab_settings']['contact_visibility'] ?? '1';
		$kb_visibility         = $ed_options['assistant_tab_settings']['kb_visibility'] ?? '1';
		$kb_search             = $ed_options['assistant_tab_settings']['assistant_search'] ?? '1';
		$kb_breadcrumb         = $ed_options['assistant_tab_settings']['assistant_breadcrumb'] ?? '1';
		$docs_not_found        = $ed_options['assistant_tab_settings']['docs_not_found'] ?? __( 'No Posts Found', 'eazydocs-pro' );
		$kb_block              = ( '1' !== $kb_contact ) ? 'kb-body-block' : '';
		$no_kb_search          = ( '1' !== $kb_search ) ? 'kb-body-no-search' : '';
		$kb_search_placeholder = $ed_options['assistant_tab_settings']['kb_search_placeholder'] ?? __( 'Search..', 'eazydocs-pro' );
		$instant_search        = $ed_options['assistant_tab_settings']['docs_instant_answer'] ?? '';
		$docs_to_show          = (int) ( $ed_options['assistant_tab_settings']['assistant_docs_show'] ?? - 1 );

		// Allow only positive integers or -1 (treat 0 and any negative as unlimited)
		$docs_to_show = ( $docs_to_show > 0 || $docs_to_show === - 1 ) ? $docs_to_show : - 1;

		if ( '1' === $kb_visibility ) :
			?>
			<div id="chatbox-search-results">
				<div class="chatbox-posts <?php echo esc_attr( $kb_block . ' ' . $no_kb_search ); ?>">
					<?php
					$query = new \WP_Query( [
						'post_type'      => 'docs',
						'posts_per_page' => $docs_to_show,
						'order'          => 'random',
					] );

					if ( $query->have_posts() ):
						while ( $query->have_posts() ) : $query->the_post();
							?>
							<div class="post-item <?php if ( '1' === $instant_search ) {
								echo esc_attr( 'instant-search-enabled' );
							} ?>"
								<?php if ( '1' === $instant_search ) : ?>
									data-id="<?php echo esc_attr( get_the_ID() ); ?>"
								<?php endif; ?>>

								<?php if ( $kb_breadcrumb == '1' ) : ?>
									<nav aria-label="breadcrumb">
										<?php eazydocs_breadcrumbs(); ?>
									</nav>
								<?php endif; ?>
								<h2><a href="<?php echo esc_url( get_the_permalink( get_the_ID() ) ); ?>"
								       target="_top"><?php the_title(); ?></a></h2>
								<?php echo wp_kses_post( wpautop( mb_substr( get_the_excerpt(), 0, 80, 'UTF-8' ) ) ); ?>
							</div>
						<?php
						endwhile;
						wp_reset_postdata();
					else:
						?>
						<div class="docs-not-found">
							<?php echo esc_html( $docs_not_found ); ?>
						</div>
					<?php
					endif;
					?>
				</div>
			</div>
		<?php
		endif;

		return ob_get_clean();
	}

	/**
	 * Add Contact Tab
	 */
	function eazydocs_assistant_tab_contact( $tabs = [] ) {
		$ed_options = get_option( 'eazydocs_settings' ); // prefix of framework
		$kb_contact = $ed_options['assistant_tab_settings']['contact_visibility'] ?? '1';
		if ( '1' === $kb_contact ) {
			$tabs[] = [
				'id'      => 'contact',
				'heading' => __( 'Contact Us', 'eazydocs-pro' ),
				'content' => $this->contact_form()
			];
		}

		return $tabs;
	}

	/**
	 * Contact Form
	 */
	function contact_form() {
		ob_start();

		$ed_options    = get_option( 'eazydocs_settings' ); // prefix of framework
		$kb_visibility = $ed_options['assistant_tab_settings']['kb_visibility'] ?? '1';

		$contact_block = ( '1' !== $kb_visibility ) ? 'active' : '';
		$kb_contact    = $ed_options['assistant_tab_settings']['contact_visibility'] ?? '1';

		// CONTACT INPUT
		$contact_fullname = $ed_options['assistant_tab_settings']['contact_fullname'] ?? __( 'Full Name', 'eazydocs-pro' );
		$contact_mail     = $ed_options['assistant_tab_settings']['contact_mail'] ?? 'name@example.com';
		$contact_subject  = $ed_options['assistant_tab_settings']['contact_subject'] ?? __( 'Subject', 'eazydocs-pro' );
		$contact_message  = $ed_options['assistant_tab_settings']['contact_message'] ?? __( 'Write Your Message', 'eazydocs-pro' );
		$contact_submit   = $ed_options['assistant_tab_settings']['contact_submit'] ?? __( 'Send Message', 'eazydocs-pro' );

		if ( '1' === $kb_contact ) :
			?>
			<div class="chatbox-form <?php echo esc_attr( $contact_block ); ?>">
				<div class="chatbox-form-wrapper">
					<form action="<?php echo esc_url( $_SERVER['REQUEST_URI'] ); ?>" method="post" class="chatbox-form">
						<input type="text" name="eazydocs_assistant_name" id="chatc-name"
						       placeholder="<?php echo esc_attr( $contact_fullname ); ?>" required>
						<input type="email" name="eazydocs_assistant_email" id="chatc-email"
						       placeholder="<?php echo esc_attr( $contact_mail ); ?>" required>
						<input type="text" name="eazydocs_assistant_subject" id="chatc-subject"
						       placeholder="<?php echo esc_attr( $contact_subject ); ?>" required>
						<textarea name="eazydocs_assistant_comment" cols="30" rows="8"
						          placeholder="<?php echo esc_attr( $contact_message ); ?>"></textarea>
						<input type="submit" name="eazydocs_assistant_submit"
						       value="<?php echo esc_attr( $contact_submit ); ?>">
					</form>
				</div>
			</div>
		<?php
		endif;

		return ob_get_clean();
	}

	// Step 3: Process the inserted tabs and tab content
	function assistant_tabs() {
		// Fetch the tabs and tab content from the hook
		$tabs = apply_filters( 'eazydocs_assistant_tab', [] );

		if ( ! empty( $tabs ) ) {

			// Display the tabs
			$ed_options            = get_option( 'eazydocs_settings' ); // prefix of framework
			$kb_visibility         = $ed_options['assistant_tab_settings']['kb_visibility'] ?? '1';
			$kb_search             = $ed_options['assistant_tab_settings']['assistant_search'] ?? '1';
			$kb_search_placeholder = $ed_options['assistant_tab_settings']['kb_search_placeholder'] ?? __( 'Search..', 'eazydocs-pro' );
			$kbase_heading         = $ed_options['assistant_tab_settings']['kb_label'] ?? __( 'Knowledge Base', 'eazydocs-pro' );
			$contact_heading       = $ed_options['assistant_tab_settings']['contact_label'] ?? __( 'Contact', 'eazydocs-pro' );
			?>
			<div class="chatbox-header">
				<div class="chatbox-tab">
					<?php
					foreach ( $tabs as $tab ) {
						if ( isset( $tab['id'] ) && 'kbase' === $tab['id'] ) {
							echo '<a href="#" tab-link="' . esc_attr( $tab['id'] ) . '" class="assistant-tab">' . esc_html( $kbase_heading ) . '</a>';
						} elseif ( isset( $tab['id'] ) && 'contact' === $tab['id'] ) {
							echo '<a href="#" tab-link="' . esc_attr( $tab['id'] ) . '" class="assistant-tab">' . esc_html( $contact_heading ) . '</a>';
						} else {
							$heading = isset( $tab['heading'] ) ? $tab['heading'] : __( 'Tab', 'eazydocs-pro' );
							echo '<a href="#" tab-link="' . esc_attr( $tab['id'] ) . '" class="assistant-tab">' . esc_html( $heading ) . '</a>';
						}
					}
					?>
				</div>

				<?php
				if ( '1' === $kb_visibility && '1' === $kb_search ) :
					?>
					<div class="search-box">
						<form action="#">
							<input type="search" name="s" id="wp-spotlight-chat-search"
							       placeholder="<?php echo esc_attr( $kb_search_placeholder ); ?>">
						</form>
					</div>
				<?php
				endif;
				?>
			</div>

			<div class="chatbox-body">
				<?php
				// Display the tab contents
				foreach ( $tabs as $tab ) {
					if ( ! empty( $tab['content'] ) ) {
						// Start with WordPress default allowed tags for posts
						$allowed_html = wp_kses_allowed_html( 'post' );

						// Add form-related tags & attributes
						$allowed_html['form'] = [
							'action' => true,
							'method' => true,
							'class'  => true,
							'id'     => true,
						];

						$allowed_html['input'] = [
							'type'        => true,
							'name'        => true,
							'value'       => true,
							'id'          => true,
							'class'       => true,
							'checked'     => true,
							'placeholder' => true,
							'disabled'    => true,
						];

						$allowed_html['select'] = [
							'name'  => true,
							'id'    => true,
							'class' => true,
						];

						$allowed_html['option'] = [
							'value'    => true,
							'selected' => true,
						];

						$allowed_html['textarea'] = [
							'name'        => true,
							'id'          => true,
							'class'       => true,
							'placeholder' => true,
							'rows'        => true,
							'cols'        => true,
						];

						$allowed_html['button'] = [
							'type'  => true,
							'class' => true,
							'id'    => true,
							'title' => true,
						];

						$allowed_html['svg'] = [
							'xmlns'       => true,
							'viewbox'     => true,
							'viewBox'     => true,
							'width'       => true,
							'height'      => true,
							'fill'        => true,
							'stroke'      => true,
							'class'       => true,
							'aria-hidden' => true,
							'role'        => true,
						];

						$allowed_html['path'] = [
							'd'            => true,
							'fill'         => true,
							'stroke'       => true,
							'stroke-width' => true,
							'class'        => true,
						];

						$allowed_html['g'] = [
							'fill'  => true,
							'class' => true,
						];

						$allowed_html['circle'] = [
							'cx'    => true,
							'cy'    => true,
							'r'     => true,
							'fill'  => true,
							'class' => true,
						];

						$allowed_html['rect'] = [
							'x'      => true,
							'y'      => true,
							'width'  => true,
							'height' => true,
							'fill'   => true,
							'class'  => true,
						];

						$allowed_html['line'] = [
							'x1'     => true,
							'x2'     => true,
							'y1'     => true,
							'y2'     => true,
							'stroke' => true,
							'class'  => true,
						];

						$allowed_html['polyline'] = [
							'points' => true,
							'fill'   => true,
							'stroke' => true,
							'class'  => true,
						];

						$allowed_html['polygon'] = [
							'points' => true,
							'fill'   => true,
							'stroke' => true,
							'class'  => true,
						];

						echo '<div class="assistant-content" tab-content="' . esc_attr( $tab['id'] ) . '"><div class="tab-content-container">' . wp_kses( $tab['content'], $allowed_html ) . '</div></div>';
					} else {
						// Output a default message or handle the situation accordingly
						echo '<div class="assistant-content" tab-content="' . esc_attr( $tab['id'] ) . '"><div class="tab-content-container">No content available for this tab</div></div>';
					}
				}
				?>
			</div>
		<?php
		}
	}

	public function contact_support() {
		new Assistant\Mailer();
	}

	public function render_assistant() {
		$ed_options            = get_option( 'eazydocs_settings' ); // prefix of framework
		$assistant_visibility  = $ed_options['assistant_visibility'] ?? '1';
		$iframe_assistant_page = is_page( 'iframe-assistant' );

		if ( '1' === $assistant_visibility ) :
			$open_icon_url         = ! empty( $ed_options['assistant_open_icon']['url'] ) ? $ed_options['assistant_open_icon']['url'] : EAZYDOCSPRO_ASSETS . '/images/frontend/chat.svg';
			$close_icon_url        = ! empty( $ed_options['assistant_close_icon']['url'] ) ? $ed_options['assistant_close_icon']['url'] : EAZYDOCSPRO_ASSETS . '/images/frontend/close.svg';
			$kb_visibility         = $ed_options['assistant_tab_settings']['kb_visibility'] ?? '1';
			$kb_search             = $ed_options['assistant_tab_settings']['assistant_search'] ?? '1';
			$kb_search_placeholder = $ed_options['assistant_tab_settings']['kb_search_placeholder'] ?? __( 'Search..', 'eazydocs-pro' );
			$kb_contact            = $ed_options['assistant_tab_settings']['contact_visibility'] ?? '1';
			$head_spacing          = ( '1' !== $kb_visibility || '1' !== $kb_contact ) ? 'chatbox-header-top-padding' : '';
			$kb_body_height        = ( '1' === $kb_contact && '1' === $kb_visibility ) ? 'kb-body-height' : '';

			$kb_visibility_by = $ed_options['assistant_visibility_by'] ?? '';

			// If current page is iframe-assistant and $kb_visibility_by is set, skip checks and return true
			if ( ! $iframe_assistant_page && ! empty( $kb_visibility_by ) ) {
				switch ( $kb_visibility_by ) {
					case 'pages':
						$assistant_pages = $ed_options['assistant_pages'] ?? '';
						if ( is_array( $assistant_pages ) && ! empty( $assistant_pages ) ) {
							if ( ! in_array( get_the_ID(), $assistant_pages ) ) {
								return true;
							}
						} else {
							return false;
						}
						break;

					case 'post_type':
						$assistant_post_types = $ed_options['assistant_post_types'] ?? '';
						if ( is_array( $assistant_post_types ) && ! empty( $assistant_post_types ) ) {
							if ( ! in_array( get_post_type( get_the_ID() ), $assistant_post_types ) ) {
								return true;
							}
						} else {
							return false;
						}
						break;

					case 'global':
						break;
				}
			}
			?>
			<div class="eazydocs-assistant-wrapper <?php echo esc_attr( $iframe_assistant_page ? 'iframe-wrapper' : '' ); ?>">

				<div class="chatbox-wrapper <?php echo esc_attr( $kb_body_height ); ?>">

					<div class="kbase-button-wrap">
						<div class="ezd-kbase-back">
							<svg xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink"
							     width="23" height="23" x="0" y="0" viewBox="0 0 128 128" xml:space="preserve" class="">
								<g>
									<path class="ezd-kbase-back-icon"
									      d="M84 108a3.988 3.988 0 0 1-2.828-1.172l-40-40a3.997 3.997 0 0 1 0-5.656l40-40c1.563-1.563 4.094-1.563 5.656 0s1.563 4.094 0 5.656L49.656 64l37.172 37.172a3.997 3.997 0 0 1 0 5.656A3.988 3.988 0 0 1 84 108z"
									      opacity="1"></path>
								</g>
							</svg>
						</div>

						<div class="ezd-kbase-extend-title"></div>

						<div class="ezd-kbase-extend">
							<svg xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink"
							     width="28" height="28" x="0" y="0" viewBox="0 0 96 96" xml:space="preserve" class="">
								<g>
									<g class="ezd-kbase-back-icon" fill-rule="evenodd" clip-rule="evenodd">
										<path class="ezd-kbase-back-icon"
										      d="M43.81 51.814c.825.824.828 2.161.004 2.988L23 75.688a2.113 2.113 0 0 1-2.993-2.982l20.815-20.887a2.112 2.112 0 0 1 2.987-.005z"
										      opacity="1"></path>
										<path d="M21.473 56.446a2.113 2.113 0 0 1 2.116 2.11l.024 13.525 13.525-.024a2.112 2.112 0 1 1 .007 4.225l-15.184.026a2.566 2.566 0 0 1-2.57-2.561l-.027-15.184a2.112 2.112 0 0 1 2.11-2.117zM52 43.874a2.112 2.112 0 0 1-.004-2.987L72.81 20a2.113 2.113 0 0 1 2.993 2.983L54.988 43.869a2.113 2.113 0 0 1-2.987.006z"
										      opacity="1" class="ezd-kbase-back-icon"></path>
										<path d="M74.337 39.242a2.113 2.113 0 0 1-2.116-2.109l-.024-13.525-13.525.023a2.113 2.113 0 1 1-.007-4.224l15.184-.027a2.566 2.566 0 0 1 2.57 2.562l.027 15.184a2.113 2.113 0 0 1-2.11 2.116z"
										      opacity="1" class="ezd-kbase-back-icon"></path>
									</g>
								</g>
							</svg>
						</div>
					</div>

					<div class="kb-content-wrap">
						<?php
						/**
						 * Hook: assistant_tabs.
						 *
						 * @hooked eazydocs_assistant_tab - 10 (Knowledge Base)
						 * @hooked eazydocs_assistant_tab - 11 (Contact Us)
						 */
						do_action( 'assistant_tabs' );
						?>
					</div>

				</div>

				<?php if ( ! $iframe_assistant_page ) : ?>
					<div class="chat-toggle">
						<a href="#">
							<img class="wp-spotlight-chat" src="<?php echo esc_url( $open_icon_url ); ?>"
							     alt="<?php esc_attr_e( 'Chat Icon', 'eazydocs-pro' ); ?>">
							<img class="wp-spotlight-hide" src="<?php echo esc_url( $close_icon_url ); ?>"
							     alt="<?php esc_attr_e( 'Close Icon', 'eazydocs-pro' ); ?>">
						</a>
					</div>
				<?php endif; ?>

				<button class="close-chat-sm">
					<span>Hide</span>
					<span class="icon">&#10094;</span>
				</button>
			</div>
		<?php
		endif;
	}

	/**
	 * Knowledge Base Instant Search
	 */
	function eazydocs_kbase_instant() {

		$post_id = $_POST['post_id'] ?? '';
		$post    = get_post( $post_id );

		if ( $post ) {
			if ( ! empty( $post->post_password ) && post_password_required( $post_id ) ) {
				echo '<h1 class="ezd-kbase-extend-heading">' . esc_html( get_the_title( $post_id ) ) . '</h1>';
				echo wp_kses_post( get_the_password_form( $post_id ) );
				die();

			} else {
				echo '<h1 class="ezd-kbase-extend-heading">' . esc_html( get_the_title( $post_id ) ) . '</h1>';
				$post_content = get_post_field( 'post_content', $post_id );
				echo wp_kses_post( do_shortcode( $post_content ) );
				die();
			}
		} else {
			esc_html__( "Post not found.", 'eazydocs-pro' );
		}

	}
}
