<?php
namespace eazyDocsPro\Elementor\Glossary_Doc;

use Elementor\Widget_Base;
use Elementor\Controls_Manager;
use Elementor\Core\Schemes\Color;
use Elementor\Core\Schemes\Typography;
use Elementor\Group_Control_Typography;
use Elementor\Group_Control_Text_Shadow;
use WP_Query;
use WP_Post;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Glossary_doc
 * @package DocyCore\Widgets
 */
class Glossary_Doc extends Widget_Base {

	public function get_name() {
		return 'ezd_glossary_doc';
	}

	public function get_title() {
		return __( '(EazyDocs) Glossary Doc', 'eazydocs-pro' );
	}

	public function get_icon() {
		return 'eicon-document-file eicon-archive';
	}

	public function get_categories() {
		return [ 'eazydocs' ];
	}

	// style depends
	public function get_style_depends() {
		return [ 'ezd-docs-widgets', 'eazydocs-pro-frontend', 'elegant-icon', 'eazydocs-tooltip'];
	}	

    public function get_script_depends() {
        return [ 'mixitup', 'eazydocs-pro-frontend', 'eazydocs-tooltip'];
    }

	public function get_keywords() {
		return [ 'eazydocs-pro', 'docs', 'documentation', 'glossary', 'glossary doc', 'glossary documentation' ];
	}

