/**
 * Frontend JavaScript for Antimanual AI Search Block
 * Integrates with existing Antimanual AI Search API
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize all search blocks on the page
    const searchBlocks = document.querySelectorAll('.antimanual-search-block');

    searchBlocks.forEach(function(block) {
        initializeSearchBlock(block);
    });

    // No longer hiding on click outside - users will use the close button instead
});

function initializeSearchBlock(block) {
    const searchField = block.querySelector('.search-field');
    const searchButton = block.querySelector('.search-button');
    const resultsContainer = block.querySelector('.ai-search-results');

    if (!searchField || !searchButton || !resultsContainer) {
        return;
    }

    // Track if user manually closed results (to prevent re-opening after API response)
    block.dataset.resultsClosed = 'false';
    
    // Store abort controller for cancelling pending requests
    block.abortController = null;

    // Initially disable the search button
    searchButton.disabled = true;
    searchButton.classList.add('disabled');

    // Handle search field input changes
    searchField.addEventListener('input', function() {
        const hasValue = this.value.trim().length > 0;
        searchButton.disabled = !hasValue;

        if (hasValue) {
            searchButton.classList.remove('disabled');
        } else {
            searchButton.classList.add('disabled');
        }
    });

    // Handle search button click
    searchButton.addEventListener('click', function() {
        if (!this.disabled) {
            // Reset closed state when user initiates a new search
            block.dataset.resultsClosed = 'false';
            hideSuggestedQuestions(block);
            hidePopularKeywords(block);
            performSearch(searchField, resultsContainer, block);
        }
    });

    // Handle Enter key press in search field
    searchField.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && this.value.trim().length > 0) {
            e.preventDefault();
            // Reset closed state when user initiates a new search
            block.dataset.resultsClosed = 'false';
            hideSuggestedQuestions(block);
            hidePopularKeywords(block);
            performSearch(searchField, resultsContainer, block);
        }
    });

    // Handle popular keyword tag clicks
    const keywordTags = block.querySelectorAll('.popular-keyword-tag');
    keywordTags.forEach(function(tag) {
        tag.addEventListener('click', function() {
            const keyword = this.dataset.keyword;
            if (!keyword) return;

            // Fill the search field with the keyword
            searchField.value = keyword;
            searchButton.disabled = false;
            searchButton.classList.remove('disabled');

            // Reset closed state and trigger search
            block.dataset.resultsClosed = 'false';
            hideSuggestedQuestions(block);
            hidePopularKeywords(block);
            performSearch(searchField, resultsContainer, block);
        });
    });

    // Handle suggested question tag clicks
    const questionTags = block.querySelectorAll('.suggested-question-tag');
    questionTags.forEach(function(tag) {
        tag.addEventListener('click', function() {
            const question = this.dataset.question;
            if (!question) return;

            // Fill the search field with the question
            searchField.value = question;
            searchButton.disabled = false;
            searchButton.classList.remove('disabled');

            // Reset closed state and trigger search
            block.dataset.resultsClosed = 'false';
            hideSuggestedQuestions(block);
            hidePopularKeywords(block);
            performSearch(searchField, resultsContainer, block);
        });
    });

    // Auto-focus search field when it comes into view
    const observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (entry.isIntersecting) {
                searchField.focus();
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.5 });

    observer.observe(block);
}

/**
 * Hide the popular keywords row when a search is active.
 *
 * @param {HTMLElement} block The search block element.
 */
function hidePopularKeywords(block) {
    const keywords = block.querySelector('.popular-keywords');
    if (keywords) {
        keywords.style.display = 'none';
    }
}

/**
 * Show the popular keywords row (e.g. when results are closed).
 *
 * @param {HTMLElement} block The search block element.
 */
function showPopularKeywords(block) {
    const keywords = block.querySelector('.popular-keywords');
    if (keywords) {
        keywords.style.display = '';
    }
}

/**
 * Hide the suggested questions section when a search is active.
 *
 * @param {HTMLElement} block The search block element.
 */
function hideSuggestedQuestions(block) {
    const questions = block.querySelector('.suggested-questions');
    if (questions) {
        questions.style.display = 'none';
    }
}

