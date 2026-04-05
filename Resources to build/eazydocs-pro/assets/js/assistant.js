;(function ($) {
  'use strict'

  $(document).ready(function () {
    
    function eazydocs_assistant_kbase_search() {
      
      let hsearch = $('#wp-spotlight-chat-search')
      let noresult = ''
      hsearch.on('keyup', function () {
        let keyword = $('#wp-spotlight-chat-search').val()
        if (keyword == '') {
          $('#chatbox-search-results').html(
            '<div class="chatbox-posts" tab-data="post">\n' +
            '<div class="post-item keyword-alert">' +
            '<p>'+eazydocs_ajax_search.assistant_not_found_words+'</p>' +
            '</div>' +
            '</div>'
        )
        } else {
          $.ajax({
            url: eazydocs_assistant.ajax_url,
            method: 'post',
            data: {
              action: 'eazydocs_ajax_search_result',
              keyword: keyword,
            },
            beforeSend: function () {
              $('#chatbox-search-results').html(
                '<?xml version="1.0" encoding="utf-8"?>\n' +
                '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin: auto; background: none; display: block; shape-rendering: auto;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">\n' +
                '<circle cx="50" cy="50" r="18" stroke-width="2" stroke="#4c4cf1" stroke-dasharray="28.274333882308138 28.274333882308138" fill="none" stroke-linecap="round">\n' +
                '  <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>\n' +
                '</circle>\n' +
                '</svg>',
              )
            },
            success: function (response) {
              $('#chatbox-search-results').html(response);
              eazydocs_assistant_instant();
            },
            error: function () {
              console.log('Oops! Something wrong, try again!')
            },
          })
        }
      });
      
       // Handle form reset when the cross button is clicked
       hsearch.on('search', function () {
        if (hsearch.val() === '') {
          $('#chatbox-search-results').html(
            '<div class="chatbox-posts" tab-data="post">' +
            '<div class="post-item keyword-alert">' +
            '<p>'+eazydocs_ajax_search.assistant_not_found_words+'</p>' +
            '</div>' +
            '</div>'
          );
        }
      });
      
    }
    eazydocs_assistant_kbase_search();
    
    // chatbox tabs
    function chatbox_tabs () {
      const searchBar = $('#wp-spotlight-chat-search')
      const searchResult = $('#chatbox-search-results')
      const kbButton = $('[tab-link=kbase]')
      const contactButton = $('[tab-link=contact]')

      const kbData = $('[tab-data=post]')
      const contactData = $('[tab-data=contact]')

      contactData.hide()

      kbButton.click(function (e) {
        e.preventDefault()
        contactButton.removeClass('active')
        contactData.hide()

        kbButton.addClass('active')
        kbData.show()
        searchResult.show()
        searchBar.show();

        // Instant Search
        var prevKbaseContent = $('.kb-content-wrap').html();
        $('.ezd-kbase-back').on('click', function (e) {
          $('.kb-content-wrap').html(prevKbaseContent);
          $('.kb-content-wrap').removeClass('opened');
          $('.show-chatbox').removeClass('chatbox-kbase-opened');
          $('.chatbox-header').show();
          eazydocs_assistant_instant();
          
          chat_toggle();
          chatbox_tabs();
          eazydocs_assistant_kbase_search();
          eazydocs_assistant_tabs();

          $('.search-box').css('display', 'block');
          $('.chatbox-body .assistant-content[tab-content=kbase]').addClass('active').siblings().removeClass('active');          
        });
        // Instant Search

      })

      contactButton.click(function (e) {
        e.preventDefault()
        kbButton.removeClass('active')
        kbData.hide()
        searchResult.hide()
        contactButton.addClass('active')
        contactData.show()
        searchBar.hide()
      })
    }

    function chat_toggle () {
      const chat_toggle_btn = $('.chat-toggle a, .close-chat-sm')
      const helper_hide = $('.wp-spotlight-hide')
      const helper_chat = $('.wp-spotlight-chat')
      const chatbox = $('.chatbox-wrapper');
      const $assistant_wrapper = $('.eazydocs-assistant-wrapper');

      chat_toggle_btn.click(function (e) {
        e.preventDefault()
        helper_hide.toggle()
        helper_chat.toggle()
        chatbox.toggleClass('show-chatbox');
        $assistant_wrapper.toggleClass('chatbox-expanded');

        // Instant Search
        $('.ezd-kbase-extend').on('click', function (e) {
          $('.chatbox-wrapper').toggleClass('extend');          
        });
        // Instant Search

      })
    }

    chat_toggle()
    chatbox_tabs()

    $('.chatbox-tab .assistant-tab:first-child').addClass('active').siblings().removeClass('active');
    $('.assistant-content:first-child').addClass('active').siblings().removeClass('active'); 

    function eazydocs_assistant_tabs(){
    $('.chatbox-tab .assistant-tab').click(function (e) {
      e.preventDefault();

      // Tab Button
      var tabId = $(this).attr('tab-link');
      
      $('.chatbox-tab .assistant-tab').removeClass('active');
      $(this).addClass('active');

      // Tab Content
      $('.assistant-content-'+tabId).addClass('active').siblings().removeClass('active');
      $('.assistant-content[tab-content='+tabId+']').addClass('active').siblings().removeClass('active');

      if (tabId == 'kbase') {
        // addclass
        $('.search-box ').addClass('active');

        // Instant Search
        var prevKbaseContent = $('.kb-content-wrap').html();        
        $('.ezd-kbase-back').on('click', function (e) {
          $('.kb-content-wrap').html(prevKbaseContent);
          $('.kb-content-wrap').removeClass('opened');
          $('.show-chatbox').removeClass('chatbox-kbase-opened');
          $('.chatbox-header').show();

          eazydocs_assistant_instant();          
          chat_toggle();
          chatbox_tabs();
          eazydocs_assistant_kbase_search();
          eazydocs_assistant_tabs();          
        });

        eazydocs_assistant_instant();
        eazydocs_assistant_kbase_search();
        // Instant Search
        
      } else {
        $('.search-box ').removeClass('active');

        // Instant Search
        eazydocs_assistant_kbase_search();
        // Instant Search
      }
    });
    }
    eazydocs_assistant_tabs();
    
    var kbase_active = $('.assistant-content[tab-content="kbase"]').attr('class');
    if ( kbase_active == 'assistant-content active' ) {
      $('.search-box ').addClass('active');
    } else {
      $('.search-box ').removeClass('active');
    }
    
    // Instant Search
    function eazydocs_assistant_instant(){

      if ($('.chatbox-posts .post-item.instant-search-enabled').length == 0) {
        return false;
      }

      $('.chatbox-posts .post-item.instant-search-enabled').on('click', function (e) {
        e.preventDefault();
        var post_id = $(this).attr('data-id');
 
        var prevResults = $('#chatbox-search-results').html();
        var prevKbaseContent = $('.kb-content-wrap').html();

          $.ajax({
            url: eazydocs_assistant.ajax_url,
            method: 'post',
            data: {
              action: 'eazydocs_kbase_instant',
              post_id: post_id,
            },
            beforeSend: function () {
              $('.chatbox-header').hide();
              $('#chatbox-search-results').html(
                '<?xml version="1.0" encoding="utf-8"?>\n' +
                '<svg class="ezd-kbase-preloader" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="margin: auto; background: none; display: block; shape-rendering: auto;" width="200px" height="200px" viewBox="0 0 100 100" preserveAspectRatio="xMidYMid">\n' +
                '<circle cx="50" cy="50" r="18" stroke-width="2" stroke="#4c4cf1" stroke-dasharray="28.274333882308138 28.274333882308138" fill="none" stroke-linecap="round">\n' +
                '  <animateTransform attributeName="transform" type="rotate" repeatCount="indefinite" dur="1s" keyTimes="0;1" values="0 50 50;360 50 50"></animateTransform>\n' +
                '</circle>\n' +
                '</svg>',
              );
              $('.show-chatbox').addClass('chatbox-kbase-opened');
            },
            success: function (response) {
              $('.kb-content-wrap').html(response).addClass('opened');
              $('.show-chatbox').addClass('chatbox-kbase-opened');
              $('#chatbox-search-results').html(prevResults);

              $('.kbase-button-wrap').css('display', 'flex');
              
              $('.ezd-kbase-extend').on('click', function (e) {
                $('.chatbox-wrapper').toggleClass('extend');    
                eazydocs_assistant_docs_back();      
              });

              $('.ezd-kbase-extend').on('click', function (e) {
                  $('.chatbox-wrapper').toggleClass('extend');    
                  eazydocs_assistant_docs_back();      
              });
                


              // if scroll to bottom 50px then this .ezd-kbase-extend-heading value append to .ezd-kbase-extend-title
              var $contentWrap = $('.kb-content-wrap.opened');
              var $titles = $('.ezd-kbase-extend-title');
              
              $contentWrap.scroll(function() {
                  var scrollTop = $contentWrap.scrollTop();
                  var innerHeight = $contentWrap.innerHeight();
                  var scrollHeight = $contentWrap[0].scrollHeight;
              
                  if (scrollTop + innerHeight > scrollHeight - 10) {
                      $('.ezd-kbase-extend-heading').each(function(index) {
                          var heading = $(this).text();
                          $titles.eq(index).text(heading);
                      });
                  } else if (scrollTop === 0) {
                      $titles.text(''); // Clear the text when scrolled to the top
                  }
              });
              
              // Clear titles on .ezd-kbase-back click
              $('.ezd-kbase-back').on('click', function(e) {
                  $titles.text('');
              });
                
              eazydocs_assistant_docs_back();
            }
        });
        
        function eazydocs_assistant_docs_back(){

          $('.ezd-kbase-back').on('click', function (e) {
            
            if (prevKbaseContent == '') {
              return false;
            }

            $('.kb-content-wrap').html(prevKbaseContent);
            $('.kb-content-wrap').removeClass('opened');
            $('.show-chatbox').removeClass('chatbox-kbase-opened');
            $('.chatbox-header').show();
            eazydocs_assistant_instant();

            $('.kbase-button-wrap').hide();
            
            chat_toggle();
            chatbox_tabs();
            eazydocs_assistant_kbase_search();
            eazydocs_assistant_tabs();
            $('.chatbox-wrapper').removeClass('extend');
          });
        }
        eazydocs_assistant_docs_back();
      });
    }
    eazydocs_assistant_instant();
    // Instant Search

      $('.eazydocs-assistant-wrapper.iframe-wrapper .ezd-kbase-extend').on('click', function (e) {
          window.parent.postMessage('extend_assistant_triggered', '*');
      });
      $('.eazydocs-assistant-wrapper.iframe-wrapper .ezd-kbase-back').on('click', function (e) {
          window.parent.postMessage('reduce_assistant_triggered', '*');
      });
      
      // Contact form submission
      let sending = false;
      $('.chatbox-form').off('submit').on('submit', function(e){
        e.preventDefault();

        if (sending) return; // block double click
        sending        = true;       

        var form       = $(this);
        var nativeForm = form.get(0);

        $.ajax({
            url: eazydocs_assistant.ajax_url,
            type: "POST",
            data: {
                action: "eazydocs_send_message",
                name: form.find('[name="eazydocs_assistant_name"]').val(),
                email: form.find('[name="eazydocs_assistant_email"]').val(),
                subject: form.find('[name="eazydocs_assistant_subject"]').val(),
                comment: form.find('[name="eazydocs_assistant_comment"]').val(),
            },
            success: function(response){
                sending = false; // allow next submit
                console.log(response);
                if(response.success){
                    if (nativeForm && typeof nativeForm.reset === 'function') {
                      // add append message sent successfully
                      var messageSent = $('<div class="message-sent-successfully"><p>' + response.data + '</p></div>');
                      form.after(messageSent);
                      
                      setTimeout(function() {
                        messageSent.remove();
                      }, 3000);
                      
                        nativeForm.reset();
                    }
                } else {
                    alert(response.data);
                }
            }
        });
    });
    
  });
})(jQuery);