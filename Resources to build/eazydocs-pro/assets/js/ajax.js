(function ($) {
  'use strict'

  $(document).ready(function () {
    
    // Contributor [ Delete ] 
    function ezd_contribute_delete() {
      $('.ezd_contribute_delete').off('click').on('click', function (e) {
        e.preventDefault();

        let contributor_id = $(this).attr('data-contributor-delete');
        let data_doc_id    = $(this).attr('data-doc-id');
        let data_name      = $(this).attr('data_name');

        $.ajax({
          url: eazydocs_ajax_search.ajax_url,
          method: 'POST',
          data: {
            action: 'ezd_doc_contributor',
            contributor_delete: contributor_id,
            data_doc_id: data_doc_id,
            nonce: eazydocs_ajax_search.eazydocs_local_nonce
          },
          beforeSend: function () {
            $('.ezd_contribute_delete[data-contributor-delete=' + contributor_id + ']').html(
              '<span class="spinner-border ezd-contributor-loader"><span class="visually-hidden">Loading...</span></span>'
            );
          },
          success: function (response) {
            $('#user-' + contributor_id).remove();
            $('.to-add-user-' + contributor_id).not(':last').remove();

            // Remove avatar by ID or fallback to title
            $('#contributor-avatar-' + contributor_id).remove();
            $('.contributed_user_list a[title='+data_name+']').remove();

            $('#to_add_contributors').append(response);
            ezd_contributor_add();
          },
          error: function () {
            console.log('Oops! Something went wrong, try again!');
          }
        });
      });
    }
    ezd_contribute_delete();

    // Contributor [ Add ] 
    function ezd_contributor_add() {
      $('.ezd_contribute_add').off('click').on('click', function (e) {
        e.preventDefault();

        let contributor_add = $(this).attr('data-contributor-add');
        let data_doc_id     = $(this).attr('data-doc-id');

        let container  = $(this).closest('.users_wrap_item');
        let user_img   = container.find('img').attr('src');
        let user_name  = $(this).attr('data_name');
        let user_url   = container.find('a').first().attr('href');
        let avatar_src = user_img.replace(/([?&])s=\d+/, '$1s=25');

        $.ajax({
          url: eazydocs_ajax_search.ajax_url,
          method: 'POST',
          data: {
            action: 'ezd_doc_contributor',
            contributor_add: contributor_add,
            data_doc_id: data_doc_id,
            nonce: eazydocs_ajax_search.eazydocs_local_nonce
          },
          beforeSend: function () {
            $('.ezd_contribute_add[data-contributor-add=' + contributor_add + ']').html(
              '<span class="spinner-border ezd-contributor-loader"><span class="visually-hidden">Loading...</span></span>'
            );
          },
          success: function (response) {
            $('#added_contributors').append(response);
            $('#to-add-user-' + contributor_add).remove();
            $('.user-' + contributor_add).not(':last').remove();

            // Remove old avatar if already exists
            $('#contributor-avatar-' + contributor_add).remove();

            // Add avatar
            $('.contributed_user_list').append(
              '<a id="contributor-avatar-' + contributor_add + '" title="' + user_name + '" href="' + user_url + '" data-bs-toggle="tooltip" data-bs-placement="bottom">' +
              '<img width="24px" src="' + avatar_src + '">' +
              '</a>'
            );

            // $('[data-bs-toggle="tooltip"]').tooltip();
            ezd_contribute_delete(); // Rebind delete buttons
          },
          error: function () {
            console.log('Oops! Something went wrong, try again!');
          }
        });
      });
    }
    ezd_contributor_add();
    
    // EazyDocs login submission
    $('.ezd-form-wrap').submit(function (e) {
        e.preventDefault();

        // Get form data
        var formData = $(this).serialize();
        var nonceValue = eazydocs_ajax_search.eazydocs_local_nonce;

        // Perform AJAX request
        $.ajax({
            type: 'POST',
            url: eazydocs_ajax_search.ajax_url,
            data: formData + '&action=ezd_login_check&nonce=' + nonceValue,
            dataType: 'json',
            beforeSend: function () {
                $('.ezd-login-error').html('<span class="spinner-border ezd-login-loader"><span class="visually-hidden">Loading...</span></span>');
            },
            success: function (response) {              
                $('.ezd-login-error').html(response.success);
                if ( response.redirect_to ) {
                  window.location.href = response.redirect_to;
                }     
                           
                if ( response.success == false ) {
                  $('.ezd-login-error').html(response.message);
                }
            }
        });
    });
    // end
    
  });
})(jQuery);