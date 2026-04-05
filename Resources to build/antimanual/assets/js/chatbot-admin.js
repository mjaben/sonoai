// File: my-excerpt-ai.js
// Make sure this file is enqueued properly in the Gutenberg editor (see step 2).

(function ($) {
    const { createElement, useState } = wp.element;
    const { createRoot } = wp.element;

    /**
     * A simple example component that displays a slider, current value,
     * and two buttons (Generate, Discard).
     */
    const MyExcerptAi = () => {
        const [sliderVal, setSliderVal] = useState(50);
        const [excerpt, setExcerpt] = useState("");
		const [generating, setGenerating] = useState(false);

        const handleGenerate = () => {
			setGenerating(true);

            const { select } = wp.data;
            const postId = select("core/editor").getCurrentPostId();
            jQuery.ajax({
                url: ajaxurl,
                type: "POST",
                data: {
                    action: "antimanual_get_excerpt",
                    nonce: antimanual_ajax.nonce,
                    post_id: postId,
                    excerpt_length: sliderVal,
					overwrite: true,
                },
                success: function (response) {
					if (response.success) {
						setExcerpt(response.data.excerpt);
                    } else {
						alert("Error: " + response.data.message);
                    }
                },
                error: function () {
                    // Use wp.i18n for internationalization
                    alert(
                        wp.i18n.__(
                            "Network error occurred while generating excerpt",
                            "antimanual"
                        )
                    );
                },
            }).always(() => {
				setGenerating(false);
			})
        };

        const handleAccept = () => {
            if (excerpt) {
                wp.data.dispatch("core/editor").editPost({ excerpt: excerpt });

                $(".editor-post-excerpt__dropdown button").trigger("click");

                setExcerpt("");
            }
        };

        const handleDiscard = () => {
            setExcerpt("");
        };

        // We’re using raw createElement calls for a quick example,
        // but you can also use JSX if you build with a bundler.
        return createElement(
            "div",
            { className: "my-excerpt-ai-panel" },
            excerpt ? [
					createElement(
						"p",
						{ key: "tempExcerpt", style: { marginTop: "1em" } },
						[
							createElement(
								"h4",
								null,
								wp.i18n.__("Generated with Antimanual", "antimanual"),
							),
							excerpt
						]
					),
					createElement("div", { key: "excerptApprovalActions" }, [
						createElement(
							"button",
							{
								key: "acceptBtn",
								className: "button button-primary",
								onClick: handleAccept,
								style: { marginRight: "1em" },
							},
							wp.i18n.__("Accept", "antimanual")
						),
						createElement(
							"button",
							{
								key: "discardBtn",
								className: "button button-secondary",
								onClick: handleDiscard,
							},
							wp.i18n.__("Discard", "antimanual")
						),
					]),
				] : [
					createElement(
						"label",
						{
							key: "label",
							style: {
								display: "block",
								marginBottom: "0.5em",
							},
						},
						wp.i18n.__("Desired Length (in words):", "antimanual")
					),
					createElement("input", {
						key: "slider",
						type: "range",
						min: "10",
						max: "300",
						value: sliderVal,
						onChange: (e) => setSliderVal(e.target.value),
						style: { marginRight: "1em" },
					}),
					createElement(
						"span",
						{ key: "val" },
						sliderVal + wp.i18n.__(" words", "antimanual")
					),
					createElement(
						"p",
						{ key: "desc", className: "description" },
						wp.i18n.__(
							"Adjust how many words to generate for the excerpt.",
							"antimanual"
						)
					),
					createElement(
						"div",
						{
							style: {
								display: "flex",
								alignItems: "center",
								gap: "1em",
							}
						},
						[
							createElement(
								"button",
								{
									key: "generateBtn",
									className: "button button-primary",
									onClick: handleGenerate,
									disabled: generating,
								},
								wp.i18n.__("Generate with Antimanual", "antimanual")
							),
							createElement(
								"span",
								{
									className: "spinner",
									style: {
										margin: 0,
										visibility: generating ? "visible" : "hidden"
									},
								},
							),
						]
					),
				]
        );
    };
    // ------------------------------------------
    // 2) Insert the React component if the excerpt panel is present
    //    and hasn't already been injected.
    // ------------------------------------------
    const insertAiIfNeeded = () => {
        // Change this selector if needed to match your WordPress version or configuration.
        const excerptPanel = document.querySelector(".editor-post-excerpt");
        if (!excerptPanel) return;

        // Avoid duplicate insertion
        if (!excerptPanel.querySelector(".my-excerpt-ai-panel-container")) {
            const container = document.createElement("div");
            container.classList.add("my-excerpt-ai-panel-container");
            container.style.marginTop = "1em";

            excerptPanel.appendChild(container);

            // Mount our React component
            const root = createRoot(container);
            root.render(createElement(MyExcerptAi));
        }
    };

    /**
     * 2. Wait for the Block Editor to be “ready,”
     *    then look for the excerpt panel DOM element.
     */
    wp.domReady(() => {
        const observer = new MutationObserver((mutations) => {
            for (const mutation of mutations) {
                if (mutation.type === "childList") {
                    const excerptPanel = document.querySelector(
                        ".editor-post-excerpt"
                    );
                    if (excerptPanel) {
                        insertAiIfNeeded();
                    }
                }
            }
        });

        observer.observe(document.body, { childList: true, subtree: true });
        window.addEventListener("beforeunload", () => observer.disconnect());
    });
})(jQuery);
