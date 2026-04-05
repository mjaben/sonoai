<?php
$username = get_query_var( 'ezd_user_slug' );
$user     = get_user_by( 'login', $username );

if ( ! $user ) {
    wp_redirect( home_url() );
    exit;
}

$user_email        = $user->user_email;
$user_website      = $user->user_url;
$user_registered   = date_i18n( get_option( 'date_format' ), strtotime( $user->user_registered ) );
$user_display      = $user->display_name;
$user_short_bio    = get_user_meta( $user->ID, 'description', true );
$user_bio_details  = get_user_meta( $user->ID, 'ezd_user_bio_details', true );
$user_job          = get_user_meta( $user->ID, 'ezd_user_job_title', true );
$user_twitter      = esc_url( get_user_meta( $user->ID, 'ezd_user_twitter', true ) );
$user_linkedin     = esc_url( get_user_meta( $user->ID, 'ezd_user_linkedin', true ) );
$user_github       = esc_url( get_user_meta( $user->ID, 'ezd_user_github', true ) );
$views_count       = intval( ezdpro_get_total_views( $user->ID ) );
$author_rss        = esc_url( get_author_posts_url( $user->ID ) . 'feed/' );

$contact_links = array(
    array(
        'label' => esc_html__( 'Email', 'eazydocs-pro' ),
        'url'   => $user_email ? 'mailto:' . antispambot( esc_attr( $user_email ) ) : '',
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 2v.01L12 13 4 6.01V6h16zM4 20V8.99l8 6.99 8-6.99V20H4z"/></svg>'
    ),
    array(
        'label' => esc_html__( 'Website', 'eazydocs-pro' ),
        'url'   => $user_website ? esc_url( $user_website ) : '',
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 4a8 8 0 100 16 8 8 0 000-16zm0 2c1.93 0 3.68.78 4.95 2.05A6.978 6.978 0 0112 20a6.978 6.978 0 01-4.95-11.95A6.978 6.978 0 0112 6zm0 2a6 6 0 100 12A6 6 0 0012 8z"/></svg>'
    ),
    array(
        'label' => esc_html__( 'GitHub', 'eazydocs-pro' ),
        'url'   => $user_github,
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M12 2C6.48 2 2 6.58 2 12.26c0 4.5 2.87 8.32 6.84 9.67.5.09.68-.22.68-.48 0-.24-.01-.87-.01-1.7-2.78.62-3.37-1.36-3.37-1.36-.45-1.17-1.1-1.48-1.1-1.48-.9-.63.07-.62.07-.62 1 .07 1.53 1.05 1.53 1.05.89 1.56 2.34 1.11 2.91.85.09-.66.35-1.11.63-1.37-2.22-.26-4.56-1.14-4.56-5.07 0-1.12.39-2.03 1.03-2.75-.1-.26-.45-1.3.1-2.7 0 0 .84-.28 2.75 1.05A9.42 9.42 0 0112 6.84c.85.004 1.71.12 2.51.35 1.91-1.33 2.75-1.05 2.75-1.05.55 1.4.2 2.44.1 2.7.64.72 1.03 1.63 1.03 2.75 0 3.94-2.34 4.81-4.57 5.07.36.32.68.94.68 1.9 0 1.37-.01 2.47-.01 2.81 0 .27.18.58.69.48A10.01 10.01 0 0022 12.26C22 6.58 17.52 2 12 2z"/></svg>'
    ),
    array(
        'label' => esc_html__( 'LinkedIn', 'eazydocs-pro' ),
        'url'   => $user_linkedin,
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M19 0h-14c-2.76 0-5 2.24-5 5v14c0 2.76 2.24 5 5 5h14c2.76 0 5-2.24 5-5v-14c0-2.76-2.24-5-5-5zm-11 19h-3v-9h3v9zm-1.5-10.28c-.97 0-1.75-.79-1.75-1.75s.78-1.75 1.75-1.75 1.75.79 1.75 1.75-.78 1.75-1.75 1.75zm13.5 10.28h-3v-4.5c0-1.08-.02-2.47-1.5-2.47-1.5 0-1.73 1.17-1.73 2.39v4.58h-3v-9h2.89v1.23h.04c.4-.75 1.38-1.54 2.84-1.54 3.04 0 3.6 2 3.6 4.59v4.72z"/></svg>'
    ),
    array(
        'label' => esc_html__( 'Twitter/X', 'eazydocs-pro' ),
        'url'   => $user_twitter,
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M22.162 5.656l-7.91 9.59 8.19 8.754h-3.6l-6.36-6.8-6.36 6.8h-3.6l8.19-8.754-7.91-9.59h3.6l6.08 7.36 6.08-7.36h3.6z"/></svg>'
    ),
    array(
        'label' => esc_html__( 'RSS Feed', 'eazydocs-pro' ),
        'url'   => $author_rss,
        'icon'  => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true"><path d="M6.18 17.82a2.18 2.18 0 11-4.36 0 2.18 2.18 0 014.36 0zm-4.36-7.27v3.27c7.18 0 13 5.82 13 13h3.27c0-9.01-7.26-16.27-16.27-16.27zm0-5.45v3.27c11.05 0 20 8.95 20 20h3.27c0-12.85-10.43-23.27-23.27-23.27z"/></svg>'
    ),
);

