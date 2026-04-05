<?php
/**
 * Frontend Translation Handler
 *
 * Handles the display of translated content on the frontend
 * and the language switcher functionality.
 *
 * @package Antimanual_Pro
 * @since 2.2.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define Pro plugin constants if not already defined
if ( ! defined( 'ANTIMANUAL_PRO_URL' ) ) {
	define( 'ANTIMANUAL_PRO_URL', plugin_dir_url( dirname( __DIR__ ) ) );
}
if ( ! defined( 'ANTIMANUAL_PRO_VERSION' ) ) {
	define( 'ANTIMANUAL_PRO_VERSION', '2.2.0' );
}

/**
 * Class ATML_Frontend_Translation
 *
 * Handles frontend translation display and language switching.
 */
class ATML_Frontend_Translation {
	/**
	 * Singleton instance.
	 *
	 * @var ATML_Frontend_Translation|null
	 */
	private static $instance = null;

	/**
	 * Current language code.
	 *
	 * @var string
	 */
	private $current_language = '';

	/**
	 * Whether the language has been detected.
	 *
	 * @var bool
	 */
	private $language_detected = false;

	/**
	 * Get singleton instance.
	 *
	 * @return ATML_Frontend_Translation
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Do not call detect_language() here; it runs lazily when needed.
	}

	/**
	 * Initialize hooks for front-end display.
	 *
	 * Called at `template_redirect` so the main query is available.
	 *
	 * @return void
	 */
	public function init_frontend() {
		// Add rewrite rules.
		$this->add_rewrite_rules();

		// Add language switcher.
		add_filter( 'the_content', array( $this, 'maybe_add_language_switcher' ), 5 );

		// Filter content based on selected language.
		add_filter( 'the_title', array( $this, 'filter_title' ), 10, 2 );
		add_filter( 'the_content', array( $this, 'filter_content' ), 10 );
		add_filter( 'the_excerpt', array( $this, 'filter_excerpt' ), 10 );

		// Enqueue frontend scripts and styles.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Add language query var.
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'atml_lang';
		return $vars;
	}

	/**
	 * Add rewrite rules.
	 *
	 * @return void
	 */
	public function add_rewrite_rules() {
		// Allow ?atml_lang=xx parameter
		add_rewrite_tag( '%atml_lang%', '([a-z]{2}(-[A-Z]{2})?)' );
	}

	/**
	 * Detect current language.
	 *
	 * @return void
	 */
	public function detect_language() {
		$this->language_detected = true;
		$post_id                 = $this->get_current_post_id();

		// Check URL parameter first.
		if ( isset( $_GET['atml_lang'] ) ) {
			$lang = sanitize_text_field( wp_unslash( $_GET['atml_lang'] ) );
			if ( $this->is_valid_language( $lang, $post_id ) ) {
				$this->current_language = $lang;
				$this->set_language_cookie( $lang );
				return;
			}
		}

		// Check cookie.
		if ( isset( $_COOKIE['atml_language'] ) ) {
			$lang = sanitize_text_field( wp_unslash( $_COOKIE['atml_language'] ) );
			if ( $this->is_valid_language( $lang, $post_id ) ) {
				$this->current_language = $lang;
				return;
			}
		}

		// Default to site language.
		$this->current_language = \Antimanual_Pro\TranslationService::get_default_language();
	}

	/**
	 * Get the current post ID from the request context.
	 *
	 * Works on both frontend pages and AJAX requests.
	 *
	 * @return int Post ID or 0 if unavailable.
	 */
	private function get_current_post_id() {
		// During AJAX requests, post_id is sent as a POST parameter.
		if ( wp_doing_ajax() && isset( $_POST['post_id'] ) ) {
			return intval( $_POST['post_id'] );
		}

		// On frontend pages, try to get the queried post.
		$post_id = get_queried_object_id();
		if ( $post_id ) {
			return $post_id;
		}

		return 0;
	}