/**
 * Show the suggested questions section (e.g. when results are closed).
 *
 * @param {HTMLElement} block The search block element.
 */
function showSuggestedQuestions(block) {
    const questions = block.querySelector('.suggested-questions');
    if (questions) {
        questions.style.display = '';
    }
}

/**
 * Close search results and cancel any pending request
 * @param {HTMLElement} block - The search block element
 */
function closeSearchResults(block) {
    const resultsContainer = block.querySelector('.ai-search-results');
    const searchField = block.querySelector('.search-field');
    const searchButton = block.querySelector('.search-button');
    
    if (!resultsContainer) return;
    
    // Mark as manually closed
    block.dataset.resultsClosed = 'true';
    
    // Abort any pending request
    if (block.abortController) {
        block.abortController.abort();
        block.abortController = null;
    }
    
    // Clear the results
    resultsContainer.innerHTML = '';
    
    // Clear the search field and reset button state
    if (searchField && searchButton) {
        searchField.value = '';
        searchButton.disabled = true;
        searchButton.classList.add('disabled');
    }

    // Re-show popular keywords and suggested questions
    showPopularKeywords(block);
    showSuggestedQuestions(block);
}

/**
 * Filter HTML content to only include safe/useful tags and extract clean text
 * @param {string} htmlContent - The HTML content to filter
 * @returns {string} - Clean text content
 */
function filterHtmlContent(htmlContent) {
    if (!htmlContent) return '';

    // Create a temporary div to parse HTML
    const tempDiv = document.createElement('div');
    tempDiv.innerHTML = htmlContent;

    // Define allowed tags
    const allowedTags = ['h2', 'h3', 'h4', 'h5', 'h6', 'p', 'pre', 'strong', 'em', 'a', 'img', 'ul', 'ol', 'li'];

    // Function to recursively clean and extract text
    function extractCleanText(element) {
        let result = '';

        for (let node of element.childNodes) {
            if (node.nodeType === Node.TEXT_NODE) {
                // Add text content
                const text = node.textContent.trim();
                if (text) {
                    result += text + ' ';
                }
            } else if (node.nodeType === Node.ELEMENT_NODE) {
                const tagName = node.tagName.toLowerCase();

                if (allowedTags.includes(tagName)) {
                    // Handle different tags appropriately
                    switch (tagName) {
                        case 'h2':
                        case 'h3':
                        case 'h4':
                        case 'h5':
                        case 'h6':
                            result += '\n\n' + node.textContent.trim() + '\n';
                            break;
                        case 'p':
                            result += '\n' + node.textContent.trim() + '\n';
                            break;
                        case 'pre':
                            result += '\n```\n' + node.textContent.trim() + '\n```\n';
                            break;
                        case 'strong':
                            result += '**' + node.textContent.trim() + '** ';
                            break;
                        case 'em':
                            result += '*' + node.textContent.trim() + '* ';
                            break;
                        case 'a':
                            const href = node.getAttribute('href');
                            const linkText = node.textContent.trim();
                            result += href ? `[${linkText}](${href}) ` : linkText + ' ';
                            break;
                        case 'img':
                            const alt = node.getAttribute('alt');
                            const src = node.getAttribute('src');
                            result += alt ? `[Image: ${alt}] ` : '[Image] ';
                            break;
                        case 'ul':
                        case 'ol':
                            result += '\n' + extractListContent(node, tagName === 'ol') + '\n';
                            break;
                        case 'li':
                            // This will be handled by the list processing
                            result += extractCleanText(node);
                            break;
                        default:
                            result += extractCleanText(node);
                    }
                } else {
                    // For disallowed tags, still extract text content but without formatting
                    result += extractCleanText(node);
                }
            }
        }

        return result;
    }

    // Function to extract list content with proper formatting
    function extractListContent(listElement, isOrdered) {
        let listResult = '';
        const items = listElement.querySelectorAll('li');

        items.forEach(function(item, index) {
            const prefix = isOrdered ? `${index + 1}. ` : '• ';
            const itemText = item.textContent.trim();
            if (itemText) {
                listResult += prefix + itemText + '\n';
            }
        });

        return listResult;
    }

    // Extract clean text
    let cleanText = extractCleanText(tempDiv);

    // Clean up extra whitespace and newlines
    cleanText = cleanText
        .replace(/\n\s*\n\s*\n/g, '\n\n') // Remove excessive newlines
        .replace(/[ \t]+/g, ' ') // Replace multiple spaces with single space
        .trim();

    return cleanText;
}

