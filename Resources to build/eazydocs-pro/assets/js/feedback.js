;(function($){
    $(document).ready(function(){

        // Function to get URL parameter by name
        function ezdGetParameterByName(name) {
            name = name.replace(/[\[\]]/g, '\\$&'); // Escape special characters
            var regex = new RegExp('[?&]' + name + '(=([^&#]*)|&|#|$)');
            var results = regex.exec(window.location.href);
            if (!results) return null; // Parameter not found
            if (!results[2]) return ''; // Parameter is present but no value
            return decodeURIComponent(results[2].replace(/\+/g, ' ')); // Decode the value
        }
        
        // Feedback update
        function feedback_update() {
          $(document).on('click', 'a.ezd-feedback-update', function (e) {
              e.preventDefault();  

              let href          = $(this).attr('href');              
              let prompt_title  = ezdGetParameterByName('status') == 'archive' ? eazydocspro_local_object.feedback_prompt_open_title : eazydocspro_local_object.feedback_prompt_archive_title;
              let prompt_text   = ezdGetParameterByName('status') == 'archive' ? eazydocspro_local_object.feedback_prompt_open_desc : eazydocspro_local_object.feedback_prompt_archive_desc;
              let button_text   = ezdGetParameterByName('status') == 'archive' ? 'Mark as Open' : 'Mark as Archive';

              Swal.fire({
                  title: prompt_title,
                  text: prompt_text,
                  showCancelButton: true,
                  icon: 'warning',
                  confirmButtonText: button_text,
              }).then((result) => {
                  /* Read more about isConfirmed, isDenied below */
                  if (result.isConfirmed) {
                      document.location.href = href;
                  }
              })
          })
        }
        feedback_update();      
      
        // Feedback delete
        function feedback_delete() {
          $(document).on('click', 'a.ezd-feedback-delete', function (e) {
              e.preventDefault();
              let href = $(this).attr('href');
              Swal.fire({
                  title: eazydocspro_local_object.feedback_prompt_delete_title,
                  text: eazydocspro_local_object.feedback_prompt_delete_desc,
                  showCancelButton: true,
                  icon: 'error',
                  showCancelButton: true,
                  confirmButtonText: 'Delete',
                }).then((result) => {
                  /* Read more about isConfirmed, isDenied below */
                  if (result.isConfirmed) {
                      document.location.href = href;
                  }
                })
          })
      }
      feedback_delete();

    });
})(jQuery);