	/**
	 * Check if language is valid and enabled.
	 *
	 * Checks against globally enabled languages first, then falls back
	 * to checking if a completed translation exists for the given post.
	 *
	 * @param string $lang    Language code.
	 * @param int    $post_id Optional. Post ID to check post-specific translations.
	 * @return bool
	 */
	private function is_valid_language( $lang, $post_id = 0 ) {
		$supported = \Antimanual_Pro\TranslationService::get_supported_languages();

		// Reject completely unknown language codes.
		if ( ! isset( $supported[ $lang ] ) ) {
			return false;
		}

		$default = \Antimanual_Pro\TranslationService::get_default_language();

		// Default language is always valid.
		if ( $lang === $default ) {
			return true;
		}

		// Check globally enabled languages.
		$enabled = \Antimanual_Pro\TranslationService::get_enabled_languages();
		if ( isset( $enabled[ $lang ] ) ) {
			return true;
		}

		// If a post ID is given, check for a completed translation.
		if ( $post_id ) {
			$translation = atml_get_translation( $post_id, $lang );
			if ( $translation && 'completed' === $translation->translation_status ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Set language cookie.
	 *
	 * @param string $lang Language code.
	 * @return void
	 */
	private function set_language_cookie( $lang ) {
		setcookie( 'atml_language', $lang, time() + ( 30 * DAY_IN_SECONDS ), COOKIEPATH, COOKIE_DOMAIN );
	}

	/**
	 * Get current language.
	 *
	 * @return string
	 */
	public function get_current_language() {
		if ( ! $this->language_detected ) {
			$this->detect_language();
		}
		return $this->current_language;
	}

	/**
	 * Enqueue frontend assets.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! is_singular() ) {
			return;
		}

		$show_switcher = atml_option( 'translation_show_switcher', true );

		if ( ! $show_switcher ) {
			return;
		}

		// Check if this post type has translation enabled
		$current_post_type  = get_post_type();
		$enabled_post_types = atml_option( 'translation_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $current_post_type, $enabled_post_types, true ) ) {
			return;
		}

		// Enqueue CSS
		wp_enqueue_style(
			'atml-language-switcher',
			ANTIMANUAL_PRO_URL . 'assets/css/frontend/language-switcher.css',
			array(),
			ANTIMANUAL_PRO_VERSION
		);

		// Enqueue JS
		wp_enqueue_script(
			'atml-language-switcher',
			ANTIMANUAL_PRO_URL . 'assets/js/language-switcher.js',
			array( 'jquery' ),
			ANTIMANUAL_PRO_VERSION,
			true
		);

		$post_id             = get_the_ID();
		$translations        = atml_get_post_translations( $post_id );
		$available_languages = array();

		foreach ( $translations as $translation ) {
			if ( 'completed' === $translation->translation_status ) {
				$all_languages                                      = \Antimanual_Pro\TranslationService::get_supported_languages();
				$available_languages[ $translation->language_code ] = $all_languages[ $translation->language_code ] ?? $translation->language_code;
			}
		}

		// Add default language
		$default_lang                         = \Antimanual_Pro\TranslationService::get_default_language();
		$all_languages                        = \Antimanual_Pro\TranslationService::get_supported_languages();
		$available_languages[ $default_lang ] = $all_languages[ $default_lang ] ?? $default_lang;

		wp_localize_script(
			'atml-language-switcher',
			'atmlTranslation',
			array(
				'ajax_url'                    => admin_url( 'admin-ajax.php' ),
				'rest_url'                    => rest_url( 'antimanual/v1/translations/' ),
				'post_id'                     => $post_id,
				'current_language'            => $this->get_current_language(),
				'default_language'            => $default_lang,
				'available_languages'         => $available_languages,
				'show_switcher_on_translated' => atml_option( 'translation_show_switcher_on_translated', false ),
				'nonce'                       => wp_create_nonce( 'atml_translation_public' ),
				'strings'                     => array(
					'select_language' => __( 'Select Language', 'antimanual' ),
					'loading'         => __( 'Loading...', 'antimanual' ),
					'error'           => __( 'Failed to load translation.', 'antimanual' ),
				),
			)
		);
	}

	/**
	 * Maybe add language switcher to content.
	 *
	 * @param string $content The post content.
	 * @return string Modified content.
	 */
	public function maybe_add_language_switcher( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$show_switcher = atml_option( 'translation_show_switcher', true );

		if ( ! $show_switcher ) {
			return $content;
		}

		// Check if this post type has translation enabled
		$current_post_type  = get_post_type();
		$enabled_post_types = atml_option( 'translation_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $current_post_type, $enabled_post_types, true ) ) {
			return $content;
		}

		$post_id      = get_the_ID();
		$translations = atml_get_post_translations( $post_id );

		// Only show if there are translations
		if ( empty( $translations ) ) {
			return $content;
		}

		$has_completed = false;
		foreach ( $translations as $t ) {
			if ( 'completed' === $t->translation_status ) {
				$has_completed = true;
				break;
			}
		}

		if ( ! $has_completed ) {
			return $content;
		}

		$switcher = $this->render_language_switcher( $post_id );
		$position = atml_option( 'translation_switcher_position', 'after_title' );

		if ( 'before_content' === $position || 'after_title' === $position ) {
			return $switcher . $content;
		} elseif ( 'after_content' === $position ) {
			return $content . $switcher;
		} elseif ( 'both' === $position ) {
			return $switcher . $content . $switcher;
		}

		return $content;
	}

	/**
	 * Render the language switcher HTML.
	 *
	 * @param int $post_id The post ID.
	 * @return string HTML output.
	 */
	private function render_language_switcher( $post_id ) {
		$translations  = atml_get_post_translations( $post_id );
		$default_lang  = \Antimanual_Pro\TranslationService::get_default_language();
		$all_languages = \Antimanual_Pro\TranslationService::get_supported_languages();
		$current       = $this->get_current_language();

		$available = array( $default_lang => $all_languages[ $default_lang ] ?? 'Original' );

		foreach ( $translations as $translation ) {
			if ( 'completed' === $translation->translation_status ) {
				$code               = $translation->language_code;
				$available[ $code ] = $all_languages[ $code ] ?? $code;
			}
		}

		if ( count( $available ) < 2 ) {
			return '';
		}

		ob_start();
		?>
		<div class="atml-language-switcher" data-post-id="<?php echo esc_attr( $post_id ); ?>">
			<label for="atml-lang-select" class="atml-lang-label">
				<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
					<circle cx="12" cy="12" r="10"></circle>
					<line x1="2" y1="12" x2="22" y2="12"></line>
					<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
				</svg>
				<span><?php esc_html_e( 'Language:', 'antimanual' ); ?></span>
			</label>
			<select id="atml-lang-select" class="atml-lang-select">
				<?php foreach ( $available as $code => $name ) : ?>
					<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $current, $code ); ?>>
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<span class="atml-lang-loading" style="display: none;">
				<span class="atml-spinner"></span>
			</span>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Filter post title.
	 *
	 * @param string $title   The post title.
	 * @param int    $post_id The post ID.
	 * @return string Filtered title.
	 */
	public function filter_title( $title, $post_id = 0 ) {
		if ( ! $post_id || ! is_singular() || ! in_the_loop() ) {
			return $title;
		}

		// Check if this post type has translation enabled
		$current_post_type  = get_post_type( $post_id );
		$enabled_post_types = atml_option( 'translation_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $current_post_type, $enabled_post_types, true ) ) {
			return $title;
		}

		$default_lang = \Antimanual_Pro\TranslationService::get_default_language();

		if ( $this->get_current_language() === $default_lang ) {
			return $title;
		}

		$translation = atml_get_translation( $post_id, $this->get_current_language() );

		if ( $translation && 'completed' === $translation->translation_status && ! empty( $translation->translated_title ) ) {
			return $translation->translated_title;
		}

		return $title;
	}

	/**
	 * Filter post content.
	 *
	 * @param string $content The post content.
	 * @return string Filtered content.
	 */
	public function filter_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		// Check if this post type has translation enabled
		$current_post_type  = get_post_type();
		$enabled_post_types = atml_option( 'translation_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $current_post_type, $enabled_post_types, true ) ) {
			return $content;
		}

		$default_lang = \Antimanual_Pro\TranslationService::get_default_language();

		if ( $this->get_current_language() === $default_lang ) {
			return $content;
		}

		$post_id     = get_the_ID();
		$translation = atml_get_translation( $post_id, $this->get_current_language() );

		if ( $translation && 'completed' === $translation->translation_status && ! empty( $translation->translated_content ) ) {
			$translated_content = $translation->translated_content;
			$keep_switcher      = atml_option( 'translation_show_switcher_on_translated', false );

			if ( $keep_switcher && atml_option( 'translation_show_switcher', true ) ) {
				$switcher = $this->render_language_switcher( $post_id );

				if ( ! empty( $switcher ) ) {
					$position = atml_option( 'translation_switcher_position', 'after_title' );

					if ( 'before_content' === $position || 'after_title' === $position ) {
						return $switcher . $translated_content;
					} elseif ( 'after_content' === $position ) {
						return $translated_content . $switcher;
					} elseif ( 'both' === $position ) {
						return $switcher . $translated_content . $switcher;
					}
				}
			}

			return $translated_content;
		}

		return $content;
	}

	/**
	 * Filter post excerpt.
	 *
	 * @param string $excerpt The post excerpt.
	 * @return string Filtered excerpt.
	 */
	public function filter_excerpt( $excerpt ) {
		if ( ! is_singular() || ! in_the_loop() ) {
			return $excerpt;
		}

		// Check if this post type has translation enabled
		$current_post_type  = get_post_type();
		$enabled_post_types = atml_option( 'translation_post_types', array( 'post', 'page' ) );
		if ( ! in_array( $current_post_type, $enabled_post_types, true ) ) {
			return $excerpt;
		}

		$default_lang = \Antimanual_Pro\TranslationService::get_default_language();

		if ( $this->get_current_language() === $default_lang ) {
			return $excerpt;
		}

		$post_id     = get_the_ID();
		$translation = atml_get_translation( $post_id, $this->get_current_language() );

		if ( $translation && 'completed' === $translation->translation_status && ! empty( $translation->translated_excerpt ) ) {
			return $translation->translated_excerpt;
		}

		return $excerpt;
	}

	/**
	 * AJAX handler for language switch.
	 *
	 * @return void
	 */
	public function ajax_switch_language() {
		check_ajax_referer( 'atml_translation_public', 'nonce' );

		$lang    = isset( $_POST['language'] ) ? sanitize_text_field( wp_unslash( $_POST['language'] ) ) : '';
		$post_id = isset( $_POST['post_id'] ) ? intval( $_POST['post_id'] ) : 0;

		if ( ! $lang || ! $post_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'antimanual' ) ) );
		}

