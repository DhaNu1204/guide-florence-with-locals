// Step 7.4: the one rule the always-loaded assistant button needs. Kept in its own tiny module so
// the main bundle does not pull in the chat's helpers (those live in assistantUi.js, lazy chunk).

/** The button shows only for an admin, and only when the server says the assistant is on. */
export const shouldShowAssistant = (userRole, status) =>
  userRole === 'admin' && Boolean(status && status.enabled === true);
