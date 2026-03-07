<?php
/**
 * SonoAI — Admin Dashboard App Template
 * Rendered by [sonoai_admin] shortcode.
 *
 * @package SonoAI
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div id="sonoai-admin-app-root" class="sonoai-admin-layout" data-theme="light">
    
    <!-- Sidebar -->
    <aside class="sonoai-admin-sidebar">
        <div class="sonoai-admin-brand">
            <span class="icon">🔬</span>
            <span class="name">SonoAI</span>
        </div>
        
        <nav class="sonoai-admin-nav">
            <a href="#dashboard" class="nav-item active" data-target="dashboard">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" class="nav-icon" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path><polyline points="9 22 9 12 15 12 15 22"></polyline></svg>
                Dashboard
            </a>
            <a href="#api-config" class="nav-item" data-target="api-config">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" class="nav-icon" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"></path><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"></path></svg>
                API Config
            </a>
            <a href="#knowledge-base" class="nav-item" data-target="knowledge-base">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" class="nav-icon" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"></path><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"></path></svg>
                Knowledge Base
            </a>
            <a href="#settings" class="nav-item" data-target="settings">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" class="nav-icon" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"></path></svg>
                Settings
            </a>
        </nav>
    </aside>
    
    <!-- Main Content -->
    <main class="sonoai-admin-main">
        
        <!-- Header -->
        <header class="sonoai-admin-header">
            <h1 class="page-title" id="sonoai-page-title">Dashboard</h1>
            <div class="header-actions">
                <button id="theme-toggle" class="icon-btn" aria-label="Toggle dark mode">
                    <svg class="sun-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"></circle><line x1="12" y1="1" x2="12" y2="3"></line><line x1="12" y1="21" x2="12" y2="23"></line><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line><line x1="1" y1="12" x2="3" y2="12"></line><line x1="21" y1="12" x2="23" y2="12"></line><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line></svg>
                    <svg class="moon-icon" xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path></svg>
                </button>
            </div>
        </header>

        <!-- View: Dashboard -->
        <section id="view-dashboard" class="sonoai-view active-view">
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-header">
                        <span class="stat-title">Total Chats</span>
                        <div class="stat-icon-wrapper"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg></div>
                    </div>
                    <div class="stat-value">Coming Soon</div>
                </div>
                <!-- More stats can be added here -->
            </div>
        </section>

        <!-- View: API Config -->
        <section id="view-api-config" class="sonoai-view">
            <div class="card">
                <h2>Provider Settings</h2>
                <form id="api-config-form" class="sonoai-form">
                    <div class="form-group">
                        <label>AI Provider</label>
                        <select name="active_provider" id="active_provider" class="sonoai-select">
                            <option value="openai">OpenAI</option>
                            <option value="claude">Anthropic Claude</option>
                            <option value="gemini">Google Gemini</option>
                        </select>
                    </div>

                    <!-- OpenAI Settings -->
                    <div class="provider-settings" id="settings-openai">
                        <div class="form-group">
                            <label>OpenAI API Key</label>
                            <input type="password" name="openai_api_key" class="sonoai-input" placeholder="sk-...">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Chat Model</label>
                                <select name="openai_chat_model" class="sonoai-select">
                                    <option value="gpt-4o">gpt-4o</option>
                                    <option value="gpt-4-turbo">gpt-4-turbo</option>
                                    <option value="gpt-3.5-turbo">gpt-3.5-turbo</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Embedding Model</label>
                                <select name="openai_embedding_model" class="sonoai-select">
                                    <option value="text-embedding-3-small">text-embedding-3-small</option>
                                    <option value="text-embedding-3-large">text-embedding-3-large</option>
                                    <option value="text-embedding-ada-002">text-embedding-ada-002</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Claude Settings -->
                    <div class="provider-settings" id="settings-claude" style="display: none;">
                        <div class="form-group">
                            <label>Anthropic API Key</label>
                            <input type="password" name="claude_api_key" class="sonoai-input" placeholder="sk-ant-...">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Chat Model</label>
                                <select name="claude_chat_model" class="sonoai-select">
                                    <option value="claude-3-opus-20240229">Claude 3 Opus</option>
                                    <option value="claude-3-sonnet-20240229">Claude 3 Sonnet</option>
                                    <option value="claude-3-haiku-20240307">Claude 3 Haiku</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Gemini Settings -->
                    <div class="provider-settings" id="settings-gemini" style="display: none;">
                        <div class="form-group">
                            <label>Gemini API Key</label>
                            <input type="password" name="gemini_api_key" class="sonoai-input" placeholder="AIza...">
                        </div>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Chat Model</label>
                                <select name="gemini_chat_model" class="sonoai-select">
                                    <option value="gemini-1.5-pro">Gemini 1.5 Pro</option>
                                    <option value="gemini-1.5-flash">Gemini 1.5 Flash</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>Embedding Model</label>
                                <select name="gemini_embedding_model" class="sonoai-select">
                                    <option value="text-embedding-004">text-embedding-004</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="sonoai-btn sonoai-btn-primary">Save API Configuration</button>
                    <span id="api-config-msg" class="form-msg"></span>
                </form>
            </div>
        </section>

        <!-- View: Knowledge Base -->
        <section id="view-knowledge-base" class="sonoai-view">
            <div class="kb-grid">
                
                <!-- WordPress Post -->
                <div class="card kb-source-card">
                    <div class="kb-source-icon wp-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg></div>
                    <h3>WordPress Post</h3>
                    <p>Add existing posts, pages, and custom post types to train your AI.</p>
                    <div class="kb-source-action">
                        <select id="kb-wp-select" class="sonoai-select" style="margin-bottom: 10px;">
                            <option value="">Select a Post...</option>
                            <?php
                            $posts = get_posts(['post_type' => ['post', 'page'], 'numberposts' => 20]);
                            foreach($posts as $p) {
                                echo '<option value="'.esc_attr($p->ID).'">'.esc_html($p->post_title).'</option>';
                            }
                            ?>
                        </select>
                        <button class="sonoai-btn kb-add-btn" data-source="wp">Add Post</button>
                    </div>
                </div>

                <!-- PDF Upload -->
                <div class="card kb-source-card">
                    <div class="kb-source-icon pdf-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="9" y1="15" x2="15" y2="15"></line></svg></div>
                    <h3>PDF Upload</h3>
                    <p>Upload PDF documents to extract and add their content.</p>
                    <div class="kb-source-action file-drop-area" id="kb-pdf-drop">
                        <span class="fake-btn">Choose PDF</span>
                        <span class="file-msg">or drag and drop</span>
                        <input class="file-input" type="file" id="kb-pdf-file" accept=".pdf">
                    </div>
                </div>

                <!-- Website URL -->
                <div class="card kb-source-card">
                    <div class="kb-source-icon url-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><line x1="2" y1="12" x2="22" y2="12"></line><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path></svg></div>
                    <h3>Website URL</h3>
                    <p>Crawl and add content from any public website URL.</p>
                    <div class="kb-source-action">
                        <input type="url" id="kb-url-input" class="sonoai-input" placeholder="https://..." style="margin-bottom: 10px;">
                        <button class="sonoai-btn kb-add-btn" data-source="url">Crawl URL</button>
                    </div>
                </div>

                <!-- Custom Text -->
                <div class="card kb-source-card">
                    <div class="kb-source-icon text-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path></svg></div>
                    <h3>Custom Text</h3>
                    <p>Write or paste any custom text, from short notes to full articles.</p>
                    <div class="kb-source-action">
                        <input type="text" id="kb-text-title" class="sonoai-input" placeholder="Title" style="margin-bottom: 10px;">
                        <textarea id="kb-text-content" class="sonoai-textarea" rows="3" placeholder="Paste text here..." style="margin-bottom: 10px; resize:none;"></textarea>
                        <button class="sonoai-btn kb-add-btn" data-source="text">Add Text</button>
                    </div>
                </div>

            </div>
        </section>

        <!-- View: Settings -->
        <section id="view-settings" class="sonoai-view">
            <div class="card">
                <h2>General Settings</h2>
                <form id="general-settings-form" class="sonoai-form">
                    <div class="form-group">
                        <label>System Prompt</label>
                        <textarea name="system_prompt" class="sonoai-textarea" rows="6" placeholder="You are a helpful AI assistant..."></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>Chat History Limit</label>
                            <input type="number" name="history_limit" class="sonoai-input" min="1" max="500" value="50">
                        </div>
                        <div class="form-group">
                            <label>RAG Results Count</label>
                            <input type="number" name="rag_results" class="sonoai-input" min="1" max="20" value="5">
                        </div>
                    </div>
                    
                    <button type="submit" class="sonoai-btn sonoai-btn-primary">Save Settings</button>
                    <span id="general-settings-msg" class="form-msg"></span>
                </form>
            </div>
            
            <!-- Danger Zone -->
            <div class="card card-danger" style="margin-top: 24px;">
                <h2>Danger Zone</h2>
                <form id="danger-settings-form" class="sonoai-form">
                    <label class="sonoai-checkbox">
                        <input type="checkbox" name="delete_on_uninstall" id="delete_on_uninstall">
                        <span>Delete all plugin data (database tables, options, file uploads) when the plugin is uninstalled. <strong>This action cannot be undone.</strong></span>
                    </label>
                    <div style="margin-top: 15px;">
                        <button type="submit" class="sonoai-btn sonoai-btn-danger">Save Danger Settings</button>
                        <span id="danger-settings-msg" class="form-msg"></span>
                    </div>
                </form>
            </div>
        </section>
        
    </main>
</div>