$top_topics = [];
// Get IDs of all 'docs' posts by this author
$user_docs_ids = get_posts( array(
    'post_type'      => 'docs',
    'author'         => $user->ID,
    'fields'         => 'ids',
    'posts_per_page' => -1,
) );

if ( ! empty( $user_docs_ids ) ) {
    // Get all tags attached to these posts
    $tags = wp_get_object_terms( $user_docs_ids, 'doc_tag', array(
        'orderby'    => 'count',
        'order'      => 'DESC',
        'fields'     => 'all',
        'hide_empty' => true,
    ) );

    if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
        // Sort tags by count descending
        usort( $tags, function( $a, $b ) {
            return $b->count - $a->count;
        } );
        // Take top 3
        $tags = array_slice( $tags, 0, 3 );
        foreach ( $tags as $tag ) {
            $top_topics[] = $tag->name;
        }
    }
}

$contributions      = ezdpro_get_contributions_by_user( $user->ID, 5, 0 );
$totalContributions = intval( count_user_posts( $user->ID, 'docs' ) );

$activities         = ezdpro_get_activities_by_user( $user->ID, 5, 0 );
$totalActivities    = intval( $activities['total'] );
$activities         = $activities['activities'];

$text = (object) [
    'load_more' => __( 'Load More', 'eazydocs-pro' ),
    'loading'   => __( 'Loading...', 'eazydocs-pro' ),
];

get_header();
ezd_header_with_block_theme();
?>