	protected function register_controls() {

		// --- Doc ------------------------------------
		$this->start_controls_section(
			'doc_opt', [
				'label' => __( 'Doc', 'eazydocs-pro' ),
			]
		);

		$this->add_control(
			'doc', [
				'label'   => esc_html__( 'Doc', 'eazydocs-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [ 'all' => esc_html__('All Docs', 'eazydocs-pro') ] + ezd_get_posts(),
				'default' => 'all'
			]
		);

		$this->add_control(
			'doc_sec_excerpt', [
				'label'       => esc_html__( 'Excerpt', 'eazydocs-pro' ),
				'description' => esc_html__( 'Excerpt word limit of the documentation sections. If the excerpt got empty, this will get from the post content.', 'eazydocs-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 8,
				'condition'   => [
					'style' => '4'
				]
			]
		);

		$this->add_control(
			'ppp_doc_items', [
				'label'       => esc_html__( 'Articles', 'eazydocs-pro' ),
				'description' => esc_html__( 'Number of articles to show under every sections', 'eazydocs-pro' ),
				'type'        => Controls_Manager::NUMBER,
				'default'     => 4,

			]
		);

		$this->add_control(
			'order', [
				'label'   => esc_html__( 'Order', 'eazydocs-pro' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'ASC'  => 'ASC',
					'DESC' => 'DESC'
				],
				'default' => 'ASC'
			]
		);

		$this->add_control(
            'tooltip', [
                'label' => esc_html__( 'Tooltip', 'eazydocs-pro' ),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'return_value' => 'yes',
                'default' => '',
            ]
        );

		$this->add_control(
			'tooltip_content_limit',
			[
				'label'     => esc_html__( 'Content Limit Display', 'eazydocs-pro' ),
				'type'      => \Elementor\Controls_Manager::NUMBER,
				'min'       => 1,
				'max'       => 100,
				'default'   => 40,
				'condition'   	=> [
					'tooltip' => 'yes'
				]
			]
		);

		$this->end_controls_section();


		// Buttons
		$this->start_controls_section(
			'view_all_btn_opt', [
				'label' => __( 'Buttons', 'eazydocs-pro' ),
				'condition'   => [
					'style' => [ '1', '3' ]
				]
			]
		);

		$this->add_control(
			'read_more', [
				'label'       => esc_html__( 'Read More Button', 'eazydocs-pro' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'Read More',
			]
		);

		$this->add_control(
			'section_btn', [
				'label'        => esc_html__( 'Section Button', 'eazydocs-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'   => [
					'style' => [ '1', '2' ]
				]
			]
		);

		$this->add_control(
			'section_btn_txt', [
				'label'       => esc_html__( 'Button Text', 'eazydocs-pro' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'View More',
				'condition'   => [
					'section_btn' => 'yes',
					'style' => [ '1', '2' ]
				]
			]
		);

		$this->add_control(
			'section_btn_url', [
				'label'       => esc_html__( 'Button URL', 'eazydocs-pro' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'condition'   => [
					'section_btn' => 'yes',
					'style' => [ '1', '2' ]
				]
			]
		);

		$this->add_control(
			'show_more_btn', [
				'label'       => esc_html__( 'Show More Button', 'eazydocs-pro' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'View All',
				'condition'   => [
					'style' => [ '4' ]
				]
			]
		);

		$this->add_control(
			'show_less_btn', [
				'label'       => esc_html__( 'Show Less Text', 'eazydocs-pro' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => 'Show Less',
				'condition'   => [
					'style' => [ '4' ]
				]
			]
		);

		$this->end_controls_section();


		/**
		 * Glossary Doc Style Section
		 */

		 // ------------------------------ Alphabet Style------------------------------ 
		$this->start_controls_section(
			'glossary_alphabet', [
				'label'     => __( 'Alphabet', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'alphabet_color', [
				'label'     => __( 'Text Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .spe-list-filter a' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     	=> 'alphabet_typography',
				'selector'	=> '
                    {{WRAPPER}} .spe-list-filter a'
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' 		=> 'alphabet-background',
				'label' 	=> esc_html__( 'Alphabet Background', 'eazydocs-pro' ),
				'types' 	=> [ 'classic', 'gradient' ],
				'exclude'	=> ['image'],
				'selector'	=> '{{WRAPPER}} .spe-list-filter',
			]
		);

		$this->end_controls_section();


		 // ------------------------------ Search Box Style------------------------------ 
		$this->start_controls_section(
			'glossary_search', [
				'label'     => __( 'Search Box', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_responsive_control(
			'glossary_search_padding', [
				'label'      	=> __( 'Padding', 'eazydocs-pro' ),
				'type'       	=> Controls_Manager::DIMENSIONS,
				'size_units'	=> [ 'px', '%', 'em' ],
				'separator'  	=> 'before',
				'selectors'  	=> [
					'{{WRAPPER}} .spe-list-search-form input[type=search]' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
				],
			]
		);
		
		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' 		=> 'glossary_search_border',
				'label' 	=> esc_html__( 'Border', 'eazydocs-pro' ),
				'selector'	=> '{{WRAPPER}} .spe-list-search-form input[type=search]',
			]
		);
		
		$this->add_control(
			'glossary_search_background', [
				'label'     => esc_html__( 'Background Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .spe-list-search-form input[type=search]' => 'background: {{VALUE}};',
				],
			]
		);
		
		$this->add_control(
			'glossary_search_background_focus', [
				'label'     => esc_html__( 'Focus Background Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .spe-list-search-form input[type=search]:focus' => 'background: {{VALUE}};',
				],
			]
		);
		
		$this->add_responsive_control(
			'glossary_search_border_radius', [
				'label'      	=> __( 'Border Radius', 'eazydocs-pro' ),
				'type'       	=> Controls_Manager::DIMENSIONS,
				'size_units'	=> [ 'px', '%', 'em' ],
				'selectors'  	=> [
					'{{WRAPPER}} .spe-list-search-form input[type=search]' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);
		
		$this->add_control(
			'glossary_search_color_placeholder', [
				'label'     	=> esc_html__( 'Placeholder Color', 'eazydocs-pro' ),
				'type'      	=> Controls_Manager::COLOR,
				'separator'  	=> 'before',
				'selectors'		=> [
					'{{WRAPPER}} .spe-list-search-form input[type=search]::placeholder' => 'color: {{VALUE}};',
				],
			]
		);
		
		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     	=> 'glossary_typography_placeholder',
				'label'    	=> esc_html__( 'Typography', 'eazydocs-pro' ),
				'selector'	=> '{{WRAPPER}} .spe-list-search-form input[type=search]::placeholder',
			]
		);
		
		$this->add_control(
			'glossary_search_color_text', [
				'label'     => esc_html__( 'Text Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .spe-list-search-form input[type=search]' => 'color: {{VALUE}};',
				],
				'separator' => 'before'
			]
		);

		$this->end_controls_section();


		 // ------------------------------Doc Content Style------------------------------ 
		$this->start_controls_section(
			'glossary_doc_alphabet', [
				'label'     => __( 'Content', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'doc_alphabet_color', [
				'label'     => __( 'Doc Alphabet Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .spe-list-block .spe-list-block-heading' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     	=> 'doc_alphabet_typography',
				'selector'	=> '
                    {{WRAPPER}} .spe-list-block .spe-list-block-heading'
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' 		=> 'doc_alphabet-background',
				'label' 	=> esc_html__( 'Background', 'eazydocs-pro' ),
				'types' 	=> [ 'classic', 'gradient' ],
				'exclude'	=> ['image'],
				'selector'	=> '{{WRAPPER}} .spe-list-block .spe-list-block-heading',
			]
		);

		$this->add_control(
			'hr',
			[
				'type' => \Elementor\Controls_Manager::DIVIDER,
			]
		);

		$this->add_control(
			'doc_alphabet_content_color', [
				'label'     => __( 'Doc Content Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .tag_list li a' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     	=> 'doc_alphabet_content_typography',
				'selector'	=> '
                    {{WRAPPER}} .tag_list li a'
			]
		);

		$this->add_control(
			'doc_alphabet_content_icon_color', [
				'label'     => __( 'Icon Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'{{WRAPPER}} .spe-list-block .spe-list-items .spe-list-item .spe-list-item-title::before' => 'color: {{VALUE}};',
				],
			]
		);

		$this->end_controls_section();


		/**
		 * Style Tab
		 * ------------------------------ Style Box ------------------------------
		 */
		$this->start_controls_section(
			'style_box', [
				'label'     => __( 'Box', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition'   => [
					'style' => [ '1', '2', '3', '4'  ]
				]
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Background::get_type(),
			[
				'name' => 'box-background',
				'label' => esc_html__( 'Background', 'eazydocs-pro' ),
				'types' => [ 'classic', 'gradient' ],
				'selector' => '{{WRAPPER}} .box-item',
			]
		);

		$this->add_group_control(
			\Elementor\Group_Control_Border::get_type(),
			[
				'name' => 'box-border',
				'label' => esc_html__( 'Border', 'eazydocs-pro' ),
				'selector' => '{{WRAPPER}} .box-item',
			]
		);

		$this->add_control(
			'box-padding',
			[
				'label' => esc_html__( 'Padding', 'eazydocs-pro' ),
				'type' => \Elementor\Controls_Manager::DIMENSIONS,
				'size_units' => [ 'px', 'em' ],
				'selectors' => [
					'{{WRAPPER}} .box-item' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}};',
				],
			]
		);

		$this->end_controls_section();

		/**
		 * Style Tab
		 * ------------------------------ Style Title ------------------------------
		 */
		$this->start_controls_section(
			'style_title', [
				'label'     => __( 'Title', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'style' => [ '2' ]
				]
			]
		);

		$this->add_control(
			'color_title', [
				'label'     => __( 'Text Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'typography_prefix',
				'selector' => '
                    {{WRAPPER}} .title'
			]
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(), [
				'name'     => 'text_shadow_prefix',
				'selector' => '{{WRAPPER}} .title',
			]
		);

		$this->end_controls_section();

		//------------------------------ Style Subtitle ------------------------------
		$this->start_controls_section(
			'style_subtitle_sec', [
				'label'     => __( 'Subtitle', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'style' => [ '2' ]
				]
			]
		);

		$this->add_control(
			'color_subtitle', [
				'label'     => __( 'Text Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .subtitle' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'typography_subtitle',
				'selector' => '{{WRAPPER}} .subtitle',
			]
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(), [
				'name'     => 'text_shadow_subtitle',
				'selector' => '{{WRAPPER}} .subtitle',
			]
		);

		$this->end_controls_section();


		/**
		 * Style Content Tab
		 * ------------------------------ Style Content ------------------------------
		 */
		$this->start_controls_section(
			'style_content', [
				'label' => __( 'Content', 'eazydocs-pro' ),
				'tab'   => Controls_Manager::TAB_STYLE,
				'condition'   => [
					'style' => [ '1', '2', '3', '4'  ]
				]
			]
		);

		$this->add_control(
			'heading_title', [
				'label' => __( 'Title', 'eazydocs-pro' ),
				'type'  => Controls_Manager::HEADING
			]
		);
		$this->add_control(
			'doc_color_title', [
				'label'     => __( 'Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ct-heading-text' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'title_typography_prefix',
				'selector' => '
                    {{WRAPPER}} .ct-heading-text'
			]
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(), [
				'name'     => 'title_shadow_prefix',
				'selector' => '{{WRAPPER}} .ct-heading-text',
			]
		);

		$this->add_control(
			'heading_content', [
				'label'     => __( 'Content', 'eazydocs-pro' ),
				'type'      => Controls_Manager::HEADING,
				'separator' => 'before'
			]
		);
		$this->add_control(
			'doc_color_content', [
				'label'     => __( 'Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .ct-content-text, {{WRAPPER}} .ct-content-text p' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'content_typography_prefix',
				'selector' => '
                    {{WRAPPER}} .ct-content-text, {{WRAPPER}} .ct-content-text p'
			]
		);

		$this->add_group_control(
			Group_Control_Text_Shadow::get_type(), [
				'name'     => 'content_shadow_prefix',
				'selector' => '{{WRAPPER}} .ct-content-text, {{WRAPPER}} .ct-content-text p',
			]
		);

		$this->end_controls_section();


		/**
		 * Background Objects
		 */
		$this->start_controls_section(
			'style_bg_objects', [
				'label'     => esc_html__( 'Background Objects', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition' => [
					'style' => [ '2' ]
				]
			]
		);

		$this->add_control(
			'is_bg_objects', [
				'label'        => esc_html__( 'Background Objects', 'eazydocs-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
			]
		);

		$this->add_control(
			'bg_shape', [
				'label'       => esc_html__( 'Shape', 'eazydocs-pro' ),
				'description' => esc_html__( 'The background shape should be transparent png or svg image.', 'eazydocs-pro' ),
				'type'        => Controls_Manager::MEDIA,
				'default'     => [
					'url' => plugins_url( 'images/docbg-shap.png', __FILE__ )
				],
				'condition'   => [
					'is_bg_objects' => [ 'yes' ]
				]
			]
		);

		$this->add_control(
			'is_round1', [
				'label'        => esc_html__( 'Round Objects 01', 'eazydocs-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before'
			]
		);

		$this->add_control(
			'round1_color', [
				'label'     => __( 'Round 01 Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .doc_round.one' => 'background: {{VALUE}}',
				],
				'condition' => [
					'is_bg_objects' => 'yes',
					'is_round1'     => 'yes',
				]
			]
		);

		$this->add_control(
			'is_round2', [
				'label'        => esc_html__( 'Round Objects 02', 'eazydocs-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'return_value' => 'yes',
				'default'      => 'yes',
				'separator'    => 'before'
			]
		);

		$this->add_control(
			'round2_color', [
				'label'     => __( 'Round 02 Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'{{WRAPPER}} .doc_round.two' => 'background: {{VALUE}}',
				],
				'condition' => [
					'is_bg_objects' => 'yes',
					'is_round1'     => 'yes',
				]
			]
		);

		$this->end_controls_section();

		//------------------------------ Style Subtitle ------------------------------
		$this->start_controls_section(
			'style_tooltip_sec', [
				'label'     => __( 'Tooltip Style', 'eazydocs-pro' ),
				'tab'       => Controls_Manager::TAB_STYLE,
				'condition'   	=> [
					'tooltip' => 'yes'
				]
			]
		);

		$this->add_responsive_control(
			'tooltip_box_padding', [
				'label'      	=> __( 'Padding', 'eazydocs-pro' ),
				'type'       	=> Controls_Manager::DIMENSIONS,
				'size_units'	=> [ 'px', '%', 'em' ],
				'separator'  	=> 'before',
				'selectors'  	=> [
					'.ezd-body-docs .tooltipster-sidetip .tooltipster-content' => 'padding: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
				],
			]
		);
		
		$this->add_control(
			'tooltip_background', [
				'label'     => esc_html__( 'Background Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors'	=> [
					'.ezd-body-docs .tooltipster-sidetip .tooltipster-box,.tooltipster-sidetip.tooltipster-top .tooltipster-arrow-border,
					.tooltipster-sidetip .tooltipster-arrow-border' => 'background: {{VALUE}} !important;',
				],
			]
		);
		$this->add_responsive_control(
			'tooltip_border_radius', [
				'label'      	=> __( 'Border Radius', 'eazydocs-pro' ),
				'type'       	=> Controls_Manager::DIMENSIONS,
				'size_units'	=> [ 'px', '%', 'em' ],
				'selectors'  	=> [
					'.ezd-body-docs .tooltipster-sidetip .tooltipster-box' => 'border-radius: {{TOP}}{{UNIT}} {{RIGHT}}{{UNIT}} {{BOTTOM}}{{UNIT}} {{LEFT}}{{UNIT}} !important;',
				],
			]
		);


		$this->add_control(
			'color_tooltip_title', [
				'label'     => __( 'Title Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'.ezd-body-docs .tooltipster-sidetip .tooltipster-content .text h4' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'typography_tooltiptitle',
				'selector' => '.ezd-body-docs .tooltipster-sidetip .tooltipster-content .text h4',
			]
		);

		$this->add_control(
			'color_tooltip_content', [
				'label'     => __( 'Content Color', 'eazydocs-pro' ),
				'type'      => Controls_Manager::COLOR,
				'selectors' => [
					'.ezd-body-docs .tooltipster-sidetip .tooltipster-content .text p' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(), [
				'name'     => 'typography_content',
				'selector' => '.ezd-body-docs .tooltipster-sidetip .tooltipster-content .text p',
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings  = $this->get_settings();
		$title_tag = ! empty( $settings['title_tag'] ) ? $settings['title_tag'] : 'h2';

		/**
		 * Get the parent docs with query
		 */
        //     'post_parent'    => $settings['doc'],
		$args = array(
			'post_type'      => 'docs',
			'post_status'    => 'publish',
			'orderby'        => 'menu_order',
			'order'          => $settings['order'],
			'posts_per_page' => -1,
		);

		if ( $settings['doc'] === 'all' ) {
			$args['post_parent__in'] = array_keys( ezd_get_posts() );
		} else {
			$args['post_parent'] = $settings['doc'] ?? 0;
		}
		
		// Your post handling logic here
		$sections = new WP_Query($args);
		
		// Include the template parts
		if ( ezd_is_premium() ) {
			include "glossary-doc-1.php";
		} else {
			include "glossary-doc-1.php";
		}
	}
}