		if ( ! $this->is_valid_language( $lang, $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid language.', 'antimanual' ) ) );
		}

		$this->set_language_cookie( $lang );

		$default_lang = \Antimanual_Pro\TranslationService::get_default_language();

		if ( $lang === $default_lang ) {
			$post = get_post( $post_id );
			wp_send_json_success(
				array(
					'title'   => $post->post_title,
					'content' => apply_filters( 'the_content', $post->post_content ),
					'excerpt' => $post->post_excerpt,
				)
			);
		}

		$translation = atml_get_translation( $post_id, $lang );

		if ( ! $translation || 'completed' !== $translation->translation_status ) {
			wp_send_json_error( array( 'message' => __( 'Translation not available.', 'antimanual' ) ) );
		}

		wp_send_json_success(
			array(
				'title'   => $translation->translated_title,
				'content' => apply_filters( 'the_content', $translation->translated_content ),
				'excerpt' => $translation->translated_excerpt,
			)
		);
	}
}

// Initialize frontend translation.
add_action(
	'init',
	function () {
		// Always register AJAX handlers.
		// AJAX requests go through admin-ajax.php where is_admin() is true.
		$instance = ATML_Frontend_Translation::instance();
		add_action( 'wp_ajax_atml_switch_language', array( $instance, 'ajax_switch_language' ) );
		add_action( 'wp_ajax_nopriv_atml_switch_language', array( $instance, 'ajax_switch_language' ) );
	}
);

// Initialize full frontend functionality only on frontend pages.
add_action(
	'template_redirect',
	function () {
		ATML_Frontend_Translation::instance()->init_frontend();
	}
);