function performSearch(searchField, resultsContainer, block) {
    const searchQuery = searchField.value.trim();

    if (!searchQuery) {
        searchField.focus();
        return;
    }

    // Show loading animation
    showLoadingAnimation(resultsContainer, block);

    // Use the same API endpoint as your existing search
    const apiUrl = window.antimanual_vars?.rest_url || window.antimanual_chatbot_vars?.rest_url;

    if (!apiUrl) {
        showError(resultsContainer, 'API endpoint not configured', block);
        return;
    }

    // Create abort controller for this request
    if (block.abortController) {
        block.abortController.abort();
    }
    block.abortController = new AbortController();

    // Make the API request using fetch (modern approach)
    const headers = {
        'Content-Type': 'application/x-www-form-urlencoded',
    };

    // Add nonce for security
    const nonce = window.antimanual_vars?.nonce || window.antimanual_chatbot_vars?.nonce;
    if (nonce) {
        headers['X-WP-Nonce'] = nonce;
    }

    // Get answer detail level from block data attribute.
    var answerDetail = block.dataset.answerDetail || 'balanced';
    if (['brief', 'balanced', 'detailed'].indexOf(answerDetail) === -1) {
        answerDetail = 'balanced';
    }

    fetch(apiUrl + '/search', {
        method: 'POST',
        headers: headers,
        body: 'message=' + encodeURIComponent(searchQuery) + '&answer_detail=' + encodeURIComponent(answerDetail),
        signal: block.abortController.signal
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        // Check if user closed the results while waiting
        if (block.dataset.resultsClosed === 'true') {
            return; // Don't display results if user closed the box
        }
        
        if (data && data.success && data.data) {
            displaySearchResults(resultsContainer, data.data, searchQuery, block);
        } else {
            showError(resultsContainer, data.message || 'Something went wrong', block);
        }
    })
    .catch(function(error) {
        // Ignore abort errors
        if (error.name === 'AbortError') {
            return;
        }
        console.error('Search Error:', error);
        showError(resultsContainer, 'Network error occurred', block);
    })
    .finally(function() {
        block.abortController = null;
    });
}

function showLoadingAnimation(container, block) {
    container.innerHTML = `
        <div class="ai-answer">
            <button class="ai-answer-close-btn" type="button" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            <div class="ai-answer-loading">
                <div class="antimanual-loading-dots">
                    <span class="antimanual-dot"></span>
                    <span class="antimanual-dot"></span>
                    <span class="antimanual-dot"></span>
                </div>
                <p>Generating AI answer...</p>
            </div>
        </div>
    `;
    
    // Add close button event listener
    const closeBtn = container.querySelector('.ai-answer-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            closeSearchResults(block);
        });
    }
}

