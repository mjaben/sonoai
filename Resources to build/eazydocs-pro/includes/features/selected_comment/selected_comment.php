<?php
 add_action('init', function() {
    // Register custom post type for text feedback
    register_post_type('ezd-text-feedback', [
        'labels' => [
            'name'    => __('Text Feedback', 'eazydocs-pro'),
            'singular_name' => __('Text Feedback', 'eazydocs-pro'),
        ],
        'public'      => false,
        'show_ui'     => false,
        'supports'    => ['title', 'editor', 'author'],
        'capability_type' => 'post'
    ]);
});

// Enqueue the JavaScript file
add_action( 'wp_enqueue_scripts', function () {
    // Only load for single docs pages if the feature is enabled
    if ( ! is_singular( 'docs' ) || ! function_exists('ezd_get_opt') || ezd_get_opt('enable-selected-comment') != 1 ) {
        return;
    }
	wp_enqueue_script( 'eazydocs-feedback', EAZYDOCSPRO_URL . '/includes/features/selected_comment/selected_comment.js', array( 'jquery' ), true, true );
} );

// Add toggle switch in the doc meta section
add_action( 'ezd_selected_comment_switcher_meta', function () {
	
	if ( ! ezd_is_promax() ) {
		return;
	}

	$selected_roles = eazydocspro_get_option( 'selected-comment-roles' );
	$user           = wp_get_current_user();
	$user_roles     = $user->roles;
	$user_role      = array_shift( $user_roles );
	$allowed_status = true;

	if ( isset( $selected_roles ) && ! empty( $selected_roles ) ) {
		if ( is_array( $selected_roles ) && ! in_array( $user_role, $selected_roles ) ) {
			$allowed_status = false;
		}
	}

	if ( ! $allowed_status ) {
		return;
	}

	$meta_title = eazydocspro_get_option( 'selected-comment-meta-title' );
	if ( ! empty( $meta_title ) ) :
		?>
        <span class="views sep selected-comment-wrap ezd-sep" title="<?php esc_attr_e('Enable/Disable comment on selected text', 'eazydocs-pro'); ?>">
			<label for="ezd_selected_comment_switcher"><?php echo esc_html( $meta_title ); ?></label>
			<span class="ezd_comment_switcher">
				<input type="checkbox" class="d-nodne" id="ezd_selected_comment_switcher" name="ezd_selected_comment_switcher" value="">
				<label for="ezd_selected_comment_switcher"></label>           
			</span>
        </span>
		<?php
	endif;
} );

// AJAX handler to save selected text comment
add_action( 'wp_ajax_ezd_selected_save_comment', 'ezd_selected_save_comment' );
add_action( 'wp_ajax_nopriv_ezd_selected_save_comment', 'ezd_selected_save_comment' );
function ezd_selected_save_comment() {
    $post_id   = intval( $_POST['post_id'] );
    $para_id   = sanitize_text_field( $_POST['para_id'] );
    $content   = sanitize_textarea_field( $_POST['content'] );
    $option    = sanitize_text_field( $_POST['option'] );

    // --- Author info
    if ( is_user_logged_in() ) {
        $user          = wp_get_current_user();
        $user_id       = $user->ID;
        $author_name   = $user->display_name ?: $user->user_login;
        $author_email  = $user->user_email;
        $avatar_url    = get_avatar_url( $user_id );
    } else {
        $user_id       = 0;
        $author_name   = __( 'Anonymous', 'eazydocs-pro' );
        $author_email  = '';
        $avatar_url    = get_avatar_url( 0, [ 'default' => 'mystery' ] );
        if ( empty( $avatar_url ) ) {
            $avatar_url = 'https://secure.gravatar.com/avatar/?d=mp';
        }
    }

    // --- Insert feedback post
    $feedback_id = wp_insert_post([
        'post_type'   => 'ezd-text-feedback',
        'post_title'  => wp_trim_words( $content, 8, '...' ),
        'post_content'=> $content,
        'post_status' => 'publish',
        'meta_input'  => [
            'related_doc_id'        => $post_id,
            'para_id'               => $para_id,
            'comment_option'        => $option,
            'author_name'           => $author_name,
            'author_email'          => $author_email,
            'avatar_url'            => esc_url( $avatar_url ),
            'ezd_feedback_archived' => 'false',
        ]
    ]);

    if ( $feedback_id ) {
        wp_send_json_success([
            'id'       => $feedback_id,
            'content'  => $content,
            'option'   => $option,
            'author'   => $author_name,
            'avatar'   => esc_url( $avatar_url ),
        ]);
    } else {
        wp_send_json_error([ 'msg' => 'Could not save feedback.' ]);
    }
}
 
