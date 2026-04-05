(function ($) {
  $(document).ready(function () {
    $(".content-layout-category li > ul > li.page_item_has_children").click(
      function (e) {
        e.preventDefault();
        $(
          ".content-layout-category li > ul > li.page_item_has_children .children"
        ).toggle();
      }
    );
    $(".content-layout-category li > ul > li.page_item_has_children a").click(
      function (e) {
        e.preventDefault();
        let href = $(this).attr("href");
        document.location.href = href;
        $(
          ".content-layout-category li > ul > li.page_item_has_children .children"
        ).hide();
      }
    );

    $(
      ".ezdoc_contributed_user_avatar .ezdoc_contributed_users .arrow_carrot-down"
    ).click(function (e) {
      $(this).toggleClass("active");
      $(
        ".ezdoc_contributed_user_avatar .ezdoc_contributed_users .doc_users_dropdown"
      ).toggleClass("active");
      e.stopPropagation();
    });

    $(
      ".ezdoc_contributed_user_avatar .ezdoc_contributed_users .doc_users_dropdown"
    ).click(function (e) {
      e.stopPropagation();
    });

    $(document).click(function () {
      $(
        ".ezdoc_contributed_user_avatar .ezdoc_contributed_users .doc_users_dropdown"
      ).removeClass("active");
      $(
        ".ezdoc_contributed_user_avatar .ezdoc_contributed_users .arrow_carrot-down"
      ).removeClass("active");
    });

    // glossary doc js
    $speListWrapper = $(".spe-list-wrapper,.wp-block-eazydocs-pro-glossary-doc");
    if ($speListWrapper.length) {
      $speListWrapper.each(function () {
        var $elem = $(this);

        var $active_filter = $elem
          .find(".spe-list-filter .filter.active")
          .data("filter");
        if ($active_filter == "" || typeof $active_filter == "undefined") {
          $active_filter = "all";
        }

        var mixer = mixitup($elem, {
          load: {
            filter: $active_filter,
          },
          controls: {
            scope: "local",
          },
          callbacks: {
            onMixEnd: function (state) {
              $("#" + state.container.id)
                .find(".spe-list-block.spe-removed")
                .hide();
            },
          },
        });

        if ($(".spe-list-search-form").length) {
          var $searchInput = $(".spe-list-search-form input");

          $searchInput.on("input", function (e) {
            var $keyword = $(this).val().toLowerCase();

            $elem.find(".spe-list-block").each(function () {
              var $elem_list_block = $(this);
              var $block_visible_items = 0;

              $elem_list_block.find(".spe-list-item").each(function () {
                if ($(this).text().toLowerCase().includes($keyword)) {
                  $(this).show();
                  $block_visible_items++;
                } else {
                  $(this).hide();
                }
              });

              var $filter_base = $elem_list_block.data("filter-base");
              var $filter_source = $elem.find(
                '.spe-list-filter a[data-filter=".spe-filter-' +
                  $filter_base +
                  '"]'
              );
              var $active_block = $elem
                .find(".spe-list-filter a.mixitup-control-active")
                .data("filter");

              if ($block_visible_items > 0) {
                $elem_list_block.removeClass("spe-removed");

                if ($active_block != "all") {
                  if ($elem_list_block.is($elem.find($active_block))) {
                    $elem.find($active_block).show();
                  }
                } else {
                  $elem_list_block.show();
                }

                $filter_source.removeClass("filter_disable").addClass("filter");
              } else {
                $elem_list_block.addClass("spe-removed");

                if ($active_block != "all") {
                  if ($elem_list_block.is($elem.find($active_block))) {
                    $elem.find($active_block).hide();
                  }
                } else {
                  $elem_list_block.hide();
                }

                $filter_source.removeClass("filter").addClass("filter_disable");
              }
            });

            if ($keyword == "") {
              mixer.filter("all"); // Reset the filter to show all items
            }
          });

          $searchInput.val("");
        }
      });
    }
  });

  $(document).ready(function () {
    function tooltip() {
      if ($(".spe-list-item-title").length) {
        $(".spe-list-item-title").tooltipster({
          interactive: true,
          arrow: true,
          animation: "grow",
          delay: 200,
        });
      }
    }

    tooltip();
    
  });
})(jQuery);