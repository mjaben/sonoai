/* File: my-ai-cats-tags.js */
(function () {
    const { registerPlugin } = wp.plugins;
    const { PluginDocumentSettingPanel } = wp.editPost;
    const { useSelect, useDispatch } = wp.data;
    const { createElement, useState } = wp.element;
    const { __ } = wp.i18n;
    const { PanelRow, Button, RangeControl, Spinner } = wp.components;

    // A custom panel to generate categories & tags from AI
    const MyAiCatsTagsPanel = () => {
        // 1. Access the post content, categories, and tags
        const { content, categories, tags } = useSelect((select) => {
            const getAttribute = select('core/editor').getEditedPostAttribute;
            return {
                content:    getAttribute('content'),
                categories: getAttribute('categories'),
                tags:       getAttribute('tags'),
            };
        }, []);

        // 2. We can dispatch actions to update the post
        const { editPost , savePost } = useDispatch('core/editor');

        // 3. Local state: desired number of categories, loading indicator
        const [desiredCats, setDesiredCats] = useState(2);
        const [isLoading, setIsLoading]     = useState(false);

        // 4. Generate button handler
        const handleGenerate = async () => {
            try {
                setIsLoading(true);
                
                // Example payload for an AI request
                const payload = {
                    content: content,       // post content
                    desiredCats: desiredCats,
                };

                // Make AJAX request to WordPress backend
                const response = await fetch(ajaxurl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'antimanual_get_tags',
                        number_of_tags: desiredCats,
                        post_id: wp.data.select('core/editor').getCurrentPostId(),
                        nonce: antimanual_ajax.nonce
                        // Add any other parameters you want to send
                    })
                });

                if (!response.ok) {
                    throw new Error(`Request failed: ${response.status}`);
                }
                
                const data = await response.json();
                
                if (!data.success) {
                    throw new Error(data.data.message || 'Unknown error occurred');
                }

                const newCatIds = data.data.categories || [];
                const newTagIds = data.data.tags || [];


                // Update the post with the AI-suggested categories/tags
                // Merge new categories with existing ones, removing duplicates
                const uniqueCategories = Array.from(new Set([...categories, ...newCatIds]));
                editPost({
                    categories: uniqueCategories,
                    tags: newTagIds,
                });
                savePost();
            } catch (err) {
                alert(__('Error generating categories/tags: ', 'antimanual') + err.message);
            } finally {
                setIsLoading(false);
            }
        };

        // 5. Return a createElement tree with a PluginDocumentSettingPanel
        return createElement(
            PluginDocumentSettingPanel,
            {
                name: 'my-ai-cats-tags-panel',
                title: __('AI Categories & Tags', 'antimanual'),
                className: 'my-ai-cats-tags-panel',
            },
            // Children of PluginDocumentSettingPanel can be multiple createElement calls:
            [
                // 1) A row for the RangeControl slider
                createElement(
                    PanelRow,
                    { key: 'row-slider' },
                    createElement(RangeControl, {
                        label: __('Desired # of Categories', 'antimanual'),
                        min: 1,
                        max: 10,
                        value: desiredCats,
                        onChange: setDesiredCats,
                    })
                ),

                // 2) A row for the Generate button & spinner
                createElement(
                    PanelRow,
                    { key: 'row-generate' },
                    createElement(
                        Button,
                        {
                            isPrimary: true,
                            onClick: handleGenerate,
                            disabled: isLoading,
                        },
                        isLoading
                            ? __('Generating...', 'antimanual')
                            : __('Generate via AI', 'antimanual')
                    ),
                    // If loading, also show a Spinner
                    isLoading && createElement(Spinner, { key: 'spinner' })
                ),
            ]
        );
    };

    // 6. Register the plugin to place our panel in the post sidebar
    registerPlugin('my-ai-cats-tags', {
        render: MyAiCatsTagsPanel,
        icon: 'admin-site', // optional dashicon
    });
})();