<div class="ezd-profile-layout">
    <aside class="ezd-sidebar" role="complementary">
        <div class="ezd-sidebar-header">
            <img src="<?php echo esc_url( get_avatar_url( $user->ID ) ); ?>" alt="<?php echo esc_attr( $user_display ) . ', ' . esc_attr__('profile picture', 'eazydocs-pro') ?>" class="ezd-avatar">
            <div class="ezd-sidebar-author-info">
                <h1 id="ezd-author-name-sidebar"><?php echo esc_html( $user_display ) ?></h1>
                <?php if ( !empty( $user_job ) ) : ?>
                    <p class="ezd-job-title"><?php echo esc_html( $user_job ); ?></p>
                <?php endif; ?>
            </div>
        </div>
        <?php if ( !empty( $user_short_bio ) ) : ?>
            <div class="ezd-sidebar-short-bio">
                <?php echo wp_kses_post( wpautop($user_short_bio) ); ?>
            </div>
        <?php endif; ?>
        <section class="ezd-sidebar-section ezd-sidebar-contact-links" aria-labelledby="ezd-contact-heading-sidebar">
            <h2 id="ezd-contact-heading-sidebar">
                <?php esc_html_e( 'Contact & Links', 'eazydocs-pro' ); ?>
            </h2>
            <ul>
                <?php
                $allowed_html = wp_kses_allowed_html( 'post' );

                // Allow SVG and its children
                $allowed_html['svg'] = [
                    'class'       => true,
                    'xmlns'       => true,
                    'width'       => true,
                    'height'      => true,
                    'viewBox'     => true,
                    'aria-hidden' => true,
                    'role'        => true,
                    'focusable'   => true,
                    'fill'        => true,
                ];
                $allowed_html['path'] = [
                    'd'    => true,
                    'fill' => true,
                ];
                foreach ( $contact_links as $link ) {
                    if ( ! empty( $link['url'] ) ) {
                        echo '<li><a href="' . esc_url( $link['url'] ) . '" target="_blank" rel="noopener noreferrer">' . wp_kses( $link['icon'], $allowed_html ) . '<span>' . esc_html( $link['label'] ) . '</span></a></li>';
                    }
                }
                ?>
            </ul>
        </section>
        <section class="ezd-sidebar-section ezd-sidebar-stats" aria-labelledby="ezd-stats-heading-sidebar">
            <h2 id="ezd-stats-heading-sidebar">
                <?php esc_html_e( 'Author Statistics', 'eazydocs-pro' ); ?>
            </h2>
            <div class="ezd-stat-item">
                <strong> <?php echo intval( count_user_posts( $user->ID, 'docs' ) ); ?> </strong>
                <div> <?php esc_html_e( 'Docs/Contributions', 'eazydocs-pro' ); ?> </div>
            </div>

            <?php
            // Get top 3 topics (tags) used by this author
            if ( ! empty( $top_topics ) ) :
                ?>
                <div class="ezd-stat-item">
                    <?php
                    $topic_links = [];
                    foreach ( $top_topics as $topic_name ) {
                        $term = get_term_by( 'name', $topic_name, 'doc_tag' );
                        if ( $term && ! is_wp_error( $term ) ) {
                            $topic_links[] = sprintf(
                                '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
                                esc_url( get_term_link( $term ) ),
                                esc_html( $term->name )
                            );
                        }
                    }
                    
                    echo wp_kses_post( implode( ', ', $topic_links ) );
                    ?>
                    <div> <?php esc_html_e( 'Top Topics', 'eazydocs-pro' ); ?> </div>
                </div>
                <?php
            endif;
            ?>
            <div class="ezd-stat-item">
                <strong> <?php echo esc_html__( 'Since', 'eazydocs-pro' ) . ': ' . esc_html( $user_registered ); ?></strong>
                <span> <?php esc_html_e( 'Member Status', 'eazydocs-pro' ); ?></span>
            </div>
            <div class="ezd-stat-item">
                <strong><?php echo esc_html( $views_count ); ?></strong>
                <div><?php esc_html_e( 'Views', 'eazydocs-pro' ); ?></div>
            </div>
        </section>
    </aside>
    <main class="ezd-main-content-area" id="ezd-main-content" role="main">
        <?php if ( !empty( $user_bio_details ) ) : ?>
            <article class="ezd-card ezd-biography" aria-labelledby="ezd-bio-heading">
                <h2 class="h2" id="ezd-bio-heading"><?php esc_html_e( 'Biography', 'eazydocs-pro' ); ?></h2>
                <div class="ezd-markdown-content">
                    <div class="ezd-detailed-bio">
                        <?php echo wp_kses_post( wpautop( $user_bio_details ) ); ?>
                    </div>
                </div>
            </article>
        <?php endif; ?>
        <section class="ezd-card ezd-contributions" aria-labelledby="ezd-contributions-heading">
            <h2 class="h2" id="ezd-contributions-heading">
                <?php printf( '%s (%d)', esc_html__( 'List of Contributions', 'eazydocs-pro' ), intval( $totalContributions ) ); ?>
            </h2>
            <div class="ezd-contribution-list"></div>
            <div class="ezd-load-more-btn">
                <button id="load-more-contributions">
                    <?php echo esc_html($text->load_more) ?>
                </button>
            </div>
        </section>
        <div class="ezd-card">
            <section class="ezd-main-section" aria-labelledby="activity-section-title">
                <h2 id="activity-section-title" class="h2 ezd-main-section-title">
                    <?php esc_html_e( 'Recent Activities', 'eazydocs-pro' ); ?>
                </h2>
                <ul class="ezd-activity-feed"></ul>
                <div class="ezd-load-more-btn">
                    <button id="load-more-activities"> <?php echo esc_html( $text->load_more ); ?> </button>
                </div>
            </section>
        </div>
    </main>
