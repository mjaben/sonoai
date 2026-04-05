;(function($){
    $(document).ready(function (){

        // Jquery mark
        let mark = function() {
            // Read the keyword
            let keyword = $("input[name='filter']").val();

            // First, unmark everything
            $(".left-sidebar-results").unmark({
                done: function() {
                    // If input is empty → show all items
                    if(keyword === ""){
                        $(".left-sidebar-results li").show();
                        return;
                    }

                    // Mark matches
                    $(".left-sidebar-results").mark(keyword, {
                        separateWordSearch: false,
                        done: function() {
                            // Hide all list items
                            $(".left-sidebar-results li").hide();

                            // Show only matched items + their parents
                            $(".left-sidebar-results mark").each(function(){
                                let li = $(this).closest("li");
                                li.show();
                                li.parents("li").show(); // show parent folders
                            });
                        }
                    });
                }
            });
        };

        $("input[name='filter']").on("input", mark);

    });
})(jQuery);