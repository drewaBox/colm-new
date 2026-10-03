// Request list, dashboard numbers and the request details window. Used by every role.
(function () {
  const requestsBody = document.getElementById("requestsBody");
  const statusFilter = document.getElementById("statusFilter");   // only on the staff page
  let user = null;
  let page = 1;
  let searchTimer = null;

  const STATUS_LABELS = {
    SUBMITTED: "Submitted", AI_VERIFYING: "Document Checking", FOR_REVIEW: "For Admin Review",
    NEEDS_CORRECTION: "Needs Correction", PROCESSING: "Processing", READY: "Ready for Release",
    COMPLETED: "Completed", REJECTED: "Rejected"
  };
  window.STATUS_LABELS = STATUS_LABELS;

  const AI_LABELS = { PASS: "AI: No problem found", REVIEW: "AI: Not sure", FAIL: "AI: Declined", UNAVAILABLE: "AI unavailable" };
  // The school uses three words: Pending, Verified, Declined.
  const REVIEW_LABELS = { Pending: "Pending", Verified: "Verified", Rejected: "Declined", Resubmit: "Declined" };

  const statusPill = status => `<span class="status-pill status-${escapeHtml(status.toLowerCase())}">${escapeHtml(STATUS_LABELS[status] || status)}</span>`;
  window.statusPill = statusPill;

  function releaseDateLabel(request) {
    if (request.status === "COMPLETED") return "Released";
    const start = Portal.formatDate(request.target_start);
    const end = Portal.formatDate(request.target_end);
    return start === end ? start : `${start} – ${end}`;
  }

  // ---------- Dashboard numbers ----------
  async function loadStats() {
    try {
      const stats = (await Api.get("requests.php", { action: "stats" })).stats;
      const isStudent = user.role === "student";
      const second = isStudent || user.role === "personnel" ? stats.processing : stats.for_review;
      const values = { statTotal: stats.total, statProcessing: second, statPending: stats.pending_payment, statReady: stats.ready, statCompleted: stats.completed };
      Object.entries(values).forEach(([id, value]) => { document.getElementById(id).textContent = value; });
      if (!isStudent) {
        document.getElementById("statTotalLabel").textContent = "All Requests";
        document.getElementById("statProcessingLabel").textContent = user.role === "personnel" ? "In Processing" : "Waiting for Review";
        document.getElementById("statReadyLabel").textContent = "Ready for Release";
        document.getElementById("statCompletedLabel").textContent = "Completed";
      }
      const empty = document.getElementById("dashboardEmpty");
      if (empty && isStudent) empty.hidden = stats.total > 0;
    } catch (error) { /* the dashboard just keeps its old numbers */ }
  }

  // ---------- Request table ----------
  function actionButtons(request) {
    const view = (label) => `<button class="request-table-action" type="button" data-view="${request.id}">${label}</button>`;
    if (user.role === "student") return view(request.status === "NEEDS_CORRECTION" ? "Fix documents" : "View");
    if (user.role === "admin") return view("Review");
    if (user.role === "personnel") {
      const canPay = request.payment_status === "Unpaid" && request.status !== "REJECTED";
      return (canPay ? `<button class="request-table-action" type="button" data-pay="${request.id}">Mark paid</button> ` : "") + view("View");
    }
    return view("View");
  }

  function renderRows(requests) {
    if (!requests.length) {
      requestsBody.innerHTML = '<tr><td colspan="10" class="requests-empty">No requests found.</td></tr>';
    } else {
      requestsBody.innerHTML = requests.map(request => `
        <tr>
          <td>${escapeHtml(request.tracking_no)}</td>
          <td>${escapeHtml(request.full_name)}</td>
          <td>${request.items.map(item => `${escapeHtml(item.name)} <span class="request-history-copies">× ${item.quantity}</span>`).join("<br>")}</td>
          <td>${Portal.formatMoney(request.total_amount)}</td>
          <td>${escapeHtml(releaseDateLabel(request))}</td>
          <td>${escapeHtml(request.payment_status)}</td>
          <td>${escapeHtml(request.payment_method)}</td>
          <td>${statusPill(request.status)}</td>
          <td>${request.is_overdue ? '<span class="overdue-flag">Overdue</span>' : ""}</td>
          <td>${actionButtons(request)}</td>
        </tr>`).join("");
    }
    document.dispatchEvent(new CustomEvent("table:rendered"));
  }

  function renderPager(total, perPage) {
    let pager = document.getElementById("requestsPager");
    if (!pager) {
      pager = document.createElement("div");
      pager.id = "requestsPager";
      pager.className = "pager";
      requestsBody.closest(".requests-table-wrap").after(pager);
      pager.addEventListener("click", event => {
        const button = event.target.closest("[data-page]");
        if (button) { page = Number(button.dataset.page); loadRequests(); }
      });
    }
    const pages = Math.max(1, Math.ceil(total / perPage));
    pager.innerHTML = pages <= 1 ? "" : `
      <button type="button" data-page="${page - 1}" ${page <= 1 ? "disabled" : ""}>Previous</button>
      <span>Page ${page} of ${pages}</span>
      <button type="button" data-page="${page + 1}" ${page >= pages ? "disabled" : ""}>Next</button>`;
  }

  async function loadRequests() {
    const params = { action: "list", page };
    if (statusFilter && statusFilter.value) params.status = statusFilter.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    try {
      const data = await Api.get("requests.php", params);
      renderRows(data.requests);
      renderPager(data.total, data.per_page);
    } catch (error) {
      requestsBody.innerHTML = `<tr><td colspan="10" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  // Other files call this after something changed.
  window.reloadRequests = function () {
    loadRequests();
    loadStats();
    window.refreshNotifications?.();
    document.dispatchEvent(new CustomEvent("requests:changed"));
  };
  window.loadStats = loadStats;

  // ---------- Request details window ----------
  async function openRequestDetail(id) {
    try {
      const data = await Api.get("requests.php", { action: "detail", id });
      if (id !== fixesRequestId) { discardFixes(); fixesRequestId = id; }
      let modal = document.querySelector("#modalBackdrop .modal-body");
      if (!modal || Number(modal.dataset.requestId) !== id) modal = Portal.openModal("", true).querySelector(".modal-body");
      modal.dataset.requestId = id;
      modal.innerHTML = detailHtml(data);
    } catch (error) {
      Portal.toast(error.message, "error");
    }
  }
  window.openRequestDetail = openRequestDetail;

  function fileSize(bytes) { return bytes >= 1048576 ? (bytes / 1048576).toFixed(1) + " MB" : Math.max(1, Math.round(bytes / 1024)) + " KB"; }
  const fileUrl = (id, inline) => `${API_URL}file.php?id=${id}${inline ? "&inline=1" : ""}`;

  function aiHtml(file, isRegistrar) {
    if (!file.ai) return '<span class="ai-pill ai-none">AI not run yet</span>';
    const ai = file.ai;
    let html = `<span class="ai-pill ai-${escapeHtml(ai.status.toLowerCase())}">${escapeHtml(AI_LABELS[ai.status])}</span>
      <small class="ai-reason">${escapeHtml(ai.reason || "")}</small>`;
    if (isRegistrar && ai.status !== "UNAVAILABLE" && ai.extracted_text !== undefined) {
      html += `<details class="ai-details"><summary>Details</summary>
        <p>Name on document: <strong>${escapeHtml(ai.name_on_document || "not found")}</strong>${ai.name_match_score !== null ? ` (match ${ai.name_match_score}%)` : ""}</p>
        <p>Confidence: ${ai.confidence !== null ? Math.round(ai.confidence * 100) + "%" : "not given"} · Model: ${escapeHtml(ai.model || "")} · ${escapeHtml(Portal.formatDate(ai.created_at, true))}</p>
        <p class="ai-text">${escapeHtml(ai.extracted_text || "No text was extracted.")}</p></details>`;
    }
    if (isRegistrar && ai.error_message) html += `<small class="ai-error">${escapeHtml(ai.error_message)}</small>`;
    return html;
  }

  // Documents a student uploads again after a correction request: "itemId:requirementId" -> { state, checkId, message, file }
  const fixes = new Map();
  let fixesRequestId = 0;
  let fixesRunning = 0;

  function discardFixes() {
    fixes.forEach(f => { if (f.checkId) Api.postJson("verify.php", { id: f.checkId }, { action: "discard" }).catch(() => {}); });
    fixes.clear();
    fixesRunning = 0;
  }

  function redrawFixBox(key) {
    const box = document.querySelector(`#modalBackdrop .upload-box[data-fix-key="${CSS.escape(key)}"]`);
    if (!box) return;
    const f = fixes.get(key);
    box.innerHTML = uploadBoxHtml(f, f && f.file, box.dataset.inputId, box.dataset.types.toUpperCase().split(",").join(", "), box.dataset.maxMb, key);
  }

  function updateSendButton() {
    const button = document.querySelector("#modalBackdrop [data-action='send-corrections']");
    if (!button) return;
    button.disabled = fixesRunning > 0;
    button.title = fixesRunning > 0 ? "Please wait while your documents are being verified." : "";
  }

  async function verifyFix(key, input, file) {
    const old = fixes.get(key);
    fixes.set(key, { state: "checking", file });
    redrawFixBox(key);
    fixesRunning++;
    updateSendButton();
    try {
      const result = await verifyUpload(file, input.dataset.reqId, old && old.checkId);
      if (!fixes.has(key) || fixes.get(key).file !== file) {
        if (result.id) Api.postJson("verify.php", { id: result.id }, { action: "discard" }).catch(() => {});
        return;
      }
      if (result.status === "DECLINED") {
        fixes.set(key, { state: "declined", message: result.message, file });
        Portal.toast(result.message, "error", "Document Declined");
      } else {
        fixes.set(key, { state: result.status === "ACCEPTED" ? "accepted" : "received", checkId: result.id, message: result.message, file });
        Portal.toast(result.message, "success", result.status === "ACCEPTED" ? "Document verified" : "Document received");
      }
    } catch (error) {
      if (!fixes.has(key) || fixes.get(key).file !== file) return;
      const view = verifyErrorView(error);
      fixes.set(key, { state: "declined", message: view.message, file });
      Portal.toast(view.message, "error", view.title);
    } finally {
      fixesRunning = Math.max(0, fixesRunning - 1);
      updateSendButton();
      redrawFixBox(key);
    }
  }

  function requirementRow(request, item, req) {
    const isRegistrar = user.role === "admin";
    const canSeeFiles = request.can_see_files;
    const studentFixes = user.role === "student" && request.status === "NEEDS_CORRECTION" && req.needs_fix;
    let fileCell, aiCell = "", reviewCell = "";

    if (!canSeeFiles) {
      return `<tr><td><strong>${escapeHtml(req.name)}</strong></td><td colspan="3" class="muted">Uploaded documents are only visible to the admin/registrar and the student.</td></tr>`;
    }
    const key = `${item.id}:${req.id}`;
    if (req.file) {
      const f = req.file;
      fileCell = `<span class="file-name">${escapeHtml(f.original_name)}</span> <small>(${fileSize(f.size_bytes)})</small><br>
        <a href="${fileUrl(f.id, true)}" target="_blank" rel="noopener">Preview</a> ·
        <a href="${fileUrl(f.id, false)}">Download</a>`;
      aiCell = aiHtml(f, isRegistrar);
      reviewCell = `<span class="review-pill review-${escapeHtml(f.review_status.toLowerCase())}">${escapeHtml(REVIEW_LABELS[f.review_status])}</span>`;
      if (f.review_remarks) reviewCell += `<small class="review-remark">${["Rejected", "Resubmit"].includes(f.review_status) ? "Declined — " : ""}${escapeHtml(f.review_remarks)}</small>`;
      if (isRegistrar && ["FOR_REVIEW", "NEEDS_CORRECTION"].includes(request.status)) {
        reviewCell += `<div class="review-controls" data-file-id="${f.id}">
          <input type="text" class="review-remarks" maxlength="500" placeholder="Reason (required to decline)" aria-label="Remark for ${escapeHtml(req.name)}">
          <button type="button" class="mini-btn ok" data-review="Verified">Approve</button>
          <button type="button" class="mini-btn bad" data-review="Rejected">Decline</button>
          <button type="button" class="mini-btn" data-run-ai="${f.id}">Verify again (AI)</button></div>`;
      }
    } else {
      fileCell = '<span class="missing-file">Required document missing</span>';
    }
    if (studentFixes) {
      const inputId = `fix-${item.id}-${req.id}`;
      const f = fixes.get(key);
      fileCell += `<input type="file" class="request-required-file fix-file" id="${inputId}" data-key="${key}" data-item-id="${item.id}" data-req-id="${req.id}"
          data-max-mb="${req.max_size_mb}" data-types="${escapeHtml(req.allowed_types)}" accept=".${req.allowed_types.split(",").join(",.")}"
          aria-label="New file for ${escapeHtml(req.name)}">
        <div class="upload-box" data-fix-key="${key}" data-input-id="${inputId}" data-types="${escapeHtml(req.allowed_types)}" data-max-mb="${req.max_size_mb}" aria-live="polite">${uploadBoxHtml(f, f && f.file, inputId, req.allowed_types.toUpperCase().split(",").join(", "), req.max_size_mb, key)}</div>`;
    }
    // Students see the file and its status only. The automatic check column is for the admin/registrar.
    return `<tr class="${studentFixes ? "needs-fix" : ""}"><td><strong>${escapeHtml(req.name)}</strong><br><small>${escapeHtml(req.description || "")}</small></td>
      <td>${fileCell}</td>${isRegistrar ? `<td>${aiCell}</td>` : ""}<td>${reviewCell}</td></tr>`;
  }

  function actionBar(request, items) {
    if (user.role === "student") {
      return request.status === "NEEDS_CORRECTION" && request.needs_fix
        ? '<div class="detail-actions"><button type="button" class="request-next-btn" data-action="send-corrections">Send corrected documents</button></div>' : "";
    }
    if (user.role === "personnel") {
      return request.payment_status === "Unpaid" && request.status !== "REJECTED"
        ? '<div class="detail-actions"><button type="button" class="request-next-btn" data-action="mark-paid">Mark as paid</button></div>' : "";
    }
    if (user.role !== "admin") return "";

    const s = request.status;
    const remarks = '<textarea class="detail-remarks" rows="2" maxlength="500" placeholder="Message to the student (required for correction or rejection)"></textarea>';
    const button = (status, label, css = "") => `<button type="button" class="request-next-btn ${css}" data-set-status="${status}">${label}</button>`;
    let buttons = "";
    if (s === "FOR_REVIEW") {
      buttons = `<button type="button" class="request-back-btn" data-action="run-ai-all">Run AI verification on all files</button>`
        + button("PROCESSING", "Start processing") + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "PROCESSING") {
      const attach = request.release_method === "Softcopy" ? items.map(item => `
        <div class="release-row"><span>${escapeHtml(item.name)}: ${item.release_file ? escapeHtml(item.release_file.original_name) : "no softcopy yet"}</span>
        <input type="file" class="release-file" data-item-id="${item.id}" accept=".pdf,.jpg,.jpeg,.png" aria-label="Softcopy for ${escapeHtml(item.name)}">
        <button type="button" class="mini-btn" data-attach="${item.id}">Attach</button></div>`).join("") : "";
      buttons = attach + button("READY", "Mark ready for release") + button("NEEDS_CORRECTION", "Request correction", "warn") + button("REJECTED", "Reject request", "bad");
    } else if (s === "READY") {
      buttons = button("COMPLETED", "Complete (released)");
    } else if (s === "NEEDS_CORRECTION") {
      buttons = button("REJECTED", "Reject request", "bad");
    } else {
      return "";
    }
    return `<div class="detail-actions">${s === "READY" ? "" : remarks}<div class="detail-buttons">${buttons}</div></div>`;
  }

  function detailHtml(data) {
    const r = data.request;
    const note = (r.registrar_remarks && ["NEEDS_CORRECTION", "REJECTED"].includes(r.status))
      ? `<div class="notice notice-warning"><strong>Message from the school:</strong> ${escapeHtml(r.registrar_remarks)}</div>` : "";
    const releaseLinks = data.items.filter(i => i.release_file && user.role === "student" && ["READY", "COMPLETED"].includes(r.status))
      .map(i => `<a class="request-table-action" href="${fileUrl(i.release_file.id, false)}">Download ${escapeHtml(i.name)}</a>`).join(" ");
    const items = data.items.map(item => `
      <section class="detail-item">
        <h3>${escapeHtml(item.name)} <small>× ${item.quantity} · ${Portal.formatMoney(item.unit_price * item.quantity)}</small></h3>
        <div class="requests-table-wrap"><table class="detail-table">
          <thead><tr><th>Requirement</th><th>Uploaded file</th>${user.role === "admin" ? "<th>Automatic check</th>" : ""}<th>Status</th></tr></thead>
          <tbody>${item.requirements.map(req => requirementRow(r, item, req)).join("")}</tbody>
        </table></div>
      </section>`).join("");
    const history = data.history.map(h => `
      <li><strong>${escapeHtml(STATUS_LABELS[h.new_status] || h.new_status)}</strong>
        <span>${escapeHtml(Portal.formatDate(h.created_at, true))}${h.by_name ? " · " + escapeHtml(h.by_name) : " · System"}</span>
        ${h.remarks ? `<em>${escapeHtml(h.remarks)}</em>` : ""}</li>`).join("");

    return `
      <h2>Request ${escapeHtml(r.tracking_no)} ${statusPill(r.status)}</h2>
      <dl class="detail-grid">
        <div><dt>Requester</dt><dd>${escapeHtml(r.full_name)}${r.student_no ? " (" + escapeHtml(r.student_no) + ")" : ""}</dd></div>
        <div><dt>Purpose</dt><dd>${escapeHtml(r.purpose)}</dd></div>
        <div><dt>Release</dt><dd>${escapeHtml(r.release_method)} · ${escapeHtml(releaseDateLabel(r))}</dd></div>
        <div><dt>Payment</dt><dd>${Portal.formatMoney(Number(r.total_amount))} · ${escapeHtml(r.payment_method)} · ${escapeHtml(r.payment_status)}</dd></div>
      </dl>
      ${note}${releaseLinks ? `<p>${releaseLinks}</p>` : ""}
      ${items}
      ${actionBar(r, data.items)}
      <h3>History</h3><ol class="history-list">${history}</ol>`;
  }

  // ---------- Buttons inside the details window ----------
  document.addEventListener("click", async event => {
    const modalBody = event.target.closest("#modalBackdrop .modal-body");
    if (!modalBody) return;
    const requestId = Number(modalBody.dataset.requestId);
    const target = event.target;
    const refresh = async () => { await openRequestDetail(requestId); window.reloadRequests(); };

    const reviewButton = target.closest("[data-review]");
    if (reviewButton) {
      const box = reviewButton.closest(".review-controls");
      await Portal.busy(reviewButton, "Saving...", async () => {
        await Api.postJson("requests.php", {
          file_id: Number(box.dataset.fileId), decision: reviewButton.dataset.review,
          remarks: box.querySelector(".review-remarks").value
        }, { action: "review_file" });
        await refresh();
      });
      return;
    }

    const aiButton = target.closest("[data-run-ai], [data-action='run-ai-all']");
    if (aiButton) {
      await Portal.busy(aiButton, "Running AI...", async () => {
        const body = { id: requestId };
        if (aiButton.dataset.runAi) body.file_id = Number(aiButton.dataset.runAi);
        const result = await Api.postJson("requests.php", body, { action: "run_ai" });
        Portal.toast(result.summary, result.counts.UNAVAILABLE ? "warning" : "success");
        await refresh();
      });
      return;
    }

    const statusButton = target.closest("[data-set-status]");
    if (statusButton) {
      const remarks = modalBody.querySelector(".detail-remarks")?.value || "";
      await Portal.busy(statusButton, "Saving...", async () => {
        await Api.postJson("requests.php", { id: requestId, status: statusButton.dataset.setStatus, remarks }, { action: "set_status" });
        Portal.toast("Request updated.", "success");
        await refresh();
      });
      return;
    }

    const attachButton = target.closest("[data-attach]");
    if (attachButton) {
      const input = modalBody.querySelector(`.release-file[data-item-id="${attachButton.dataset.attach}"]`);
      if (!input.files[0]) { Portal.toast("Choose a file first.", "error"); return; }
      await Portal.busy(attachButton, "Uploading...", async () => {
        const form = new FormData();
        form.append("item_id", attachButton.dataset.attach);
        form.append("file", input.files[0]);
        await Api.postForm("requests.php", form, { action: "attach_release", id: requestId });
        Portal.toast("Softcopy attached.", "success");
        await refresh();
      });
      return;
    }

    const actionButton = target.closest("[data-action]");
    if (actionButton?.dataset.action === "mark-paid") {
      await Portal.busy(actionButton, "Saving...", async () => {
        await Api.postJson("requests.php", { id: requestId }, { action: "mark_paid" });
        Portal.toast("Payment recorded.", "success");
        await refresh();
      });
    }
    if (actionButton?.dataset.action === "send-corrections") {
      const inputs = [...modalBody.querySelectorAll(".fix-file")];
      if (fixesRunning > 0) { Portal.toast("Please wait while your documents are being verified.", "warning"); return; }
      const checkIds = {};
      for (const input of inputs) {
        const f = fixes.get(input.dataset.key);
        if (f && f.state === "declined") { Portal.toast("A document was declined. Please use Upload Again with a correct file.", "error", "Document Declined"); return; }
        if (!f || !isAcceptedState(f)) { Portal.toast("Please upload all documents that need correction.", "error", "Required document missing"); return; }
        checkIds[input.dataset.key] = f.checkId;
      }
      await Portal.busy(actionButton, "Sending...", async () => {
        const form = new FormData();
        form.append("payload", JSON.stringify({ checks: checkIds }));
        const result = await Api.postForm("requests.php", form, { action: "resubmit", id: requestId });
        fixes.forEach(f => { f.checkId = null; });          // the files now belong to the request
        fixes.clear();
        Portal.toast(result.message, "success", "Documents sent");
        await refresh();
      });
    }
  });

  // Closing the window without sending: the verified files that were not sent are removed from the server.
  const closeModalBase = Portal.closeModal;
  Portal.closeModal = function () { discardFixes(); closeModalBase(); };

  // ---------- Student: choosing a new file for a declined document ----------
  document.addEventListener("change", async event => {
    const input = event.target.closest("#modalBackdrop .fix-file");
    if (!input) return;
    const key = input.dataset.key;
    const file = input.files[0];
    if (!file) { fixes.delete(key); redrawFixBox(key); return; }
    const problem = await fileProblem(file, input);
    if (problem) {
      fixes.delete(key);
      input.value = "";
      redrawFixBox(key);
      Portal.toast(problem.text, "error", problem.title);
      return;
    }
    verifyFix(key, input, file);
  });
  document.addEventListener("click", event => {
    const box = event.target.closest("#modalBackdrop .upload-box[data-fix-key]");
    if (!box) return;
    const remove = event.target.closest("[data-remove]");
    const again = event.target.closest("[data-again]");
    if (!remove && !again) return;
    const key = box.dataset.fixKey;
    const input = document.getElementById(box.dataset.inputId);
    const old = fixes.get(key);
    if (old && old.checkId) Api.postJson("verify.php", { id: old.checkId }, { action: "discard" }).catch(() => {});
    fixes.delete(key);
    input.value = "";
    redrawFixBox(key);
    if (again) input.click();
  });

  // ---------- Table buttons ----------
  requestsBody.addEventListener("click", async event => {
    const view = event.target.closest("[data-view]");
    if (view) { openRequestDetail(Number(view.dataset.view)); return; }
    const pay = event.target.closest("[data-pay]");
    if (pay && window.confirm("Mark this request as paid?")) {
      await Portal.busy(pay, "Saving...", async () => {
        await Api.postJson("requests.php", { id: Number(pay.dataset.pay) }, { action: "mark_paid" });
        Portal.toast("Payment recorded.", "success");
        window.reloadRequests();
      });
    }
  });

  if (statusFilter) {
    statusFilter.innerHTML += Object.entries(STATUS_LABELS).map(([value, label]) => `<option value="${value}">${label}</option>`).join("");
    statusFilter.addEventListener("change", () => { page = 1; loadRequests(); });
  }

  // Search box also searches on the server, so older requests can be found too.
  document.getElementById("searchInput").addEventListener("input", () => {
    if (!["My Requests", "All Requests"].includes(Portal.currentPage)) return;
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => { page = 1; loadRequests(); }, 300);
  });

  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (["My Requests", "All Requests"].includes(event.detail)) loadRequests();
    if (["Dashboard", "Overview & KPIs"].includes(event.detail)) loadStats();
  });

  document.addEventListener("portal:ready", event => {
    user = event.detail;
    loadStats();
    loadRequests();
    setInterval(() => { if (!document.hidden) loadStats(); }, 60000);
  });
})();