</div>

<script>
jQuery(document).ready(function ($) {

    // Human time difference function
    function humanTimeDiff(date) {
        const seconds = Math.floor((Date.now() - new Date(date).getTime()) / 1000);

        const intervals = [
            {
                label: {
                    singular: '<?php echo esc_js( __( 'year', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'years', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 31536000
            },
            {
                label: {
                    singular: '<?php echo esc_js( __( 'month', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'months', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 2592000
            },
            {
                label: {
                    singular: '<?php echo esc_js( __( 'week', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'weeks', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 604800
            },
            {
                label: {
                    singular: '<?php echo esc_js( __( 'day', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'days', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 86400
            },
            {
                label: {
                    singular: '<?php echo esc_js( __( 'hour', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'hours', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 3600
            },
            {
                label: {
                    singular: '<?php echo esc_js( __( 'minute', 'eazydocs-pro' ) ); ?>',
                    plural: '<?php echo esc_js( __( 'minutes', 'eazydocs-pro' ) ); ?>'
                },
                seconds: 60
            },
        ];

        for (const interval of intervals) {
            const count = Math.floor(seconds / interval.seconds);
            if (count >= 1) {
                return count + ' ' + (count === 1 ? interval.label.singular : interval.label.plural) + ' <?php echo esc_js( __( 'ago', 'eazydocs-pro' ) ); ?>';
            }
        }

        return '<?php echo esc_js( __( 'just now', 'eazydocs-pro' ) ); ?>';
    }

    class Contributions {
        totalExists = Number("<?php echo esc_js( $totalContributions ); ?>");
        contributions = <?php echo json_encode( $contributions ); ?>;
        $container = $('.ezd-contribution-list');
        $loadMoreBtn = $('#load-more-contributions');

        constructor() {
            this.render();
        }

        render() {
            const renderedIds = this.$container.find(`.ezd-contribution-item`).map((_, el) => $(el).data('contribution-id')).get();

            this.contributions.forEach(contribution => {
                if (renderedIds.includes(contribution.id)) return;

                const $article = $(`
                    <article
                        class="ezd-contribution-item appearing"
                        data-contribution-id="${contribution.id}"
                    >
                        <h3 class="ezd-contribution-title">
                            <a href="${contribution.link}" target="_blank">${contribution.title || '<small>(no title)</small>'}</a>
                        </h3>
                        <p class="ezd-contribution-meta">
                            <?php esc_html_e( 'Published', 'eazydocs-pro' ) ?>: 
                            ${new Date(contribution.date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })}
                        </p>
                        <div class="ezd-contribution-tags">
                            ${contribution.tags.map(tag => {
                                return `<a href="${tag.link}" class="ezd-tag" target="_blank">${tag.name}</a>`;
                            }).join(' ')}
                        </div>
                    </article>
                `);

                this.$container.append($article);
            });

            const renderDelay = 100;
            let currentDelay = 0;

            this.$container.find(`.ezd-contribution-item.appearing`).each(function () {
                setTimeout(() => $(this).removeClass('appearing'), currentDelay);
                currentDelay += renderDelay;
            });

            if(this.contributions.length >= this.totalExists) {
                this.$loadMoreBtn.hide();
            }
        }

        add(contribution) {
            this.contributions.push(contribution);

            const uniqueIds = [];
            this.contributions = this.contributions.filter(item => {
                if(uniqueIds.includes(item.id)) return;

                uniqueIds.push(item.id);
                return true;
            });

            this.render();
        }

        get total() {
            return this.contributions.length;
        }
    }

    class Activities {
        totalExists = Number("<?php echo esc_js( $totalActivities ); ?>");
        activities = <?php echo json_encode( $activities ); ?>;
        $container = $('.ezd-activity-feed');
        $loadMoreBtn = $('#load-more-activities');

        constructor() {
            this.render();
        }

        render() {
            const renderedIds = this.$container.find(`.ezd-activity-item`).map((_, el) => $(el).data('activity-id')).get();

            this.activities.forEach(activity => {
                if (renderedIds.includes(activity.id)) return;

                const $article = $(`
                    <li class="ezd-activity-item appearing" data-activity-id="${activity.id}">
                        <div class="ezd-activity-date">
                            ${humanTimeDiff(activity.date*1000)}
                        </div>
                        <div class="ezd-activity-content">
                            <p><strong>${activity.label}:</strong> <a href="${activity.permalink}">${activity.title}</a></p>
                        </div>
                    </li>
                `);

                this.$container.append($article);
            });

            const renderDelay = 100;
            let currentDelay = 0;

            this.$container.find(`.ezd-activity-item.appearing`).each(function () {
                setTimeout(() => $(this).removeClass('appearing'), currentDelay);
                currentDelay += renderDelay;
            });

            if(this.activities.length >= this.totalExists) {
                this.$loadMoreBtn.hide();
            }
        }

        add(activity) {
            this.activities.push(activity);

            const uniqueIds = [];
            this.activities = this.activities.filter(item => {
                if(uniqueIds.includes(item.id)) return;

                uniqueIds.push(item.id);
                return true;
            });

            this.render();
        }

        get total() {
            return this.activities.length;
        }
    }

    const contributions = new Contributions();
    const activities = new Activities();

    const userId = "<?php echo esc_js( $user->ID ); ?>";

	const $contributionsBtn = $(`#load-more-contributions`);
	const $activitiesBtn = $(`#load-more-activities`);

	$contributionsBtn.click(async function () {
        const $contributionsBtn = $(this);

        if($contributionsBtn.hasClass('loading')) return;

		$contributionsBtn.text("<?php echo esc_js( $text->loading ); ?>");
        $contributionsBtn.addClass("loading");

		$.ajax({
            method: 'GET',
            url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
            data: {
                action: 'ezdpro_contributions_by_user',
                user_id: userId,
                nonce: '<?php echo esc_js( wp_create_nonce( 'ezdpro_contributions_nonce' ) ); ?>',
                offset: contributions.total,
            },
            success: (res) => {
                res?.data?.forEach(contribution => {
                    contributions.add(contribution);
                });
            },
            error: (err) => {},
            complete: () => {
                $contributionsBtn.text("<?php echo esc_js( $text->load_more ) ?>");
                $contributionsBtn.removeClass("loading");
            }
        });
	});

	$activitiesBtn.click(async function () {
        const $activitiesBtn = $(this);

        if($activitiesBtn.hasClass('loading')) return;

		$activitiesBtn.text("<?php echo esc_js( $text->loading ); ?>");
        $activitiesBtn.addClass("loading");

		$.ajax({
            method: 'GET',
            url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
            data: {
                action: 'ezdpro_activities_by_user',
                user_id: userId,
                nonce: '<?php echo esc_js( wp_create_nonce( 'ezdpro_activities_nonce' ) ); ?>',
                offset: activities.total,
            },
            success: (res) => {
                res?.data?.forEach(activity => {
                    activities.add(activity);
                });
            },
            error: (err) => {},
            complete: () => {
                $activitiesBtn.text("<?php echo esc_js( $text->load_more ) ?>");
                $activitiesBtn.removeClass("loading");
            }
        });
	});
});
</script>

<?php
ezd_footer_with_block_theme();
get_footer();