// Add data attributes to paragraphs in the content
add_filter( 'the_content', function( $content ) {
    if ( is_singular( 'docs' ) ) {
        $is_enabled = false;

        if ( function_exists('ezd_get_opt') && ezd_get_opt('enable-selected-comment') == 1 ) {
            $is_enabled = true;            
        }

        if ( $is_enabled ) {
            global $post;
            $feedbacks = get_posts([
                'post_type'      => 'ezd-text-feedback',
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'meta_query'     => [
                    [
                        'key'     => 'related_doc_id',
                        'value'   => $post->ID,
                        'compare' => '=',
                    ]
                ]
            ]);

            $feedback_by_para = [];
            foreach ( $feedbacks as $feedback ) {
                $is_archived = get_post_meta( $feedback->ID, 'ezd_feedback_archived', true );
                if ( $is_archived === 'true' ) continue;

                $para_id = get_post_meta( $feedback->ID, 'para_id', true );
                $option  = get_post_meta( $feedback->ID, 'comment_option', true );
                $avatar  = get_post_meta( $feedback->ID, 'avatar_url', true );

                // --- Ensure avatar is never undefined
                if ( empty( $avatar ) || ! filter_var( $avatar, FILTER_VALIDATE_URL ) ) {
                    $avatar = get_avatar_url( 0, [ 'default' => 'mystery' ] );
                    if ( empty( $avatar ) ) {
                        $avatar = 'https://secure.gravatar.com/avatar/?d=mp';
                    }
                }

                $author  = get_post_meta( $feedback->ID, 'author_name', true ) ?: __( 'Anonymous', 'eazydocs-pro' );
                $feedback_by_para[$para_id][] = [
                    'text'   => $feedback->post_content,
                    'option' => $option,
                    'author' => $author,
                    'avatar' => esc_url( $avatar ),
                ];
            }

            // --- Parse DOM paragraphs
            $dom = new DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<?xml encoding="utf-8" ?>' . $content);
            $paras = $dom->getElementsByTagName('p');
            $i = 0;

            foreach ( $paras as $p ) {
                $i++;
                $existing = $p->getAttribute('class');
                $p->setAttribute('class', trim( $existing . ' ezd_selected-commentable' ));
                $p->setAttribute('data-para-id', $i);

                if ( isset( $feedback_by_para[$i] ) && ! empty( $feedback_by_para[$i] ) ) {
                    $p->setAttribute('data-comments', wp_json_encode( $feedback_by_para[$i] ));
                }
            }

            $body = $dom->getElementsByTagName('body')->item(0);
            $content = $dom->saveHTML( $body );
            $content = preg_replace( '~<(?:/?body[^>]*)>~i', '', $content );
        }
    }
    return $content;
});

// Get presets for selected comment options
function ezd_selected_comment_presets(){
    $options = ezd_get_opt('selected_comment_options', []);
    
    if (is_array($options)) {
        $defaults = [];
        foreach ($options as $row) {
            if (!empty($row['label'])) {
                $defaults[] = $row['label'];
            }
        }
        return $defaults;
    }

    return [];
}

// Get all settings for selected comment feature
function ezd_selected_comment_settings() {
    return [
        'options'     => ezd_selected_comment_presets(),
        'heading'     => ezd_get_opt('selected_comment_options_heading', __( 'What is the issue with this selection?', 'eazydocs-pro' )),
        'other_label' => ezd_get_opt('selected_comment_option_other', __( 'Others', 'eazydocs-pro' )),
        'form_title'  => ezd_get_opt('selected_comment_form_heading', __( 'Share additional info or suggestions', 'eazydocs-pro' )),
        'subheading'  => ezd_get_opt('selected_comment_form_subheading', __( 'Do not share any personal info', 'eazydocs-pro' )),
        'footer'      => ezd_get_opt('selected_comment_form_footer', __( 'Your feedback is important to us', 'eazydocs-pro' ))
    ];
}