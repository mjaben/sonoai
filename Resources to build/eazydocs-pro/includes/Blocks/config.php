<?php
class EazyDocsBlocks {

    public function __construct() {
        add_action("init", [$this, "block_init"]);
    }

    /**
     * Registers custom Gutenberg blocks and sets up their configurations.
     *
     * @return void
     */
    public function block_init() {
        if ($this->ezd_is_premium()) {
            $this->register_block('eazy-docs', array(
                'render_callback' => [$this, 'render_eazy_docs'],
            ));
            
            // Register Book Chapters block
            $this->register_block('book-chapters', array(
                'render_callback' => [$this, 'render_book_chapters'],
            ));

            // Register Glossary Doc block
            $this->register_block('glossary-doc', array(
                'render_callback' => [$this, 'render_glossary_doc'],
            ));

            // Note: Tabbed Docs block has been moved to the free EazyDocs plugin.
            // Pro features are unlocked when ezd_is_premium() returns true.
        }
    }

    /**
     * Render callback for the free block.
     *
     * @param array $attributes Block attributes.
     * @param string $content Block content.
     * @return string Rendered block content.
     */
    public function render_eazy_docs($attributes, $content) {
        return $this->load_block_template($attributes);
    }

    /**
     * Render callback for Book Chapters block.
     *
     * @param array $attributes Block attributes.
     * @param string $content Block content.
     * @return string Rendered block content.
     */
    public function render_book_chapters($attributes, $content) {
        // Enqueue required styles
        wp_enqueue_style('elegant-icon');
        wp_enqueue_style('ezd-docs-widgets');
        wp_enqueue_style('eazydocs-pro-frontend');
        
        // Enqueue required scripts
        wp_enqueue_script('ezd-script-handle');
        wp_enqueue_script('scrollspy');

        ob_start();
        
        $file_path = EAZYDOCSPRO_PATH . '/build/book-chapters/render.php';
        
        if (is_readable($file_path)) {
            include $file_path;
        }

        return ob_get_clean();
    }

    /**
     * Render callback for Glossary Doc block.
     *
     * @param array $attributes Block attributes.
     * @param string $content Block content.
     * @return string Rendered block content.
     */
    public function render_glossary_doc($attributes, $content) {
        // Enqueue required styles
        wp_enqueue_style('elegant-icon');
        wp_enqueue_style('ezd-docs-widgets');
        wp_enqueue_style('eazydocs-pro-frontend');
        wp_enqueue_script('eazydocs-tooltip'); 

        ob_start();
        
        $file_path = EAZYDOCSPRO_PATH . '/build/glossary-doc/render.php';
        
        if (is_readable($file_path)) {
            include $file_path;
        }

        return ob_get_clean();
    }
    
    /**
     * Loads the block template file based on attributes.
     *
     * @param array $attributes
     * @return string Rendered content
     */
    private function load_block_template($attributes) {
        wp_enqueue_style('elegant-icon');
        wp_enqueue_style('ezd-docs-widgets');
        wp_enqueue_style('eazydocs-pro-frontend');

        ob_start();

        // Whitelist valid docTypes to prevent Local File Inclusion
        $allowed_doc_types = array( 'multi-doc', 'single-doc' );
        $doc_type = isset( $attributes['docTypes'] ) && in_array( $attributes['docTypes'], $allowed_doc_types, true ) 
            ? $attributes['docTypes'] 
            : '';

        // Whitelist valid presets for each doc type to prevent Local File Inclusion
        // Note: Tabbed presets (boxed_tabbed_docs, flat_tabbed_docs, tabbed_doc_list) 
        // and Light preset have been removed
        $allowed_presets = array(
            'multi-doc' => array( 'box', 'collaborative_docs', 'creative', 'docbox' ),
            'single-doc' => array( 'box', 'creative', 'docbox', 'topicsbox' ),
        );

        $preset = $attributes['docPreset'] ?? '';
        if ( $doc_type === 'single-doc' ) {
            $preset = $attributes['docSinglePreset'] ?? '';
        }

        // Validate preset against whitelist
        if ( empty( $doc_type ) || ! isset( $allowed_presets[$doc_type] ) || ! in_array( $preset, $allowed_presets[$doc_type], true ) ) {
            return ob_get_clean();
        }

        $file_path = sprintf(
            '%s/includes/Blocks/%s/%s.php',
            EAZYDOCSPRO_PATH,
            $doc_type,
            $preset
        );

        if (is_readable($file_path)) {
            require_once $file_path;
        }

        return ob_get_clean();
    }

    /**
     * Registers a Gutenberg block.
     *
     * @param string $name Block name.
     * @param array $options Block options.
     */
    public function register_block($name, $options = array()) {
        register_block_type(EAZYDOCSPRO_PATH . '/build/' . $name, $options);
    }

    /**
     * Checks if the premium version is enabled.
     *
     * @return bool
     */
    private function ezd_is_premium() {
        return function_exists('ezd_is_premium') && ezd_is_premium();
    }
}

// Instantiate the class
new EazyDocsBlocks();