function displaySearchResults(container, data, query, block) {
    // Extract clean text for copying
    const cleanTextForCopy = filterHtmlContent(data.answer || data.reply || '');

    // Store the answer HTML for voting
    const answerHtml = data.answer || data.reply || '';
    
    // Store the query log ID for voting (passed from backend)
    const queryLogId = data.query_log_id || null;

    const uniqueLinks = [...new Set(data?.references?.map((ref) => ref.link))];

    let referencesHtml = `
        <div class="sources-container">
            <div class="sources-head-wrapper">
                <button class="sources" type="button">
                    ${
                        uniqueLinks.length > 0 ? `
                            <svg fill="currentColor" width="16px" class="antimanual-icon ea-arrow-down" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512">
                                <path d="M233.4 406.6c12.5 12.5 32.8 12.5 45.3 0l192-192c12.5-12.5 12.5-32.8 0-45.3s-32.8-12.5-45.3 0L256 338.7 86.6 169.4c-12.5-12.5-32.8-12.5-45.3 0s-12.5 32.8 0 45.3l192 192z"></path>
                            </svg>
                            <span>Sources</span>
                            <span class="sources-count">${uniqueLinks.length}</span>
                        ` : ''
                    }
                </button>
                <div class="answer-actions">
                    <button class="action-button helpful-btn" type="button" title="Helpful" data-vote-type="yes" data-query="${query.replace(/"/g, '&quot;')}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#6b7280" viewBox="0 0 24 24">
                            <path d="M1.75 23h2.5C5.215 23 6 22.215 6 21.25V9.75C6 8.785 5.215 8 4.25 8h-2.5C.785 8 0 8.785 0 9.75v11.5C0 22.215.785 23 1.75 23zM12.781.75c-1 0-1.5.5-1.5 3 0 2.376-2.301 4.288-3.781 5.273v12.388c1.601.741 4.806 1.839 9.781 1.839h1.6c1.95 0 3.61-1.4 3.94-3.32l1.12-6.5a3.998 3.998 0 0 0-3.94-4.68h-4.72s.75-1.5.75-4c0-3-2.25-4-3.25-4z"/>
                        </svg>
                    </button>
                    <button class="action-button not-helpful-btn" type="button" title="Not helpful" data-vote-type="no" data-query="${query.replace(/"/g, '&quot;')}">
                        <svg xmlns="http://www.w3.org/2000/svg" width="12" height="12" fill="#6b7280" viewBox="0 0 24 24">
                            <path d="M22.25 1h-2.5C18.785 1 18 1.785 18 2.75v11.5c0 .965.785 1.75 1.75 1.75h2.5c.965 0 1.75-.785 1.75-1.75V2.75C24 1.785 23.215 1 22.25 1zM5.119.75c-1.95 0-3.61 1.4-3.94 3.32l-1.12 6.5a3.998 3.998 0 0 0 3.94 4.68h4.72s-.75 1.5-.75 4c0 3 2.25 4 3.25 4s1.5-.5 1.5-3c0-2.376 2.301-4.288 3.781-5.273V2.589C14.899 1.848 11.694.75 6.719.75z"/>
                        </svg>
                    </button>
                    <button class="action-button copy-btn" type="button" title="Copy" data-copy-text="${cleanTextForCopy.replace(/"/g, '&quot;')}">
                        <svg xmlns="http://www.w3.org/2000/svg" version="1.1" xmlns:xlink="http://www.w3.org/1999/xlink" width="12" height="12" x="0" y="0" fill="#6b7280" viewBox="0 0 24 24" style="enable-background:new 0 0 512 512" xml:space="preserve" class=""><g><path d="M18.548 2H9.452A3.456 3.456 0 0 0 6 5.452V6h-.548A3.456 3.456 0 0 0 2 9.452v9.096A3.456 3.456 0 0 0 5.452 22h9.096c1.748 0 3.182-1.312 3.406-3h.594A3.456 3.456 0 0 0 22 15.548V5.452A3.456 3.456 0 0 0 18.548 2zM20 15.548c0 .8-.651 1.452-1.452 1.452H18V9.452A3.456 3.456 0 0 0 14.548 6H8v-.548C8 4.652 8.651 4 9.452 4h9.096c.8 0 1.452.651 1.452 1.452z"></path></g></svg>
                    </button>
                </div>
            </div>
            <div class="references" style="display: none;">`;

    uniqueLinks.forEach(function(link) {
        const ref = data.references.find((r) => r.link === link);
        referencesHtml += `
            <a href="${link || '#'}" target="_blank" rel="noopener noreferrer">
                <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 36 36" fill="currentColor">
                    <path d="M17.6,24.32l-2.46,2.44a4,4,0,0,1-5.62,0,3.92,3.92,0,0,1,0-5.55l4.69-4.65a4,4,0,0,1,5.62,0,3.86,3.86,0,0,1,1,1.71A2,2,0,0,0,21.1,18l1.29-1.28a5.89,5.89,0,0,0-1.15-1.62,6,6,0,0,0-8.44,0L8.1,19.79a5.91,5.91,0,0,0,0,8.39,6,6,0,0,0,8.44,0l3.65-3.62c-.17,0-.33,0-.5,0A8,8,0,0,1,17.6,24.32Z"></path>
                    <path d="M28.61,7.82a6,6,0,0,0-8.44,0l-3.65,3.62c.17,0,.33,0,.49,0h0a8,8,0,0,1,2.1.28l2.46-2.44a4,4,0,0,1,5.62,0,3.92,3.92,0,0,1,0,5.55l-4.69,4.65a4,4,0,0,1-5.62,0,3.86,3.86,0,0,1-1-1.71,2,2,0,0,0-.28.23l-1.29,1.28a5.89,5.89,0,0,0,1.15,1.62,6,6,0,0,0,8.44,0l4.69-4.65a5.92,5.92,0,0,0,0-8.39Z"></path>
                </svg>
                <span class="text">${ref.title || 'Source'}</span>
            </a>
        `;
    });

    referencesHtml += '</div></div>';

    container.innerHTML = `
        <div class="ai-answer">
            <button class="ai-answer-close-btn" type="button" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            <div class="ai-answer-header">
                <svg class="ai-icon" xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 32 32">
                    <g>
                        <path d="m13.294 7.436.803 2.23a8.835 8.835 0 0 0 5.316 5.316l2.23.803a.229.229 0 0 1 0 .43l-2.23.803a8.835 8.835 0 0 0-5.316 5.316l-.803 2.23a.229.229 0 0 1-.43 0l-.803-2.23a8.835 8.835 0 0 0-5.316-5.316l-2.23-.803a.229.229 0 0 1 0-.43l2.23-.803a8.835 8.835 0 0 0 5.316-5.316l.803-2.23a.228.228 0 0 1 .43 0z" fill="#0079FF"/>
                        <path d="M23.332 2.077l.407 1.129a4.477 4.477 0 0 0 2.692 2.692l1.129.407a.116.116 0 0 1 0 .218l-1.129.407a4.477 4.477 0 0 0-2.692 2.692l-.407 1.129a.116.116 0 0 1-.218 0l-.407-1.129a4.477 4.477 0 0 0-2.692-2.692l-1.129-.407a.116.116 0 0 1 0-.218l1.129-.407a4.477 4.477 0 0 0 2.692-2.692l.407-1.129a.116.116 0 0 1 .218 0z" fill="#0079FF"/>
                        <path d="M23.332 21.25l.407 1.129a4.477 4.477 0 0 0 2.692 2.692l1.129.407a.116.116 0 0 1 0 .218l-1.129.407a4.477 4.477 0 0 0-2.692 2.692l-.407 1.129a.116.116 0 0 1-.218 0l-.407-1.129a4.477 4.477 0 0 0-2.692-2.692l-1.129-.407a.116.116 0 0 1 0-.218l1.129-.407a4.477 4.477 0 0 0 2.692-2.692l.407-1.129c.037-.102.182-.102.218 0z" fill="#0079FF"/>
                    </g>
                </svg>
                <h4>AI Answer</h4>
            </div>
            <div class="answer-content">
                ${data.answer || data.reply || 'No answer available'}
            </div>
            ${referencesHtml}
        </div>
    `;

    // Add event listener for close button
    const closeBtn = container.querySelector('.ai-answer-close-btn');
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            closeSearchResults(block);
        });
    }

    // Add event listener for sources toggle
    const sourcesButton = container.querySelector('.sources');
    if (sourcesButton) {
        sourcesButton.addEventListener('click', function() {
            const referencesEl = container.querySelector('.references');
            if (referencesEl) {
                const isVisible = referencesEl.style.display !== 'none';
                referencesEl.style.display = isVisible ? 'none' : 'flex';
                this.classList.toggle('expanded', !isVisible);
            }
        });
    }

    // Add event listener for copy button
    const copyButton = container.querySelector('.copy-btn');
    if (copyButton) {
        copyButton.addEventListener('click', function() {
            const textToCopy = this.getAttribute('data-copy-text');
            copyToClipboard(textToCopy);
        });
    }

    // Add event listeners for voting buttons
    const voteButtons = container.querySelectorAll('.action-button[data-vote-type]');
    voteButtons.forEach(function(button) {
        const postId = window.antimanual_vars?.post_id ||
                       window.antimanual_chatbot_vars?.post_id ||
                       document.querySelector('article')?.id?.replace('post-', '') ||
                       document.body.dataset.postId ||
                       1;
        const voteKey = `antimanual-voted-${postId}-${query}`;
        const votedType = localStorage.getItem(voteKey);
        if (votedType) {
            button.disabled = true;
            if (button.getAttribute('data-vote-type') === votedType) {
                button.classList.add('voted');
            } else {
                button.classList.remove('voted');
            }
            button.title = 'You have already voted';
        } else {
            button.disabled = false;
            button.classList.remove('voted');
            button.title = '';
        }
        button.addEventListener('click', function() {
            handleFeedback(this.getAttribute('data-vote-type'), this.getAttribute('data-query'), this, answerHtml, queryLogId);
        });
    });
}

