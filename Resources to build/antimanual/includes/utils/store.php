<?php
/**
 * Antimanual Data Store
 *
 * This file contains the data store for Antimanual, which holds various settings and configurations.
 *
 * @package Antimanual
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'init', 'antimanual_init_store' );

function antimanual_init_store() {
	$chat_models = [
		[
			'value'       => 'gpt-5.4',
			'label'       => esc_html__( 'GPT 5.4', 'antimanual' ),
			'description' => esc_html__( 'Flagship GPT-5.4 model for complex reasoning, coding, and professional workflows.', 'antimanual' ),
		],
		[
			'value'       => 'gpt-5',
			'label'       => esc_html__( 'GPT 5', 'antimanual' ),
			'description' => esc_html__( 'Next-generation model with advanced reasoning, creativity, and context retention. Suitable for demanding tasks and premium applications.', 'antimanual' ),
		],
		[
			'value'       => 'gpt-5-mini',
			'label'       => esc_html__( 'GPT 5 Mini', 'antimanual' ),
			'description' => esc_html__( 'A lightweight version of GPT 5, optimized for speed and cost-efficiency. Ideal for general-purpose and high-volume use.', 'antimanual' ),
		],
		[
			'value'       => 'gpt-4.1-mini',
			'label'       => esc_html__( 'GPT 4.1 Mini', 'antimanual' ),
			'description' => esc_html__( 'Compact version of GPT-4.1 for faster responses. (~$0.005/1K tokens)', 'antimanual' ),
		],
	];

	$openai_model_details = [
		'gpt-5.4'      => [
			'best_for'          => esc_html__( 'Complex reasoning, coding, and professional workflows at the highest supported capability level.', 'antimanual' ),
			'context_window'    => '1,050,000',
			'max_output_tokens' => '128,000',
			'knowledge_cutoff'  => '2025-08-31',
			'reasoning_effort'  => 'none, low, medium, high, xhigh',
			'modalities'        => esc_html__( 'Text output, text and image input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$2.50 / 1M',
				'cached_input' => '$0.25 / 1M',
				'output'       => '$15.00 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Audio and video are not supported.', 'antimanual' ),
				esc_html__( 'Fine-tuning is not supported.', 'antimanual' ),
				esc_html__( 'Rate limits depend on your OpenAI usage tier.', 'antimanual' ),
				esc_html__( 'Prompts above 272K input tokens use higher GPT-5.4 long-context pricing.', 'antimanual' ),
			],
			'docs_url'          => 'https://developers.openai.com/api/docs/models/gpt-5.4',
		],
		'gpt-5'        => [
			'best_for'          => esc_html__( 'General coding, reasoning, and agentic tasks when you want a full GPT-5 class model.', 'antimanual' ),
			'context_window'    => '400,000',
			'max_output_tokens' => '128,000',
			'knowledge_cutoff'  => '2024-09-30',
			'reasoning_effort'  => 'minimal, low, medium, high',
			'modalities'        => esc_html__( 'Text output, text and image input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$1.25 / 1M',
				'cached_input' => '$0.125 / 1M',
				'output'       => '$10.00 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Audio and video are not supported.', 'antimanual' ),
				esc_html__( 'Fine-tuning is not supported.', 'antimanual' ),
				esc_html__( 'Rate limits depend on your OpenAI usage tier.', 'antimanual' ),
			],
			'docs_url'          => 'https://developers.openai.com/api/docs/models/gpt-5',
		],
		'gpt-5-mini'   => [
			'best_for'          => esc_html__( 'Cost-sensitive, lower-latency, high-volume GPT-5 workloads with strong instruction following.', 'antimanual' ),
			'context_window'    => '400,000',
			'max_output_tokens' => '128,000',
			'knowledge_cutoff'  => '2024-05-31',
			'reasoning_effort'  => 'medium',
			'modalities'        => esc_html__( 'Text output, text and image input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.25 / 1M',
				'cached_input' => '$0.025 / 1M',
				'output'       => '$2.00 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Audio and video are not supported.', 'antimanual' ),
				esc_html__( 'Fine-tuning is not supported.', 'antimanual' ),
				esc_html__( 'Some advanced tool support is narrower than flagship GPT-5.4.', 'antimanual' ),
				esc_html__( 'Rate limits depend on your OpenAI usage tier.', 'antimanual' ),
			],
			'docs_url'          => 'https://developers.openai.com/api/docs/models/gpt-5-mini',
		],
		'gpt-4.1-mini' => [
			'best_for'          => esc_html__( 'Lower-cost GPT-4.1 class tasks with strong instruction following and long context.', 'antimanual' ),
			'context_window'    => '1,047,576',
			'max_output_tokens' => '32,768',
			'knowledge_cutoff'  => '2024-06-01',
			'reasoning_effort'  => esc_html__( 'Not a reasoning model', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text and image input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.40 / 1M',
				'cached_input' => '$0.10 / 1M',
				'output'       => '$1.60 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Audio and video are not supported.', 'antimanual' ),
				esc_html__( 'Lower max output than GPT-5 family models.', 'antimanual' ),
				esc_html__( 'OpenAI recommends GPT-5 mini for more complex tasks.', 'antimanual' ),
			],
			'docs_url'          => 'https://developers.openai.com/api/docs/models/gpt-4.1-mini',
		],
	];

	$chat_models = array_map(
		function ( $model ) use ( $openai_model_details ) {
			$value = (string) ( $model['value'] ?? '' );
			$meta  = $openai_model_details[ $value ] ?? [];
			return array_merge( $model, $meta );
		},
		$chat_models
	);

	$gemini_chat_models = [
		[
			'value'       => 'gemini-3.1-pro-preview',
			'label'       => esc_html__( 'Gemini 3.1 Pro Preview', 'antimanual' ),
			'description' => esc_html__( 'Latest model with enhanced reasoning, world knowledge, and multimodal capabilities.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-3.1-flash-lite-preview',
			'label'       => esc_html__( 'Gemini 3.1 Flash-Lite Preview', 'antimanual' ),
			'description' => esc_html__( 'Cost-efficient workhorse model optimized for high-volume tasks.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-3-pro-preview',
			'label'       => esc_html__( 'Gemini 3 Pro Preview', 'antimanual' ),
			'description' => esc_html__( 'Next-generation model with advanced reasoning capabilities.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-3-flash-preview',
			'label'       => esc_html__( 'Gemini 3 Flash Preview', 'antimanual' ),
			'description' => esc_html__( 'Next-generation high-speed multimodal model.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-2.5-pro',
			'label'       => esc_html__( 'Gemini 2.5 Pro', 'antimanual' ),
			'description' => esc_html__( 'Advanced model with improved reasoning capabilities.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-2.5-flash',
			'label'       => esc_html__( 'Gemini 2.5 Flash', 'antimanual' ),
			'description' => esc_html__( 'High-speed, cost-effective multimodal model.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-2.5-flash-preview',
			'label'       => esc_html__( 'Gemini 2.5 Flash Preview', 'antimanual' ),
			'description' => esc_html__( 'Preview version of Gemini 2.5 Flash.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-2.5-flash-lite',
			'label'       => esc_html__( 'Gemini 2.5 Flash-Lite', 'antimanual' ),
			'description' => esc_html__( 'Optimized for extreme cost-efficiency and speed.', 'antimanual' ),
		],
		[
			'value'       => 'gemini-2.5-flash-lite-preview',
			'label'       => esc_html__( 'Gemini 2.5 Flash-Lite Preview', 'antimanual' ),
			'description' => esc_html__( 'Preview version of Gemini 2.5 Flash-Lite.', 'antimanual' ),
		],
	];

	$gemini_model_details = [
		'gemini-3.1-pro-preview'        => [
			'best_for'          => esc_html__( 'Top-end multimodal understanding, agentic workflows, and advanced coding or reasoning tasks.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$2.00 / 1M (<=200K), $4.00 / 1M (>200K)',
				'cached_input' => '$0.20 / 1M (<=200K), $0.40 / 1M (>200K)',
				'output'       => '$12.00 / 1M (<=200K), $18.00 / 1M (>200K)',
			],
			'limitations'       => [
				esc_html__( 'Preview models can change before becoming stable.', 'antimanual' ),
				esc_html__( 'Image generation, audio generation, and Live API are not supported.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/models/gemini',
		],
		'gemini-3.1-flash-lite-preview' => [
			'best_for'          => esc_html__( 'Lowest-cost high-throughput generation, routing, extraction, and utility tasks at scale.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.25 / 1M text-image-video',
				'cached_input' => '$0.025 / 1M text-image-video',
				'output'       => '$1.50 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Preview models can change before becoming stable.', 'antimanual' ),
				esc_html__( 'Image generation, audio generation, and Live API are not supported.', 'antimanual' ),
				esc_html__( 'Less capable than Gemini Pro on harder reasoning tasks.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/models/gemini',
		],
		'gemini-3-pro-preview'          => [
			'best_for'          => esc_html__( 'Top-end multimodal understanding, agentic workflows, and advanced coding or reasoning tasks.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$2.00 / 1M (<=200K), $4.00 / 1M (>200K)',
				'cached_input' => '$0.20 / 1M (<=200K), $0.40 / 1M (>200K)',
				'output'       => '$12.00 / 1M (<=200K), $18.00 / 1M (>200K)',
			],
			'limitations'       => [
				esc_html__( 'Preview models can change before becoming stable and may have stricter rate limits.', 'antimanual' ),
				esc_html__( 'Image generation, audio generation, Live API, and Google Maps grounding are not supported.', 'antimanual' ),
				esc_html__( 'Context caching storage is billed separately.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/models/gemini',
		],
		'gemini-3-flash-preview'        => [
			'best_for'          => esc_html__( 'Fast multimodal generation with strong search, grounding, and tool support at lower cost than Gemini 3 Pro.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.50 / 1M text-image-video, $1.00 / 1M audio',
				'cached_input' => '$0.05 / 1M text-image-video, $0.10 / 1M audio',
				'output'       => '$3.00 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Preview models can change before becoming stable and may have stricter rate limits.', 'antimanual' ),
				esc_html__( 'Image generation, audio generation, Live API, and Google Maps grounding are not supported.', 'antimanual' ),
				esc_html__( 'Batch pricing is lower, but this plugin uses interactive requests.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/models/gemini',
		],
		'gemini-2.5-pro'                => [
			'best_for'          => esc_html__( 'Complex reasoning, coding, long-context analysis, and higher-stakes generation tasks.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$1.25 / 1M (<=200K), $2.50 / 1M (>200K)',
				'cached_input' => '$0.125 / 1M (<=200K), $0.25 / 1M (>200K)',
				'output'       => '$10.00 / 1M (<=200K), $15.00 / 1M (>200K)',
			],
			'limitations'       => [
				esc_html__( 'Audio generation, image generation, and Live API are not supported.', 'antimanual' ),
				esc_html__( 'Grounding and caching can add separate charges or quota limits.', 'antimanual' ),
				esc_html__( 'Higher rates apply when prompts exceed 200K tokens.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/gemini-api/docs/models/gemini-v2',
		],
		'gemini-2.5-flash'              => [
			'best_for'          => esc_html__( 'Balanced speed, scale, and multimodal reasoning for general high-volume site tasks.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio input', 'antimanual' ),
			'pricing'           => [
				'input'        => esc_html__( 'See current Google pricing page', 'antimanual' ),
				'cached_input' => esc_html__( 'See current Google pricing page', 'antimanual' ),
				'output'       => esc_html__( 'See current Google pricing page', 'antimanual' ),
			],
			'limitations'       => [
				esc_html__( 'Public model docs list token limits and capabilities, but current pricing for this stable code should be checked on Google pricing pages.', 'antimanual' ),
				esc_html__( 'Audio generation, image generation, and Live API are not supported.', 'antimanual' ),
				esc_html__( 'Grounding and caching can add separate charges or quota limits.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/gemini-api/docs/models/gemini-v2',
		],
		'gemini-2.5-flash-preview'      => [
			'best_for'          => esc_html__( 'Latest preview build of Gemini 2.5 Flash for lower-latency, high-throughput tasks with thinking enabled.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.30 / 1M text-image-video, $1.00 / 1M audio',
				'cached_input' => '$0.03 / 1M text-image-video, $0.10 / 1M audio',
				'output'       => '$2.50 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Google’s current public docs use a dated code such as gemini-2.5-flash-preview-09-2025; availability may vary from this plugin alias.', 'antimanual' ),
				esc_html__( 'Preview models can change before becoming stable and may have stricter rate limits.', 'antimanual' ),
				esc_html__( 'Audio generation, image generation, and Live API are not supported.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/gemini-api/docs/models/gemini-v2',
		],
		'gemini-2.5-flash-lite'         => [
			'best_for'          => esc_html__( 'Lowest-cost high-throughput generation, routing, extraction, and utility tasks at scale.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.10 / 1M text-image-video, $0.30 / 1M audio',
				'cached_input' => '$0.01 / 1M text-image-video, $0.03 / 1M audio',
				'output'       => '$0.40 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Cheaper and faster, but less capable than Pro or Flash on harder reasoning tasks.', 'antimanual' ),
				esc_html__( 'Audio generation, image generation, and Live API are not supported.', 'antimanual' ),
				esc_html__( 'Batch pricing is lower, but this plugin uses interactive requests.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/gemini-api/docs/models/gemini-v2',
		],
		'gemini-2.5-flash-lite-preview' => [
			'best_for'          => esc_html__( 'Preview Flash-Lite variant for cost-efficient, high-throughput workloads.', 'antimanual' ),
			'context_window'    => '1,048,576',
			'max_output_tokens' => '65,536',
			'knowledge_cutoff'  => '2025-01',
			'reasoning_effort'  => esc_html__( 'Thinking supported', 'antimanual' ),
			'modalities'        => esc_html__( 'Text output, text/image/video/audio/PDF input', 'antimanual' ),
			'pricing'           => [
				'input'        => '$0.10 / 1M text-image-video, $0.30 / 1M audio',
				'cached_input' => '$0.01 / 1M text-image-video, $0.03 / 1M audio',
				'output'       => '$0.40 / 1M',
			],
			'limitations'       => [
				esc_html__( 'Google’s current public docs use a dated code such as gemini-2.5-flash-lite-preview-09-2025; availability may vary from this plugin alias.', 'antimanual' ),
				esc_html__( 'Preview models can change before becoming stable and may have stricter rate limits.', 'antimanual' ),
				esc_html__( 'Audio generation, image generation, and Live API are not supported.', 'antimanual' ),
			],
			'docs_url'          => 'https://ai.google.dev/gemini-api/docs/models/gemini-v2',
		],
	];

	$gemini_chat_models = array_map(
		function ( $model ) use ( $gemini_model_details ) {
			$value = (string) ( $model['value'] ?? '' );
			$meta  = $gemini_model_details[ $value ] ?? [];
			return array_merge( $model, $meta );
		},
		$gemini_chat_models
	);

	$menu_slugs = [
		'configuration'    => 'antimanual',
		'knowledge_base'   => 'atml-knowledge-base',
		'chatbot'          => 'atml-chatbot',
		'generate_post'    => 'atml-generate-post',
		'auto_posting'     => 'atml-auto-posting',
		'auto_update'      => 'atml-auto-update',
		'repurpose_studio' => 'atml-repurpose-studio',
		'bulk_rewrite'     => 'atml-bulk-rewrite',
		'search_analytics' => 'atml-search-analytics',
		'docs'             => 'atml-docs',
		'forumax'          => 'atml-forumax',
		'faq_generator'    => 'atml-faq-generator',
		'translation'      => 'atml-translation',
		'seo_agent'        => 'atml-seo-agent',
		'internal_linking' => 'atml-internal-linking',
		'email_marketing'  => 'atml-email-marketing',
	];

	$menus = [
		[
			'title' => esc_html__( 'Configuration', 'antimanual' ),
			'slug'  => $menu_slugs['configuration'],
		],
		[
			'title'    => esc_html__( 'Knowledge Base', 'antimanual' ),
			'subtitle' => esc_html__( 'Manage your Knowledge Base to train AI', 'antimanual' ),
			'slug'     => $menu_slugs['knowledge_base'],
		],
		[
			'title'    => esc_html__( 'Chatbot', 'antimanual' ),
			'subtitle' => esc_html__( 'Manage your AI Chatbot', 'antimanual' ),
			'slug'     => $menu_slugs['chatbot'],
			'module'   => 'chatbot',
		],
		[
			'title'    => esc_html__( 'Generate Post', 'antimanual' ),
			'subtitle' => esc_html__( 'Generate a post using AI.', 'antimanual' ),
			'slug'     => $menu_slugs['generate_post'],
			'module'   => 'generate_post',
		],
		[
			'title'    => esc_html__( 'Auto Posting', 'antimanual' ),
			'subtitle' => esc_html__( 'Automatically generate and publish multiple posts on a schedule. Set it up once and let AI handle your content creation.', 'antimanual' ),
			'slug'     => $menu_slugs['auto_posting'],
			'module'   => 'auto_posting',
		],
		[
			'title'    => esc_html__( 'Auto Update', 'antimanual' ),
			'subtitle' => esc_html__( 'Refresh older posts on a schedule to keep content up to date.', 'antimanual' ),
			'slug'     => $menu_slugs['auto_update'],
			'module'   => 'auto_update',
		],
		[
			'title'    => esc_html__( 'Repurpose Studio', 'antimanual' ),
			'subtitle' => esc_html__( 'Turn one post into email, social, video scripts, and docs snippets.', 'antimanual' ),
			'slug'     => $menu_slugs['repurpose_studio'],
			'module'   => 'repurpose_studio',
		],
		[
			'title'    => esc_html__( 'Bulk Rewrite', 'antimanual' ),
			'subtitle' => esc_html__( 'Rewrite multiple posts at once using AI.', 'antimanual' ),
			'slug'     => $menu_slugs['bulk_rewrite'],
			'module'   => 'bulk_rewrite',
		],
		[
			'title'    => esc_html__( 'AI Search Block', 'antimanual' ),
			'subtitle' => esc_html__( 'View analytics for your AI Search blocks', 'antimanual' ),
			'slug'     => $menu_slugs['search_analytics'],
			'module'   => 'search_block',
		],
		[
			'title'    => esc_html__( 'Generate Docs', 'antimanual' ),
			'subtitle' => esc_html__( 'Generate comprehensive documentations.', 'antimanual' ),
			'slug'     => $menu_slugs['docs'],
			'module'   => 'generate_docs',
		],
		[
			'title'    => esc_html__( 'Forum Automation', 'antimanual' ),
			'subtitle' => esc_html__( 'Automate your forum conversations.', 'antimanual' ),
			'slug'     => $menu_slugs['forumax'],
			'module'   => 'forum_automation',
		],
		[
			'title'    => esc_html__( 'FAQ Generator', 'antimanual' ),
			'subtitle' => esc_html__( 'Generate FAQs for your website.', 'antimanual' ),
			'slug'     => $menu_slugs['faq_generator'],
			'module'   => 'faq_generator',
		],
		[
			'title'    => esc_html__( 'Translation', 'antimanual' ),
			'subtitle' => esc_html__( 'Translate your content into multiple languages.', 'antimanual' ),
			'slug'     => $menu_slugs['translation'],
			'module'   => 'translation',
		],
		[
			'title'    => esc_html__( 'SEO Agent', 'antimanual' ),
			'subtitle' => esc_html__( 'Analyze pages for SEO issues and get actionable recommendations.', 'antimanual' ),
			'slug'     => $menu_slugs['seo_agent'],
			'module'   => 'seo_agent',
		],
		[
			'title'    => esc_html__( 'Internal Linking', 'antimanual' ),
			'subtitle' => esc_html__( 'Analyze and optimize your internal link structure with AI-powered suggestions.', 'antimanual' ),
			'slug'     => $menu_slugs['internal_linking'],
			'module'   => 'internal_linking',
		],
		[
			'title'    => esc_html__( 'Email Campaign', 'antimanual' ),
			'subtitle' => esc_html__( 'AI-powered email campaigns with scheduling and subscriber management.', 'antimanual' ),
			'slug'     => $menu_slugs['email_marketing'],
			'module'   => 'email_marketing',
		],
	];

	// Filter out menus whose module is disabled.
	$module_defaults = \Antimanual\Api\PreferencesController::MODULE_DEFAULTS;
	$module_saved    = get_option( 'antimanual_module_prefs', [] );
	$module_prefs    = wp_parse_args( is_array( $module_saved ) ? $module_saved : [], $module_defaults );

	$menus = array_filter( $menus, function ( $menu ) use ( $module_prefs ) {
		// Menus without a module key (Configuration, Knowledge Base) are always visible.
		if ( empty( $menu['module'] ) ) {
			return true;
		}

		return ! empty( $module_prefs[ $menu['module'] ] );
	} );

	$TONES = [
		'casual'            => [
			'label'       => esc_html__( 'Casual', 'antimanual' ),
			'description' => esc_html__(
				'Use a relaxed and friendly tone. Keep the language simple and conversational, as if explaining to a beginner or a non-technical reader.',
				'antimanual'
			),
		],
		'professional'      => [
			'label'       => esc_html__( 'Professional', 'antimanual' ),
			'description' => esc_html__(
				'Use clear, concise, and formal language. Maintain a professional tone suitable for business or corporate documentation.',
				'antimanual'
			),
		],
		'technical'         => [
			'label'       => esc_html__( 'Technical', 'antimanual' ),
			'description' => esc_html__(
				'Use precise and detailed language appropriate for developers and technical users. Include accurate terminology and avoid unnecessary simplifications.',
				'antimanual'
			),
		],
		'beginner-friendly' => [
			'label'       => esc_html__( 'Beginner-friendly', 'antimanual' ),
			'description' => esc_html__(
				'Use very simple and clear language. Avoid jargon and assume the reader is new to the topic. Explain concepts in layman\'s terms.',
				'antimanual'
			),
		],
		'instructor'        => [
			'label'       => esc_html__( 'Instructor', 'antimanual' ),
			'description' => esc_html__(
				'Adopt a tone like a course instructor or tutor. Explain concepts step by step, and guide the user as if teaching a class.',
				'antimanual'
			),
		],
		'authoritative'     => [
			'label'       => esc_html__( 'Authoritative', 'antimanual' ),
			'description' => esc_html__(
				'Write confidently and with authority, as if the content is an official source. Avoid uncertainty or casual language.',
				'antimanual'
			),
		],
		'enthusiastic'      => [
			'label'       => esc_html__( 'Enthusiastic', 'antimanual' ),
			'description' => esc_html__(
				'Use energetic and upbeat language. Make the topic sound exciting and engaging while still being informative.',
				'antimanual'
			),
		],
		'minimalist'        => [
			'label'       => esc_html__( 'Minimalist', 'antimanual' ),
			'description' => esc_html__(
				'Use short, to-the-point sentences. Focus only on what\'s necessary. Avoid fluff or long explanations.',
				'antimanual'
			),
		],
		'support agent'     => [
			'label'       => esc_html__( 'Support Agent', 'antimanual' ),
			'description' => esc_html__(
				'Write like a helpful customer support agent. Be polite, solution-focused, and empathetic. Assume you\'re responding to user confusion.',
				'antimanual'
			),
		],
		'blog-style'        => [
			'label'       => esc_html__( 'Blog Style', 'antimanual' ),
			'description' => esc_html__(
				'Use an informal blog-post tone. Include personal touches, first-person references, and make the writing feel more human and story-like.',
				'antimanual'
			),
		],
	];

	$ATML_STORE = [
		'openai_chat_models' => $chat_models,
		'gemini_chat_models' => $gemini_chat_models,
		'menus'              => $menus,
		'menu_slugs'         => $menu_slugs,
		'tones'              => $TONES,
	];

	$GLOBALS['ATML_STORE'] = $ATML_STORE;
}
