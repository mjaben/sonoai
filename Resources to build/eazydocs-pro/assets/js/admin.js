(function ($) {
  $(document).ready(function () {
    // Onchange of select docs page
    $(".docs-page-wrap select").on("change", function () {
      let url = $("#get_docs_archive").attr("href");
      let value = url.substring(url.lastIndexOf("/") + 1);
      let x = "?p=";
      let result =
        x +
        this.value +
        "?autofocus[panel]=docs-page&autofocus[section]=docs-archive-page";
      url = url.replace(value, result);
      $("#get_docs_archive").attr("href", url);
    });

    // Doc Visibility
    function ezd_doc_visibility() {
      $("a.docs-visibility").click(function (e) { 
          e.preventDefault(); 
          let href = $(this).attr("href"); 

          // Read current saved state from the link (populated server-side).
          let initialStatus = $(this).data('ezdVisibility') || '';
          let initialPassword = $(this).data('ezdPassword') || '';
          let initialRolesRaw = $(this).data('ezdRoleVisibility') || '';
          let initialGuest = String($(this).data('ezdRoleGuest') || '0') === '1';
          
          // Get roles from localized object (if available)
          let rolesHtml = '';
          let hasRoleVisibility = typeof eazydocspro_local_object !== 'undefined' && 
                                  eazydocspro_local_object.wp_roles && 
                                  eazydocspro_local_object.is_premium === '1' &&
                                  eazydocspro_local_object.role_visibility_enable === '1';
          
          if (hasRoleVisibility) {
              rolesHtml = '<div class="ezd_role_visibility_wrap" style="display:none; margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 8px; border: 1px solid #e0e0e0;">' +
                  '<div class="ezd_role_visibility_header" style="margin-bottom: 12px;">' +
                  '<label style="font-weight: 600; color: #1e1e1e; display: flex; align-items: center; gap: 6px;">' +
                  '<span class="dashicons dashicons-groups" style="color: #2271b1;"></span>' +
                  'Role-Based Access</label>' +
                  '<p style="margin: 5px 0 0; font-size: 12px; color: #666;">Restrict this doc to specific user roles. Leave unchecked for all logged-in users.</p>' +
                  '</div>' +
                  '<div class="ezd_role_checkboxes" style="max-height: 150px; overflow-y: auto; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">';
              
              // Add role checkboxes
              let roles = eazydocspro_local_object.wp_roles;
              for (let roleSlug in roles) {
                  if (roles.hasOwnProperty(roleSlug)) {
                      rolesHtml += '<label style="display: block; margin-bottom: 6px; cursor: pointer;">' +
                          '<input type="checkbox" name="ezd_role_visibility[]" value="' + roleSlug + '" style="margin-right: 6px;">' +
                          roles[roleSlug] + '</label>';
                  }
              }
              
              rolesHtml += '</div>' +
                  '<div style="margin-top: 10px;">' +
                  '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                  '<input type="checkbox" id="ezd_role_visibility_guest" value="1">' +
                  '<span>Allow guests (not logged in)</span></label>' +
                  '</div>' +
                  '<div style="margin-top: 10px;">' +
                  '<label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">' +
                  '<input type="checkbox" id="ezd_apply_to_children_roles" value="1">' +
                  '<span>Apply roles to all child docs</span></label>' +
                  '</div>' +
                  '</div>';
          }
  
          Swal.fire({ 
              title: "Visibility Options", 
              html: 
                  '<div class="docs_visibility_wrapper">' + 
                  "<p>The selected visibility option will apply to the child docs as well.</p>" + 
                  '<div class="docs_visibility_field_wrap">' + 
                  '<label for="ezd_docs_sidebar">Select Doc Visibility</label><br>' + 
                  '<input type="radio" id="ezd_status_public" name="ezd_doc_status" value="publish">' + 
                  '<label for="ezd_status_public">Public</label>' + 
                  '<input type="radio" id="ezd_status_private" name="ezd_doc_status" value="private">' + 
                  '<label for="ezd_status_private">Private</label>' + 
                  '<input type="radio" id="ezd_status_protected" name="ezd_doc_status" value="protected">' + 
                  '<label for="ezd_status_protected">Password protected</label>' + 
                  '<input type="text" id="ezd_password_input" name="ezd_password_input" value="" placeholder="Insert Password">' + 
                  rolesHtml +
                  "</div>" + 
                  "</div>", 
              showCancelButton: true, 
              confirmButtonText: "Update",
              width: hasRoleVisibility ? '500px' : 'auto',
              didOpen: () => {
                let $popup = $(Swal.getPopup());

                // Hide dependent UI by default.
                $popup.find('#ezd_password_input').hide();
                $popup.find('.ezd_role_visibility_wrap').hide();

                // Radio change handler scoped to this popup.
                $popup.on('change', 'input[name="ezd_doc_status"]', function () {
                  let ezd_doc_status = $popup.find('input[name="ezd_doc_status"]:checked').val();

                  // Handle password field
                  if (ezd_doc_status === "protected") {
                    $popup.find('#ezd_password_input').show();
                  } else {
                    $popup.find('#ezd_password_input').hide();
                  }

                  // Handle role visibility section
                  if (ezd_doc_status === "private" && hasRoleVisibility) {
                    $popup.find('.ezd_role_visibility_wrap').slideDown(200);
                  } else {
                    $popup.find('.ezd_role_visibility_wrap').slideUp(200);
                  }
                });

                // Preselect saved visibility option.
                if (initialStatus) {
                  $popup.find('input[name="ezd_doc_status"][value="' + initialStatus + '"]')
                    .prop('checked', true)
                    .trigger('change');
                }

                // Pre-fill the password if protected.
                if (initialStatus === 'protected' && initialPassword) {
                  $popup.find('#ezd_password_input').val(initialPassword);
                }

                // Preselect saved role visibility.
                if (hasRoleVisibility) {
                  let initialRoles = [];
                  if (typeof initialRolesRaw === 'string' && initialRolesRaw.length) {
                    initialRoles = initialRolesRaw.split(',').map(function (r) {
                      return String(r || '').trim();
                    }).filter(Boolean);
                  }

                  initialRoles.forEach(function (roleSlug) {
                    $popup.find('input[name="ezd_role_visibility[]"][value="' + roleSlug + '"]').prop('checked', true);
                  });

                  if (initialGuest) {
                    $popup.find('#ezd_role_visibility_guest').prop('checked', true);
                  }
                }
              },
              preConfirm: () => {
                  // Ensure a visibility option is selected
                let $popup = $(Swal.getPopup());
                let ezd_doc_status = $popup.find('input[name="ezd_doc_status"]:checked').val();
                  if (!ezd_doc_status) {
                      Swal.showValidationMessage("Please select a visibility option.");
                      return false; // Prevent form submission
                  }
  
                  // Ensure password input is filled if "protected" is selected
                  if (ezd_doc_status === "protected") {
                  let password = $popup.find("#ezd_password_input").val();
                    // Allow empty password only when keeping an existing protected password.
                    // (Server will preserve it if empty is submitted.)
                    if (!password && initialStatus !== 'protected') {
                          Swal.showValidationMessage("Please enter a password.");
                          return false; // Prevent form submission
                      }
                  }
                  
                  // Collect selected roles if private
                  let selectedRoles = [];
                  let applyRolesToChildren = false;
                  let allowGuests = false;
                  
                  if (ezd_doc_status === "private" && hasRoleVisibility) {
                  $popup.find('input[name="ezd_role_visibility[]"]:checked').each(function() {
                          selectedRoles.push($(this).val());
                      });
                  allowGuests = $popup.find('#ezd_role_visibility_guest').is(':checked');
                  applyRolesToChildren = $popup.find('#ezd_apply_to_children_roles').is(':checked');
                  }
                  
                  return { 
                      ezd_doc_status, 
                  password: $popup.find("#ezd_password_input").val(),
                      roles: selectedRoles,
                      allowGuests: allowGuests,
                      applyRolesToChildren: applyRolesToChildren
                  };
              }
          }).then((result) => { 
              if (result.isConfirmed) { 
                  let ezd_doc_status = result.value.ezd_doc_status;
                  let ezd_password_input = result.value.password;
                  let ezd_password_encoded = ezd_password_input.replaceAll("#", ";hash;");
                  
                  let url = href + "&doc_visibility_type=" + ezd_doc_status + "&doc_password_input=" + ezd_password_encoded;
                  
                  // Add role visibility params if private
                  if (ezd_doc_status === "private" && hasRoleVisibility) {
                      if (result.value.roles.length > 0) {
                          url += "&role_visibility=" + encodeURIComponent(result.value.roles.join(','));
                      }
                      if (result.value.allowGuests) {
                          url += "&role_visibility_guest=1";
                      }
                      if (result.value.applyRolesToChildren) {
                          url += "&apply_roles_to_children=1";
                      }
                  }
  
                  document.location.href = url; 
              } 
          }); 
      }); 
    } 
    ezd_doc_visibility();
  

    // Doc Visibility
    function ezd_doc_export() {
      $(document).on("click", "a.ezdoc-export", function (e) {
        e.preventDefault();
        let href = $(this).attr("href");
        Swal.fire({
          title: "Do you want to export this Doc?",
          html: "We're here to help you to do this service",
          showCancelButton: true,
          confirmButtonText: "Update",
        }).then((result) => {
          /* Read more about isConfirmed, isDenied below */
          if (result.isConfirmed) {
            document.location.href = href;
          }
        });
      });
    }
    ezd_doc_export();

    // Docs Sidebar
    function ezd_docs_sidebar() {
      $(document).on("click", ".docs-sidebar", function (e) {
        e.preventDefault();
        let href = $(this).attr("href");

        // function created to get parameter from edit url
        $.urlParams = function (name) {
          var doc_results = new RegExp("[?&]" + name + "=([^&#]*)").exec(href);
          if (doc_results == null) {
            return "";
          }
          return decodeURI(doc_results[1]) || "";
        };

        let left_type = $.urlParams("left_type");
        let right_type = $.urlParams("right_type");

        let left_content = "";
        if (left_type != "widget_data") {
          left_content = $.urlParams("left_content");
        }

        let right_content = "";
        if (right_type != "widget_data_right") {
          right_content = $.urlParams("right_content");
        }

        Swal.fire({
          title: "Doc Sidebar",
          html:
            '<div class="create_onepage_doc_area">' +
              '<div class="ezd_content_btn_wrap">' +

              '<div class="left_btn_link ezd_left_active">Left Sidebar</div>' +
              '<div class="right_btn_link">Right Sidebar</div>' +
              "</div>" +
              '<div class="ezd_left_content">' +
              '<div class="ezd_docs_content_type_wrap">' +

              '<label for="ezd_docs_content_type">Content Type:</label>' +
              '<input type="radio" id="widget_data" name="ezd_docs_content_type" value="widget_data">' +
              '<label for="widget_data">Reusable Blocks</label>' +
              '<input type="radio" checked id="string_data" name="ezd_docs_content_type" value="string_data">' +
              '<label for="string_data">Normal Content</label>' +
              "</div>" +
              '<div class="ezd_shortcode_content_wrap">' +
              '<label for="ezd-shortcode">Content (Optional) </label><br>' +
              '<textarea name="ezd-shortcode-content" id="ezd-shortcode-content" rows="5" class="widefat">' +
              left_content +
              "</textarea>" +
              '<span class="ezd-text-support">*The field will support text and html formats.</span>' +
              "</div>" +
              '<div class="ezd_widget_content_wrap">' +
              eazydocs_local_object.get_reusable_block +
              eazydocs_local_object.manage_reusable_blocks +
              "</div>" +
              "</div>" +

              '<div class="ezd_right_content">' +
              '<div class="ezd_docs_content_type_wrap">' +
              '<label for="ezd_docs_content_type">Content Type:</label>' +

              '<input type="radio" id="widget_data_right" name="ezd_docs_content_type_right" value="widget_data_right">' +
              '<label for="widget_data_right">Reusable Blocks</label>' +

              '<input type="radio" checked id="string_data_right" name="ezd_docs_content_type_right" value="string_data_right">' +
              '<label for="string_data_right">Normal Content</label>' +

              '<input type="radio" id="shortcode_right" name="ezd_docs_content_type_right" value="shortcode_right" checked>' +
              '<label for="shortcode_right">Doc Sidebar</label>' +
              '<div class="ezd-doc-sidebar-intro">To show the doc sidebar data, you have to go to <b>appearance</b> then <b>widgets</b> and just add your content inside <b>Doc Right Sidebar</b> location. If you cant find the location in the Widgets area, go to <b>EazyDocs</b> -> <b>Settings</b>. Then go to <b>Doc Single</b> -> <b>Right Sidebar</b> and then enable the option called <b>"Widgets Area"</b>' +
              "</div>" +
              "</div>" +

              '<div class="ezd_shortcode_content_wrap_right">' +
              '<label for="ezd-shortcode">Content (Optional) </label><br>' +
              '<textarea name="ezd-shortcode-content-right" id="ezd-shortcode-content-right" rows="5" class="widefat">' +
              right_content +
              "</textarea>" +
              '<span class="ezd-text-support">*The field will support text and html formats.</span>' +
              "</div>" +
              '<div class="ezd_widget_content_wrap_right">' +
              eazydocs_local_object.get_reusable_blocks_right +
              eazydocs_local_object.manage_reusable_blocks +
              "</div>" +
            "</div>",
          confirmButtonText: "Update",
          showCancelButton: true,
        }).then((result) => {
          if (result.isConfirmed) {
            let left_content = document.getElementById(
              "ezd-shortcode-content"
            ).value;
            let right_content = document.getElementById(
              "ezd-shortcode-content-right"
            ).value;

            let get_left_content = left_content.replace(/<!--(.*?)-->/gm, "");
            let style_attr_update1 = get_left_content.replaceAll(
              "style=",
              "style@"
            );
            let style_attr_update2 = style_attr_update1.replaceAll(
              "#",
              ";hash;"
            );
            let style_attr_update = style_attr_update2.replaceAll(
              "style&equals;",
              "style@"
            );

            let get_right_content = right_content.replace(/<!--(.*?)-->/gm, "");
            let right_style_attr_update1 = get_right_content.replaceAll(
              "style=",
              "style@"
            );
            let right_style_attr_update2 = right_style_attr_update1.replaceAll(
              "#",
              ";hash;"
            );
            let right_style_attr_update = right_style_attr_update2.replaceAll(
              "style&equals;",
              "style@"
            );

            encoded = encodeURIComponent(JSON.stringify(style_attr_update));
            encoded_right = encodeURIComponent(
              JSON.stringify(right_style_attr_update)
            );

            window.location.href =
              href +
              "&content_type=" +
              document.querySelector(
                "input[name=ezd_docs_content_type]:checked"
              ).value +
              "&left_side_sidebar=" +
              document.getElementById("left_side_sidebar").value +
              "&shortcode_content=" +
              encoded +
              "&shortcode_right=" +
              document.querySelector(
                "input[name=ezd_docs_content_type_right]:checked"
              ).value +
              "&shortcode_content_right=" +
              encoded_right +
              "&right_side_sidebar=" +
              document.getElementById("right_side_sidebar").value;
          }
        });

        // check widget save left sidebar
        if (left_type == "shortcode") {
          $(".ezd_doc_left_shortcode").prop("checked", true);
        } else if (left_type == "widget_data") {
          $(".ezd_widget_content_wrap").css("display", "block");
          $(".ezd_shortcode_content_wrap").css("display", "none");
          $("#widget_data").prop("checked", true);
          let get_left_widget = $.urlParams("left_content");
          $('#left_side_sidebar option[value="' + get_left_widget + '"]')
            .prop("selected", true)
            .trigger("change");
        }

        $(".ezd_content_btn_wrap .left_btn_link").addClass("ezd_left_active");
        $(".ezd_left_content").addClass("ezd_left_content_active");

        $(".ezd_content_btn_wrap .left_btn_link").click(function () {
          $(this).addClass("ezd_left_active");
          $(".ezd_left_content").addClass("ezd_left_content_active");
          $(".ezd_right_content").removeClass("ezd_left_content_active");
          $(".ezd_content_btn_wrap .right_btn_link").removeClass(
            "ezd_right_active"
          );
        });
        $(".ezd_content_btn_wrap .right_btn_link").click(function () {
          $(this).addClass("ezd_right_active");
          $(".ezd_left_content").removeClass("ezd_left_content_active");
          $(".ezd_right_content").addClass("ezd_left_content_active");
          $(".ezd_content_btn_wrap .left_btn_link").removeClass(
            "ezd_left_active"
          );
        });

        $("input[type=radio]#widget_data").click(function () {
          if ($(this).prop("checked")) {
            $(".ezd_shortcode_content_wrap").hide();
            $(".ezd_widget_content_wrap").show();
          }
        });

        $("input[type=radio]#string_data").click(function () {
          if ($(this).prop("checked")) {
            $(".ezd_shortcode_content_wrap").show();
            $(".ezd_widget_content_wrap").hide();
          }
        });

        // RIGHT TAB
        $(".ezd_widget_content_wrap_right,.ezd-doc-sidebar-intro").hide();

        $("input[type=radio]#string_data_right").click(function () {
          if ($(this).prop("checked")) {
            $(".ezd_widget_content_wrap_right").hide();
            $(".ezd_shortcode_content_wrap_right").show();
            $(".ezd-doc-sidebar-intro").hide();
          }
        });

        function handleDocSidebarVisibility() {
          if ($("input[type=radio]#shortcode_right").prop("checked")) {
            $(".ezd_widget_content_wrap_right").hide();
            $(".ezd_shortcode_content_wrap_right").hide();
            $(".ezd-doc-sidebar-intro").show();
          } else {
            $(".ezd_widget_content_wrap_right").show();
            $(".ezd_shortcode_content_wrap_right").show();
            $(".ezd-doc-sidebar-intro").hide();
          }
        }

        handleDocSidebarVisibility();

        // Call the function on click event
        $("input[type=radio]#shortcode_right").click(function () {
          handleDocSidebarVisibility();
        });


        $("input[type=radio]#widget_data_right").click(function () {
          if ($(this).prop("checked")) {
            $(".ezd_widget_content_wrap_right").show();
            $(
              ".ezd_shortcode_content_wrap_right,.ezd-doc-sidebar-intro"
            ).hide();
          }
        });

        // check widget save
        if (right_type == "shortcode_right") {
          $("#shortcode_right").prop("checked", true);
          $(".ezd_shortcode_content_wrap_right").css("display", "none");
          $(".ezd-doc-sidebar-intro").css("display", "block");
        } else if (right_type == "widget_data_right") {
          $(".ezd_widget_content_wrap_right").css("display", "block");
          $(".ezd_shortcode_content_wrap_right,.ezd-doc-sidebar-intro").css(
            "display",
            "none"
          );
          $("#widget_data_right").prop("checked", true);
          let get_right_widget = $.urlParams("right_content");
          $('#right_side_sidebar option[value="' + get_right_widget + '"]')
            .prop("selected", true)
            .trigger("change");
        }
      });
    }
    ezd_docs_sidebar();


    /**
     * Analytics reset callback
     */
    function ezd_analytics_reset_callback( callback = '' ){
        Swal.fire({
          title: 'Are you sure?',
          text: 'If you want to reset all the views then do it!',
          showCancelButton: true,
          icon: 'error',
          showCancelButton: true,
          confirmButtonText: 'Reset',
        }).then((result) => {
          /* Read more about isConfirmed, isDenied below */
          if (result.isConfirmed) {
              $.ajax({
                  url: eazydocspro_local_object.ajaxurl, // Adjust for frontend as needed
                  type: 'POST',
                  data: {
                      action: callback,
                      nonce: eazydocspro_local_object.nonce,
                  },
                  success: function(response) {
                    location.reload();
                  },
                  error: function(xhr, status, error) {
                      console.error('AJAX Error:', status, error);
                  }
              });
          }
        });
    }


    // Overview Reset
    function ezd_overview_reset() {
        $(document).on('click', '.ezd_overview_reset', function () {
            ezd_analytics_reset_callback('ezd_overview_reset');
        })
    }
    ezd_overview_reset();

    // Reset views
    function ezd_views_reset(){
        $(document).on('click', '.ezd_views_reset', function () {
              ezd_analytics_reset_callback('ezd_reset_views');
        });
    }
    ezd_views_reset();

    // Feedback reset
    function ezd_feedback_delete() {
        $(document).on('click', '.ezd_feedback_reset', function () {
            ezd_analytics_reset_callback('ezd_reset_feedback');
        })
    }
    ezd_feedback_delete();

    // Search reset
    function ezd_search_delete() {
        $(document).on('click', '.ezd_search_reset', function () {
            ezd_analytics_reset_callback('ezd_search_table_reset');
        })
    }
    ezd_search_delete();

    // Analytics sample report test email
    $('.ezd-analytics-sample-report').on('click', function(e){
        e.preventDefault();

        const $button = $(this);
        const recipientEmail = $('#reporting_email').val() || 'administrator email';

        // Show SweetAlert popup with better messaging
        Swal.fire({
            title: '📧 Sending Test Report',
            html: `
                <p style="margin-bottom: 10px;">A sample analytics report will be sent to:</p>
                <p style="font-weight: 600; color: #3b82f6; margin-bottom: 15px;">${recipientEmail}</p>
                <p style="font-size: 13px; color: #64748b;">Please wait while we prepare and send the report...</p>
            `,
            icon: 'info',
            allowOutsideClick: false,
            showConfirmButton: false,
            didOpen: () => {
                Swal.showLoading();

                // AJAX request to send test report
                $.ajax({
                    url: eazydocspro_local_object.ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'ezd_send_test_report'
                    },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                title: '✅ Test Email Sent!',
                                html: `
                                    <p style="margin-bottom: 10px;">Your sample report has been sent successfully.</p>
                                    <p style="font-size: 13px; color: #64748b;">
                                        Please check your inbox (and spam folder) for the test report from EazyDocs.
                                    </p>
                                `,
                                icon: 'success',
                                confirmButtonColor: '#3b82f6',
                                confirmButtonText: 'Got it!'
                            });
                        } else {
                            Swal.fire({
                                title: '❌ Email Failed',
                                html: `
                                    <p style="margin-bottom: 10px;">The test email could not be sent.</p>
                                    <p style="font-size: 13px; color: #64748b;">
                                        Please verify your server email configuration and try again.
                                    </p>
                                `,
                                icon: 'error',
                                confirmButtonColor: '#ef4444',
                                confirmButtonText: 'Close'
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            title: '❌ Connection Error',
                            html: `
                                <p style="margin-bottom: 10px;">Could not connect to the server.</p>
                                <p style="font-size: 13px; color: #64748b;">
                                    Please check your internet connection and try again.
                                </p>
                            `,
                            icon: 'error',
                            confirmButtonColor: '#ef4444',
                            confirmButtonText: 'Close'
                        });
                    }
                });
            }
        });
    });

        // ========================================
        // Notification Popover Module
        // ========================================
        const EZD_Notifications = {
            state: {
                isLoading: false,
                hasMore: true,
                currentPage: 1,
                currentFilter: 'all'
            },

            init: function() {
                this.bindEvents();
                this.initInfiniteScroll();
            },

            bindEvents: function() {
                const self = this;

                // Toggle notification popover
                $(document).on('click.ezdNotificationToggle', '.ezd-notification-redesigned .header-notify-icon', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    const $panel = $(this).siblings('.ezd-notification-panel');
                    $panel.toggleClass('is-active');
                    
                    // Close when clicking outside
                    if ($panel.hasClass('is-active')) {
                        $(document).on('click.ezdNotificationClose', function(evt) {
                            if (!$(evt.target).closest('.ezd-notification-redesigned').length) {
                                $panel.removeClass('is-active');
                                $(document).off('click.ezdNotificationClose');
                            }
                        });
                    } else {
                        $(document).off('click.ezdNotificationClose');
                    }
                });

                // Filter tabs
                $(document).on('click.ezdNotificationFilter', '.ezd-filter-tab', function(e) {
                    e.preventDefault();
                    const $this = $(this);
                    const filter = $this.data('filter');
                    
                    if (filter === self.state.currentFilter) {
                        return;
                    }

                    // Update active state
                    $('.ezd-filter-tab').removeClass('is-active');
                    $this.addClass('is-active');

                    // Reset and reload
                    self.state.currentFilter = filter;
                    self.state.currentPage = 1;
                    self.state.hasMore = true;
                    
                    const $list = $('.ezd-notification-list');
                    const $container = $('.ezd-notification-list-container');
                    
                    // Remove filter empty state if exists
                    $container.find('.ezd-filter-empty-state').remove();
                    
                    // Show and clear the list
                    $list.show().data('filter', filter).data('page', 1);
                    $list.empty();
                    
                    self.loadMoreNotifications();
                });

                // Mark all as read button
                $(document).on('click.ezdNotificationMarkRead', '.ezd-notification-mark-read', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    // Future enhancement: Implement mark all as read functionality
                    $(this).addClass('clicked');
                    setTimeout(() => {
                        $(this).removeClass('clicked');
                    }, 300);
                });
            },

            initInfiniteScroll: function() {
                const self = this;
                const $container = $('.ezd-notification-list-container');

                if (!$container.length) {
                    return;
                }

                $container.on('scroll.ezdNotificationScroll', function() {
                    if (self.state.isLoading || !self.state.hasMore) {
                        return;
                    }

                    const scrollTop = $(this).scrollTop();
                    const scrollHeight = this.scrollHeight;
                    const containerHeight = $(this).height();

                    // Load more when near the bottom (100px threshold)
                    if (scrollTop + containerHeight >= scrollHeight - 100) {
                        self.loadMoreNotifications();
                    }
                });
            },

            loadMoreNotifications: function() {
                const self = this;
                const $list = $('.ezd-notification-list');
                const $loader = $('.ezd-notification-loader');
                const $endMessage = $('.ezd-notification-end');
                const $container = $('.ezd-notification-list-container');

                if (self.state.isLoading || !self.state.hasMore) {
                    return;
                }

                self.state.isLoading = true;
                $loader.show();
                $endMessage.hide();

                // Remove any existing filter empty state
                $container.find('.ezd-filter-empty-state').remove();

                const nonce = $list.data('nonce');
                const perPage = $list.data('per-page') || 10;

                $.ajax({
                    url: eazydocspro_local_object.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'ezd_load_more_notifications',
                        nonce: nonce,
                        page: self.state.currentPage,
                        per_page: perPage,
                        filter: self.state.currentFilter
                    },
                    success: function(response) {
                        $loader.hide();
                        self.state.isLoading = false;

                        if (response.success && response.data.html && response.data.html.trim()) {
                            // Show the list if it was hidden
                            $list.show();
                            $list.append(response.data.html);
                            self.state.currentPage++;
                            $list.data('page', self.state.currentPage);

                            if (!response.data.has_more) {
                                self.state.hasMore = false;
                                $endMessage.show();
                            }
                        } else {
                            self.state.hasMore = false;
                            
                            // If this is page 1 and no results, show filter-specific empty state
                            if (self.state.currentPage === 1) {
                                $list.hide();
                                $endMessage.hide();
                                
                                let emptyMessage = '';
                                let emptyIcon = '';
                                
                                switch(self.state.currentFilter) {
                                    case 'comment':
                                        emptyIcon = 'dashicons-format-chat';
                                        emptyMessage = 'No comments found';
                                        break;
                                    case 'vote':
                                        emptyIcon = 'dashicons-thumbs-up';
                                        emptyMessage = 'No votes found';
                                        break;
                                    default:
                                        emptyIcon = 'dashicons-bell';
                                        emptyMessage = 'No notifications found';
                                }
                                
                                $container.append(
                                    '<div class="ezd-filter-empty-state ezd-notification-empty">' +
                                        '<div class="ezd-empty-icon"><span class="dashicons ' + emptyIcon + '" style="font-size: 32px; width: 32px; height: 32px; color: #ccc;"></span></div>' +
                                        '<h5 class="ezd-empty-title">' + emptyMessage + '</h5>' +
                                        '<p class="ezd-empty-text">Try selecting a different filter.</p>' +
                                    '</div>'
                                );
                            } else {
                                $endMessage.show();
                            }
                        }
                    },
                    error: function() {
                        $loader.hide();
                        self.state.isLoading = false;
                        console.error('EazyDocs: Error loading notifications');
                    }
                });
            }
        };

        // Initialize notification module
        EZD_Notifications.init();

    });

})(jQuery);