/**
 * Show error message in the results container
 * @param {HTMLElement} container - The container to show the error in
 * @param {string} message - The error message
 */
function showError(container, message, block) {
    container.innerHTML = `
        <div class="ai-answer">
            <button class="ai-answer-close-btn" type="button" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 24 24">
                    <path d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/>
                </svg>
            </button>
            <div class="ai-answer-header">
                <svg class="ai-icon" fill="#ef4444" width="28" height="28" viewBox="0 0 24 24">
                    <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/>
                </svg>
                <h4>Error</h4>
            </div>
            <div class="answer-content">
                <p>Sorry, I couldn't process your request: ${message}</p>
                <p>Please try again or contact support if the issue persists.</p>
            </div>
        </div>
    `;
    
    // Add close button event listener
    const closeBtn = container.querySelector('.ai-answer-close-btn');
    if (closeBtn && block) {
        closeBtn.addEventListener('click', function() {
            closeSearchResults(block);
        });
    }
}

/**
 * Handle feedback voting system
 * @param {string} voteType - 'yes' or 'no'
 * @param {string} query - The search query that was voted on
 * @param {HTMLElement} clickedButton - The button that was clicked
 * @param {string} answerHtml - The HTML answer content
 * @param {number|null} queryLogId - The database record ID for this query (from search response)
 */
