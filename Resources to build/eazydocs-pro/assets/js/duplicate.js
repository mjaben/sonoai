(function($){
    'use sticky'
    $(document).ready(function(){

        // DUPLICATE DOC
        function duplicate_doc() {
            $('.docs-duplicate').on('click', function (e) {
                e.preventDefault();
                let href = $(this).attr('href')
                Swal.fire({
                    title: eazydocs_local_object.clone_prompt_title,
                    text: "A duplicate copy of this doc (including its child) will be created. And a unique ID number will be appended on every cloned doc. The cloned doc will be drafted by default.",
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonColor: '#3085d6',
                    cancelButtonColor: '#d33',
                    confirmButtonText: 'Yes'
                }).then((result) => {
                    if (result.value) {
                        document.location.href = href;
                    }
                })
            })
        }
        duplicate_doc()

    });
})(jQuery);