1 - Use both token-based session management and JSON storage
2 - To solve hallucination problem, we need to use a minimum similarity threshold and requiring the model to cite [Source N] below answer. A small pill size for the citation at the end of the last sentence.A style similar to most gen-AI tools.
Re-structure as fresh install on a postgreSQL database with pgvector extension.
Labelled data for : When data is being added to the knowledge base, it needs to be labelled with the topic. This is to ensure that the model can retrieve the most relevant information for a given query.
    Still on labelling: Since the AI auto detects contents via plugin post types, it would be great to have a way to manually label the data with the topic or auto-labeling based on the content itself. For example, eazydoc has categories as well, forum has forum as tags, forum topic, forum name, wordpress post uses categories and tags,so it when adding to knowledge base, it could pick that information as well and label. it helps with similarities and further help with SQL optimization. This could also help Reduce dataset before PHP loop. 
Store vectors more efficiently - Right now: JSON → decode → array
    Better: store as compressed string OR binary
    Even better (still MySQL-safe): keep JSON but cache decoded vectors in memory (static cache)
Cache top results : We already cache embeddings 
    Also cache: query_hash → top_chunks. Even 5–15 min cache = massive win.