function handleFeedback(voteType, query, clickedButton, answerHtml, queryLogId) {
    const postId = window.antimanual_vars?.post_id ||
                   window.antimanual_chatbot_vars?.post_id ||
                   document.querySelector('article')?.id?.replace('post-', '') ||
                   document.body.dataset.postId ||
                   1;
    const voteKey = `antimanual-voted-${postId}-${query}`;
    if (localStorage.getItem(voteKey)) {
        showTemporaryMessage('You have already voted for this answer.', 'info');
        disableVotingButtons(voteType);
        return;
    }

    // Prepare data for AJAX request
    const formData = new FormData();
    formData.append('action', 'antimanual_vote');
    formData.append('post_id', postId);
    formData.append('vote_type', voteType);
    
    // Primary method: Use query_log_id for reliable ID-based update
    if (queryLogId) {
        formData.append('query_log_id', queryLogId);
    }
    
    // Fallback: Include query/answer for backwards compatibility
    formData.append('query', query);

    // Properly encode the HTML content to prevent JSON parsing issues
    if (answerHtml) {
        // Strip HTML tags and get clean text, or encode properly
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = answerHtml;
        const cleanAnswer = tempDiv.textContent || tempDiv.innerText || '';
        formData.append('answer', cleanAnswer);
    }

    // Add nonce for security (optional)
    if (window.antimanual_vars?.nonce) {
        formData.append('nonce', window.antimanual_vars.nonce);
    }

    // Get AJAX URL
    const ajaxUrl = window.antimanual_vars?.ajax_url ||
                    window.antimanual_chatbot_vars?.ajax_url ||
                    window.ajaxurl ||
                    '';

    if (!ajaxUrl) {
        showTemporaryMessage('AJAX endpoint not configured', 'error');
        if (clickedButton) {
            clickedButton.disabled = false;
            clickedButton.style.opacity = '1';
        }
        return;
    }

    // Show loading state on the clicked button
    if (clickedButton) {
        clickedButton.disabled = true;
        clickedButton.style.opacity = '0.5';
    }
    // Send AJAX request
    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(function(response) {
        return response.json();
    })
    .then(function(data) {
        if (data.success) {
            // Mark as voted in localStorage (store voteType)
            localStorage.setItem(voteKey, voteType);
            showTemporaryMessage('Thank you for your feedback!', 'success');
            updateVoteCounts(data.data.yes_votes, data.data.no_votes);
            disableVotingButtons(voteType);
            if (clickedButton) {
                clickedButton.classList.add('voted');
            }
        } else {
            showTemporaryMessage(data.data?.message || 'Failed to record vote', 'error');
        }
    })
    .catch(function(error) {
        console.error('Voting error:', error);
        showTemporaryMessage('Network error occurred', 'error');
    })
    .finally(function() {
        // Restore button state
        if (clickedButton) {
            clickedButton.disabled = false;
            clickedButton.style.opacity = '1';
        }
    });
}

