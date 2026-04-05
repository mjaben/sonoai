<div class="easydocs-tab tab-active" id="analytics-helpful">
    <h2 class="title"> Helpful Docs </h2> <br>

	<div class="easydocs-filter-container">
		<ul class="single-item-filter">
			<li class="easydocs-btn easydocs-btn-gray-light easydocs-btn-rounded easydocs-btn-sm is-active mixitup-control-active most_helpful" data-filter="all">
				<?php esc_html_e( 'Most Helpful', 'eazydocs-pro' ); ?>
			</li>
			<li class="easydocs-btn easydocs-btn-gray-light easydocs-btn-rounded easydocs-btn-sm least_helpful" data-filter=".least-helpful">
				<?php esc_html_e( 'Least Helpful', 'eazydocs-pro' ); ?>
			</li>
			</li>
			<li class="easydocs-btn easydocs-btn-gray-light easydocs-btn-rounded easydocs-btn-sm most_viewed" data-filter=".most-viewed">
				<?php esc_html_e( 'Most Viewed', 'eazydocs-pro' ); ?>
			</li>
		</ul>
		<form method="get" id="search_for_most_helpful">
			<ul class="single-item-filter">
				<input type="hidden" name="type" value="most_helpful" id="ez_search__type" />
				<input type="hidden" name="action" value="ezd_search_helpful_docs_paginate" />
				<li><?php esc_html_e('Number of items to show', 'eazydocs-pro'); ?></li>
				<li><?php echo esc_html( ':' ); ?></li>
				<li>
					<input type="number" name="total_page" id="search_helpful_docs" value="<?php echo esc_attr('10'); ?>" style="width: 80px !important;">
				</li>
			</ul>
		</form>
		<form method="get" id="search_for_least_helpful">
			<ul class="single-item-filter">
				<input type="hidden" name="type" value="least_helpful" id="ez_search__type" />
				<input type="hidden" name="action" value="ezd_search_helpful_docs_paginate" />
				<li><?php esc_html_e('Number of items to show', 'eazydocs-pro'); ?></li>
				<li><?php esc_html_e(':', 'eazydocs-pro'); ?></li>
				<li>
					<input type="number" name="total_page" id="search_helpful_docs" value="<?php echo esc_attr('10'); ?>" style="width: 80px !important;">
				</li>
			</ul>
		</form>
		<form method="get" id="search_for_most_viewed">
			<ul class="single-item-filter">
				<input type="hidden" name="type" value="most_viewed" id="ez_search__type" />
				<input type="hidden" name="action" value="ezd_search_helpful_docs_paginate" />
				<li><?php esc_html_e('Number of items to show', 'eazydocs-pro'); ?></li>
				<li><?php esc_html_e(':', 'eazydocs-pro'); ?></li>
				<li>
					<input type="number" name="total_page" id="search_helpful_docs" value="<?php echo esc_attr('10'); ?>" style="width: 80px !important;">
				</li>
			</ul>
		</form>
	</div>

	<?php
	/**
	 * Most Helpful and Least Docs
	 */
	include dirname(__FILE__) . '/helpful-most.php';
	include dirname(__FILE__) . '/helpful-least.php';
	include dirname(__FILE__) . '/most-viewed-docs.php';
	?>
</div>

<script>
    jQuery(document).ready(function($) {
        $('#most_helpful').show();
        $('#least_helpful').hide();
		$('#most_viewed').hide();
		$('#search_for_least_helpful').hide();
		$('#search_for_most_viewed').hide();
		$('#search_for_most_helpful').show();

        $('.most_helpful').click(function() {
            $('#most_helpful').show();
            $('#least_helpful').hide();
			$('#ez_search__type').val('most_helpful');
			$('#search_for_least_helpful').hide();
			$('#search_for_most_viewed').hide();
			$('#search_for_most_helpful').show();
			$('#most_viewed').hide();
        });
        $('.least_helpful').click(function() {
            $('#most_helpful').hide();
            $('#least_helpful').show();
			$('#ez_search__type').val('least_helpful');
			$('#search_for_most_helpful').hide();
			$('#search_for_most_viewed').hide();
			$('#search_for_least_helpful').show();
			$('#most_viewed').hide();
        });
		$('.most_viewed').click(function() {
			$('#most_helpful').hide();
			$('#least_helpful').hide();
			$('#most_viewed').show();
			$('#ez_search__type').val('most_viewed');
			$('#search_for_most_viewed').show();
			$('#search_for_most_helpful').hide();
			$('#search_for_least_helpful').hide();
		});
    });

	jQuery(document).ready(function($) {
		$('#search_for_most_helpful, #search_for_least_helpful, #search_for_most_viewed').on('change', function(e) {
			e.preventDefault(); // Prevent form submission from reloading the page

			// Get the form data
			var formData = $(this).serialize();

			// Send the AJAX request after 200ms
			setTimeout(function() {
				$.get({
					url: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
					data: formData,
					success: function(response) {
						// if type is most_helpful then show most helpful docs else show least helpful docs
						if($('#ez_search__type').val() == 'most_helpful') {
							$('#most_helpful').html(response);
						} else if($('#ez_search__type').val() == 'most_viewed') {
							$('#most_viewed').html(response);
						} else {
							$('#least_helpful').html(response);
						}
					}
				});
			}, 200);
			
		});
	});
</script>