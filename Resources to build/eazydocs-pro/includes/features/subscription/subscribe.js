;(function($){
  $(document).ready(function(){

  // Subscribe
  $(document).on('click', '.ezd-subscription-btn.subscribe', function (e) {      
      e.preventDefault();
    
      $('.ezd-subscription-popup').addClass('hidden');
      
      // if not data-token
      if( !$(this).attr('data-token') ){
          var docId = $(this).attr('data-id');
          $('.ezd-subscription-form-wrap#'+docId).addClass('active');
        
          $(document).mouseup('click', function (e) {
              // Check if the clicked element is not inside .ezd-subscription-inner
              if (!$(e.target).closest('.ezd-subscription-inner').length) {
                $('.ezd-subscription-form-wrap').removeClass('active');     
              }
              
              $('.ezd-subscription-cancel, .ezd-subscription-close').on('click', function (e) {
                e.preventDefault();
                $('.ezd-subscription-form-wrap').removeClass('active');
              });
          });
      }
  });
  
  
  // Check for disallowed characters in the name field
  function nameDisallowedCharacters(str) {
    // Regular expression to check for disallowed characters
    var pattern = /[#$%^&*()+={}\[\];:'",<>\/?@]/;          
    return pattern.test(str);
  }
  
  var submitBtn = $('.ezd-subscription-submit').html();
    
  // form submit with ajax    
  $(document).on('submit', '.ezd-subscription-form', function (e) {
      e.preventDefault();

      // Get form data
      var formData = $(this).serialize();

      const parsedFormData = new URLSearchParams(formData);

      // Get the value of ezd_subscription_name
      const ezdSubscriptionName = parsedFormData.get('ezd_subscription_name');

      var nonceValue = eazydocs_ajax_search.eazydocs_local_nonce;
      var subscriptions_success = eazydocs_subscription.subscriptions_success;
      var specialCharacter = eazydocs_subscription.character_not_allowed;
      
      if ( nameDisallowedCharacters(ezdSubscriptionName) ) {
          $('.ezd-subscription-form').append('<p class="ezd-response-alert">'+specialCharacter+'</p>');
          $('.ezd-response-alert').not(':first').remove();
          
      }  else {

        // Perform AJAX request
        $.ajax({
            type: 'POST',
            url: eazydocs_ajax_search.ajax_url,
            data: formData + '&action=ezd_subscription_form&nonce=' + nonceValue + '&subscriptions_success=' + subscriptions_success,
            dataType: 'json',
            beforeSend: function () {
                $('.ezd-subscription-submit').html('<span class="spinner-border ezd-subscription-loader"><span class="visually-hidden">Loading...</span></span>');
            },
            success: function (response) {
              $('.ezd-subscription-submit').html(submitBtn);
              // last response alert
              $('.ezd-subscription-form').append('<p class="ezd-response-alert">'+response.data+'</p>');
              $('.ezd-response-alert:not(:last-child)').remove();
              ezd_logged_user_subscription()
              
            },
            error: function (error) {
              $('.ezd-subscription-submit').html(submitBtn);
              $('.ezd-subscription-form').append('<p class="ezd-response-alert">Oops! Something wrong, try again!</p>');
              $('.ezd-response-alert:not(:last-child)').remove();
            }
        });
      }
  });
  
  
    $(document) .on('click', '.ezd-subscription-btn.subscribed', function (e) {
        e.preventDefault();

        var subscriptions_btn = eazydocs_subscription.subscriptions_btn; 
        var token               = $(this).attr('data-token');
        var id                  = $(this).attr('data-id');        
        var ezd_doc_post_title  = '';

        if ( eazydocs_subscription.unsubscriptions_post_title ) {
            ezd_doc_post_title  = eazydocs_subscription.doc_post_title+'?';
        }

        $('body').append("<div class='ezd-subscription-popup top-center unsubscription'><div class='ezd-subscription-popup-inner'><h1>"+eazydocs_subscription.unsubscriptions_heading+"</h1><p>"+eazydocs_subscription.unsubscriptions_desc +' '+ezd_doc_post_title+"</p><span class='ezd-subscription-preloader hidden'><span class='ezd-subscription-loader-wrap'></span></span><span class='ezd-subscription-action'><a href='' class='ezd-subscription-confirm'>"+eazydocs_subscription.unsubscriptions_submit_btn+"</a><a href='' class='ezd-subscription-cancel'>"+eazydocs_subscription.unsubscriptions_cancel_btn+"</a></span></div></div>");

        $('.ezd-subscription-cancel').on('click', (function (e) {
              e.preventDefault();
              $('.ezd-subscription-popup').addClass('hidden');            
            })
        );

        // if click .ezd-subscription-confirm
        $('.ezd-subscription-confirm').on('click', (function (e) {

            $('.ezd-subscription-popup .ezd-subscription-popup-inner h1').text('Unsubscribing..');
            $('.ezd-subscription-preloader').removeClass('hidden');

            e.preventDefault();
              
            $.ajax({
                type: 'POST',
                url: eazydocs_ajax_search.ajax_url,
                data: {
                  action: 'ezd_unsubscription_create',
                  token: token,
                  token_id: id
                },
                dataType: 'json',
                beforeSend: function () {
                  $('.ezd-subscription-btn[data-id='+id+'].subscribed').text('Unsubscribing...');              
                },
                success: function (response) {
                  console.log(response.data);
                
                  $('.ezd-subscription-btn[data-id='+id+']').text(subscriptions_btn);
                  $('.ezd-subscription-btn[data-id='+id+']').removeClass('subscribed');
                  $('.ezd-subscription-btn[data-id='+id+']').addClass('logged-user');
                  $('.ezd-subscription-btn[data-id='+id+']').removeAttr('data-token', token);
            
                  $('.ezd-subscription-popup').addClass('hidden');
                  ezd_logged_user_subscription();

                },
                error: function () {
                }
            });
      }));
      
    });

  
  
  // Subscription confirmation    
    var subscription_confirmation = eazydocs_subscription.subscription_confirmation;      
    if ( subscription_confirmation ) {
        
      var unsubscriptions_btn = eazydocs_subscription.unsubscriptions_btn;    
      var token     = $(subscription_confirmation).attr('data-token');
      var token_id  = $(subscription_confirmation).attr('data-id');
      token_id      = parseInt(token_id, 10);
      $('.ezd-subscription-preloader').addClass('visible');

        $.ajax({
            type: 'POST',
            url: eazydocs_ajax_search.ajax_url,
            data: {
                action: 'ezd_confirm_subscription',
                token: token,
                token_id: token_id
            },
            beforeSend: function () {
              $('body').append('<div class="ezd-subscription-popup top-right"><div class="ezd-subscription-popup-inner"><h1>Opt-in confirmed</h1><p>You will now receive updates! </p><span class="ezd-subscription-preloader"><span class="ezd-subscription-loader-wrap"></span></span></div></div>');

              $('.ezd-subscription-btn.subscribe[data-id='+token_id+']').text('Subscribing...');
            },
            success: function (response) {
              
                $('.ezd-subscription-btn[data-id='+token_id+']').text(unsubscriptions_btn);
                $('.ezd-subscription-btn[data-id='+token_id+']').removeClass('subscribe');
                $('.ezd-subscription-btn[data-id='+token_id+']').addClass('subscribed');
                $('.ezd-subscription-btn[data-id='+token_id+']').attr('data-token', token);                  
                $('.ezd-subscription-popup').addClass('hidden');    

                history.pushState({}, '', window.location.href.split('?')[0]);  
               
            },
            error: function (errorThrown) {
                console.error('AJAX error: ' + errorThrown);
            },
        });
      
      }

  // Unsubscribe confirmation
  $(document).ready(function(){
      function ezdGetParameterByName(name, url) {
          if (!url) url = window.location.href;
          name = name.replace(/[\[\]]/g, "\\$&");
          var regex = new RegExp("[?&]" + name + "(=([^&#]*)|&|#|$)"),
              results = regex.exec(url);
          if (!results) return null;
          if (!results[2]) return '';
          return decodeURIComponent(results[2].replace(/\+/g, " "));
      }

      var unsubscription_confirmation = eazydocs_subscription.unsubscription_confirmation;    
      var subscriptions_btn           = eazydocs_subscription.subscriptions_btn;    
      if ( unsubscription_confirmation === '1' ) {
          
          $('.ezd-subscription-preloader').addClass('visible');

          var unsubscribe_token   = ezdGetParameterByName('unsubscribe_token');
          var token_id            = ezdGetParameterByName('token_id');

          $.ajax({
              type: 'POST',
              url: eazydocs_ajax_search.ajax_url,
              data: {
                  action: 'ezd_confirm_unsubscription',
                  token: unsubscribe_token,
                  token_id: token_id
              },
              beforeSend: function () {
                  $('body').append('<div class="ezd-subscription-popup top-right"><div class="ezd-subscription-popup-inner auto-opt-out"><h1>Unsubscribing...</h1><p>You will no longer receive updates! </p><span class="ezd-subscription-preloader"><span class="ezd-subscription-loader-wrap"></span></span></div></div>');
                  $('.ezd-subscription-btn.subscribed').text('Unsubscribing...');                
              },
              success: function (response) {
                  
                  $('.ezd-subscription-btn').text(subscriptions_btn);
                  $('.ezd-subscription-btn').removeClass('subscribed');
                  $('.ezd-subscription-btn').addClass('subscribe');
                  $('.ezd-subscription-btn').removeAttr('data-token');
                  
                  $('.ezd-subscription-popup').addClass('hidden');
                  
                  history.pushState({}, '', window.location.href.split('?')[0]);
                  
              },
              error: function (errorThrown) {
                  console.error('AJAX error: ' + errorThrown);
              },
          });
        }
    });
      
    // For logged in user
    function ezd_logged_user_subscription(){
      $('.ezd-subscription-btn.logged-user').on('click', function() {

          var $this   = $(this);
          var postId  = $this.data('id');
          var token   = $(this).attr('data-token');
          var id      = $(this).attr('data-id');  
          
          if ( $this.hasClass('subscribed') ) {
              // Unsubscribe via AJAX
          
              var ezd_doc_post_title  = '';
      
              if ( eazydocs_subscription.unsubscriptions_post_title ) {
                  ezd_doc_post_title  = eazydocs_subscription.doc_post_title+'?';
              }
      
              $('body').append("<div class='ezd-subscription-popup top-center unsubscription'><div class='ezd-subscription-popup-inner'><h1>"+eazydocs_subscription.unsubscriptions_heading+"</h1><p>"+eazydocs_subscription.unsubscriptions_desc +' '+ezd_doc_post_title+"</p><span class='ezd-subscription-preloader hidden'><span class='ezd-subscription-loader-wrap'></span></span><span class='ezd-subscription-action'><a href='' class='ezd-subscription-confirm'>"+eazydocs_subscription.unsubscriptions_submit_btn+"</a><a href='' class='ezd-subscription-cancel'>"+eazydocs_subscription.unsubscriptions_cancel_btn+"</a></span></div></div>");
      
              $('.ezd-subscription-cancel').on('click', (function (e) {
                    e.preventDefault();
                    $('.ezd-subscription-popup').addClass('hidden');            
                  })
              );
      
              // if click .ezd-subscription-confirm
              $('.ezd-subscription-confirm').on('click', (function (e) {
                $.ajax({
                    url: eazydocs_ajax_search.ajax_url, // Ensure this is defined in wp_localize_script
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'ezd_unsubscription_create',
                        token: token,
                        token_id: id
                    },
                    beforeSend: function() {
                        $this.text('Unsubscribing...').prop('disabled', true);
                    },
                    success: function(response) {
                      $this.text('Subscribe').removeClass('subscribed');
                      
                    },
                    complete: function() {
                        $this.prop('disabled', false);
                    }
                });
              }
            ));
          } else {
              // Subscribe via AJAX
              $.ajax({
                  url: eazydocs_ajax_search.ajax_url,
                  type: 'POST',
                  dataType: 'json',
                  data: {
                      action: 'ezd_auto_confirm_subscription',
                      post_id: postId
                  },
                  beforeSend: function() {
                      $this.text('Subscribing...').prop('disabled', true);
                  },
                  success: function(response) {
                    $this.text('Unsubscribe').addClass('subscribed');
                    $this.attr('data-token', response.data.token);
                  },
                  complete: function() {
                      $this.prop('disabled', false);
                  }
              });
          }
      });
    }
    ezd_logged_user_subscription();

  });
})(jQuery);