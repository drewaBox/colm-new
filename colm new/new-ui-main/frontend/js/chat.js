// AI chatbot page (students and admin/registrar). The AI key stays on the server: this file only calls api/chat.php.
(function () {
  const messagesBox = document.getElementById("chatMessages");
  const form = document.getElementById("chatForm");
  const input = document.getElementById("chatInput");
  const sendButton = document.getElementById("chatSend");
  const clearButton = document.getElementById("chatClear");
  const suggestions = document.getElementById("chatSuggestions");
  let loaded = false;
  let waiting = false;

  const STUDENT_QUESTIONS = ["What documents can I request?", "What are the requirements for the Transcript of Records?", "What is the status of my request?", "Why was my document declined?", "How long does processing take?"];
  const ADMIN_QUESTIONS = ["How many requests are waiting for review?", "What are the requirements for Certificate of Registration?", "How does the document verification work?"];

  function addMessage(sender, text, extraClass = "") {
    const bubble = document.createElement("div");
    bubble.className = `chat-bubble chat-${sender} ${extraClass}`.trim();
    bubble.textContent = text;           // textContent, so nothing the AI writes can run as HTML
    messagesBox.append(bubble);
    messagesBox.scrollTop = messagesBox.scrollHeight;
    return bubble;
  }

  function showWelcome() {
    addMessage("assistant", window.portalUser.role === "student"
      ? `Hello ${window.portalUser.full_name.split(" ")[0]}! I can help with document requirements, how to submit, your request status and your profile. What would you like to know?`
      : "Hello! I can answer questions about the catalog, requirements, the verification process and the request summary. What would you like to know?");
  }

  async function loadHistory() {
    if (loaded) return;
    loaded = true;
    messagesBox.replaceChildren();
    try {
      const data = await Api.get("chat.php");
      showWelcome();
      data.messages.forEach(m => addMessage(m.sender, m.message));
      if (!data.enabled) addMessage("assistant", window.portalUser.role === "admin"
        ? "The chatbot is not set up yet: add AI_API_KEY to the .env file, then use Settings > Test AI connection."
        : "The assistant is not available right now. Please try again later.", "chat-error");
    } catch (error) {
      showWelcome();
      addMessage("assistant", error.message, "chat-error");
    }
  }

  async function ask(question) {
    if (waiting || !question.trim()) return;
    waiting = true;
    sendButton.disabled = true;
    addMessage("user", question);
    input.value = "";
    const thinking = addMessage("assistant", "Thinking...", "chat-loading");
    try {
      const data = await Api.postJson("chat.php", { message: question }, {}, 70000);
      thinking.remove();
      addMessage("assistant", data.answer);
    } catch (error) {
      thinking.remove();
      // 4xx messages (empty question, too many questions) are already friendly; everything else gets the standard text.
      const friendly = error.status === 422 || error.status === 429 || (window.portalUser.role === "admin" && error.status === 503);
      addMessage("assistant", friendly ? error.message : "Unable to process your message. Please try again.", "chat-error");
      Portal.toast("Unable to process your message. Please try again.", "error");
    } finally {
      waiting = false;
      sendButton.disabled = false;
      input.focus();
    }
  }

  form.addEventListener("submit", event => { event.preventDefault(); ask(input.value); });
  suggestions.addEventListener("click", event => {
    const chip = event.target.closest("[data-question]");
    if (chip) ask(chip.dataset.question);
  });
  clearButton.addEventListener("click", async () => {
    if (!window.confirm("Delete this whole conversation?")) return;
    try {
      await Api.postJson("chat.php", {}, { action: "clear" });
      loaded = false;
      loadHistory();
    } catch (error) { Portal.toast(error.message, "error"); }
  });

  document.addEventListener("portal:ready", event => {
    const questions = event.detail.role === "student" ? STUDENT_QUESTIONS : ADMIN_QUESTIONS;
    suggestions.innerHTML = questions.map(q => `<button type="button" class="chat-chip" data-question="${escapeHtml(q)}">${escapeHtml(q)}</button>`).join("");
  });
  document.addEventListener("page:change", event => { if (event.detail === "AI Assistant") { loadHistory(); input.focus(); } });
})();