/**
 * Update vote counts in the UI (optional)
 * @param {number} yesVotes - Total yes votes
 * @param {number} noVotes - Total no votes
 */
function updateVoteCounts(yesVotes, noVotes) {
    // You can implement this if you want to show vote counts in the UI
    const yesCountEl = document.querySelector('.vote-count-yes');
    const noCountEl = document.querySelector('.vote-count-no');

    if (yesCountEl) yesCountEl.textContent = yesVotes;
    if (noCountEl) noCountEl.textContent = noVotes;
}

/**
 * Disable voting buttons to prevent duplicate votes
 */
function disableVotingButtons(votedType) {
    const voteButtons = document.querySelectorAll('.action-button[data-vote-type]');
    voteButtons.forEach(function(button) {
        button.disabled = true;
        button.style.opacity = '0.5';
        button.title = 'You have already voted';
        if (votedType && button.getAttribute('data-vote-type') === votedType) {
            button.classList.add('voted');
        } else {
            button.classList.remove('voted');
        }
    });
}

/**
 * Show temporary message to user
 * @param {string} message - Message to show
 * @param {string} type - Message type: 'success', 'error', 'info'
 */
function showTemporaryMessage(message, type = 'info') {
    // Remove any existing messages
    const existingMessage = document.querySelector('.antimanual-temp-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    // Create message element
    const messageEl = document.createElement('div');
    messageEl.className = `antimanual-temp-message antimanual-temp-message--${type}`;
    messageEl.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        padding: 12px 16px;
        border-radius: 4px;
        font-size: 14px;
        font-weight: 500;
        max-width: 300px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        transform: translateX(100%);
        transition: transform 0.3s ease-in-out;
    `;

    // Set colors based on type
    switch (type) {
        case 'success':
            messageEl.style.backgroundColor = '#10b981';
            messageEl.style.color = 'white';
            break;
        case 'error':
            messageEl.style.backgroundColor = '#ef4444';
            messageEl.style.color = 'white';
            break;
        default:
            messageEl.style.backgroundColor = '#3b82f6';
            messageEl.style.color = 'white';
    }

    messageEl.textContent = message;
    document.body.appendChild(messageEl);

    // Animate in
    setTimeout(function() {
        messageEl.style.transform = 'translateX(0)';
    }, 10);

    // Auto remove after 3 seconds
    setTimeout(function() {
        messageEl.style.transform = 'translateX(100%)';
        setTimeout(function() {
            if (messageEl.parentNode) {
                messageEl.parentNode.removeChild(messageEl);
            }
        }, 300);
    }, 3000);
}

function copyToClipboard(text) {
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            // Show success notification
            showTemporaryMessage('Copied to clipboard!', 'success');
        }).catch(function() {
            fallbackCopyToClipboard(text);
        });
    } else {
        fallbackCopyToClipboard(text);
    }
}

function fallbackCopyToClipboard(text) {
    const textArea = document.createElement('textarea');
    textArea.value = text;
    textArea.style.position = 'fixed';
    textArea.style.left = '-999999px';
    textArea.style.top = '-999999px';
    document.body.appendChild(textArea);
    textArea.focus();
    textArea.select();

    try {
        document.execCommand('copy');
        showTemporaryMessage('Copied to clipboard!', 'success');
    } catch (err) {
        showTemporaryMessage('Failed to copy text', 'error');
    }

    document.body.removeChild(textArea);
}
