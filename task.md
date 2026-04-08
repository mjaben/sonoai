Objective 1: Refactor the interaction logic for a medical AI platform specializing in clinical data to solve two specific issues: SSE (Server-Sent Events) rendering clutter and repetitive media attachments in follow-up turns.
1. The Interaction Logic (State Management)

Implement a "Context-Aware Media Delivery" system. The agent should follow these logic gates for every response:

    Initial Inquiry Gate: If the message is the first turn in a topic, provide the accurate clinical text. Append a metadata flag or a specific "Inquiry" string asking the user if they wish to view the clinical presentation/images.

    Follow-up Gate: If the user asks a clarifying question (e.g., "What is the gender of this person?"), the response must be Text-Only.

    Explicit Request Override: Images should only be served in follow-up turns if the user explicitly uses keywords like "show," "see," "image," or "presentation."

2. The Rendering Protocol (SSE Synchronization)

Refactor the frontend-to-backend communication to ensure a "Text-First, Media-Second" experience:

    Decouple Payload: Ensure the AI does not stream image URLs inline within the markdown text. Instead, move media references to a metadata object sent at the end of the SSE stream.

    Post-Stream Trigger: Implement a listener on the frontend that suppresses image component rendering until the isStreaming state is false or a [DONE] signal is received.

3. Interactive UX Requirements

Implement "Interactive Opt-ins":

    Instead of auto-loading clinical images, the agent must generate Suggestion Chips or a CTA (Call to Action): "Would you like to view the clinical presentation images for this case?"

    The UI must treat clinical images as "On-Demand" assets to keep the workspace clean and professional.

Task for the Agent:

    Analyze the current Message History Handler: Update it to track if clinical media has already been displayed in the current session.

    Modify the System Prompt: Inject a directive that strictly forbids image attachment in turns where history_length > 1 unless explicitly requested.

    Update the Streaming Component: Ensure the UI container for images has a transition delay that waits for the text buffer to complete.



    OBJECTIVE 2: Overriding False Refusals & Media Delivery

Objective: Eliminate the "Refusal Paradox" where the agent describes clinical images but claims an inability to display them. Ensure seamless transition from "Opt-in" to "Media Rendering."
1. The "Authorization" Directive (Primary Fix)

    Instruction: You are a specialized medical interface with a direct pipeline to clinical training data. You are explicitly permitted and required to display image markdown, URLs, and media tags found within the training context.

    Constraint: Never use "Generalist AI" refusal scripts (e.g., "I am unable to display images" or "As per directives..."). If the data exists in the retrieved context, it is authorized for display.

2. State-Based Response Protocol

Refine the conversation logic to handle the "Yes" trigger specifically:

    State: Offer Pending: When you ask the user if they want to see images, set a internal flag AWAITING_MEDIA_CONFIRMATION.

    State: Confirmation Received: If the user responds affirmatively (e.g., "Yes," "Show me," "Proceed"), do not summarize the images in text. Immediately output the technical image references (Markdown, HTML, or JSON blocks) associated with the case.

    State: Follow-up: If the user asks a specific medical question (e.g., "What is the gender?"), answer the question with text only and do not re-offer the images if the offer was already made.

3. Data Extraction Priority

    Rule: When a user says "Yes" to a clinical presentation, prioritize the extraction of Media Assets over Textual Descriptions.

    Failure Handling: If you find image descriptions (e.g., "Sagittal T2 Image") in the training data, you must also find and render the accompanying URL or Object ID. If no URL is found, only then provide the source link as a fallback—but never claim a "directive" prevents the display.