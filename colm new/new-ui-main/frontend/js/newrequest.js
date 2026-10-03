// Student only: the document catalog and the 4-step request form.
// Documents, prices, release methods and requirements all come from the database (api/documents.php).
(function () {
  const MAX_COPIES = 5;
  const docGrid = document.getElementById("docGrid");
  const requestFormPanel = document.getElementById("requestFormPanel");
  const requestsHistoryCard = document.getElementById("requestsHistoryCard");
  const form = document.getElementById("documentRequestForm");
  const documentRows = document.getElementById("requestDocumentRows");
  const requirementsList = document.getElementById("requestRequirementsList");
  const releaseSelect = document.getElementById("requestReleaseMethod");
  const paymentSelect = document.getElementById("requestPaymentMethod");
  const purposeInput = document.getElementById("requestPurpose");
  const message = document.getElementById("requestFormMessage");
  const successPanel = document.getElementById("requestSuccess");
  const submitButton = document.getElementById("submitRequestBtn");
  const steps = [...document.querySelectorAll("[data-request-step]")];
  const stepIndicators = [...document.querySelectorAll("[data-step-indicator]")];
  const PURPOSE_HINT = "e.g. Scholarship application, employment, transfer";

  let catalog = [];            // documents from the database
  let activeStep = 1;
  let latestRequest = null;
  let messageTimer = null;
  const chosenFiles = new Map();         // "rowId:requirementId" -> File
  // "rowId:requirementId" -> { state: "checking" | "accepted" | "received" | "declined", checkId, title, message, filename }
  const checks = new Map();
  let checksRunning = 0;                 // how many files are being verified right now
  const reviewedRows = new Set();        // rows where the student ticked "I have reviewed these requirements"

  // ---------- Catalog ----------
  function processingText(doc) {
    if (doc.processing_max_days === 0) return "Same day";
    return doc.processing_min_days === doc.processing_max_days
      ? `${doc.processing_min_days} days` : `${doc.processing_min_days}-${doc.processing_max_days} days`;
  }

  function releaseDateFor(doc) {
    const add = days => { const d = new Date(); d.setHours(12, 0, 0, 0); d.setDate(d.getDate() + days); return d; };
    const start = add(doc.processing_min_days), end = add(doc.processing_max_days);
    const label = start.getTime() === end.getTime()
      ? Portal.formatDate(start.toISOString()) : `${Portal.formatDate(start.toISOString())} – ${Portal.formatDate(end.toISOString())}`;
    return { start, end, label };
  }

  function renderCatalog() {
    docGrid.innerHTML = catalog.length ? catalog.map(doc => `
      <div class="doc-item">
        <div class="doc-card">
          <h3>${escapeHtml(doc.name)}</h3>
          <span class="doc-price">${doc.price}</span>
          <p class="doc-desc">${escapeHtml(doc.description || "")}</p>
          <button class="doc-request-btn" type="button" data-document-id="${doc.id}">Select Document</button>
        </div>
        <div class="doc-card-meta">
          <p><strong>Processing Days:</strong> ${escapeHtml(processingText(doc))}</p>
          <p class="meta-label"><strong>Requirements:</strong></p>
          ${doc.requirements.map(req => `<p class="meta-check"><span class="material-symbol">check_circle</span> ${escapeHtml(req.name)}</p>`).join("")}
          <p class="meta-label"><strong>Available Released Method:</strong></p>
          ${doc.release_methods.map(method => `<p class="meta-check"><span class="material-symbol">check_circle</span> ${escapeHtml(method)}</p>`).join("")}
        </div>
      </div>`).join("") : '<p class="requests-empty">No documents are available right now.</p>';
    document.dispatchEvent(new CustomEvent("table:rendered"));
  }

  docGrid.addEventListener("click", event => {
    const button = event.target.closest("[data-document-id]");
    if (button) startRequest(button.dataset.documentId);
  });

  // ---------- Messages and steps ----------
  function showMessage(text) {
    message.setAttribute("role", "alert");
    message.textContent = text;
    clearTimeout(messageTimer);
    messageTimer = setTimeout(clearMessage, 8000);
  }
  function clearMessage() {
    clearTimeout(messageTimer);
    message.setAttribute("role", "status");
    message.textContent = "";
  }
  function showStep(step) {
    activeStep = step;
    steps.forEach(section => { section.hidden = Number(section.dataset.requestStep) !== step; });
    stepIndicators.forEach(indicator => {
      const number = Number(indicator.dataset.stepIndicator);
      indicator.classList.toggle("active", number === step);
      indicator.classList.toggle("complete", number < step);
    });
    clearMessage();
  }

  // ---------- Document rows (step 1) ----------
  function chosenDocuments() {
    return [...documentRows.querySelectorAll(".request-document-row")].flatMap(row => {
      const doc = catalog.find(item => item.id === Number(row.querySelector(".request-document-select").value));
      const quantity = Number(row.querySelector(".request-quantity").value);
      if (!doc || !Number.isInteger(quantity) || quantity < 1 || quantity > MAX_COPIES) return [];
      return [{ ...doc, rowId: row.dataset.rowId, quantity, lineTotal: doc.price * quantity, release: releaseDateFor(doc) }];
    });
  }

  function addDocumentRow(documentId = "") {
    const row = document.createElement("div");
    const id = crypto.randomUUID();
    row.className = "request-document-row";
    row.dataset.rowId = id;
    row.innerHTML = `
      <div class="request-field request-document-field">
        <label for="doc-${id}">Requested document</label>
        <select class="request-document-select" id="doc-${id}" required>
          <option value="">-- Choose a document --</option>
          ${catalog.map(doc => `<option value="${doc.id}">${escapeHtml(doc.name)}</option>`).join("")}
        </select>
      </div>
      <div class="request-field request-quantity-field">
        <label for="qty-${id}">Number of copies</label>
        <input class="request-quantity" id="qty-${id}" type="number" min="1" max="${MAX_COPIES}" step="1" value="1" required>
      </div>
      <div class="request-row-meta">
        <div><span>Unit price</span><strong class="request-unit-price"></strong></div>
        <div><span>Date Release</span><strong class="request-row-release">Choose a document</strong></div>
        <div><span>Fee assessment</span><strong class="request-line-total"></strong></div>
      </div>
      <button class="request-remove-btn" type="button" aria-label="Remove document"><span class="material-symbol">delete</span></button>`;
    row.querySelector(".request-document-select").value = documentId;
    documentRows.append(row);
    updateSummary();
  }

  function updateSummary() {
    const picked = new Set([...documentRows.querySelectorAll(".request-document-select")].map(s => s.value).filter(Boolean));
    documentRows.querySelectorAll(".request-document-row").forEach(row => {
      const select = row.querySelector(".request-document-select");
      [...select.options].forEach(option => { option.disabled = !!option.value && option.value !== select.value && picked.has(option.value); });
      const doc = catalog.find(item => item.id === Number(select.value));
      const quantity = Number(row.querySelector(".request-quantity").value) || 0;
      row.querySelector(".request-unit-price").textContent = doc ? Portal.formatMoney(doc.price) : "";
      row.querySelector(".request-row-release").textContent = doc ? releaseDateFor(doc).label : "Choose a document";
      row.querySelector(".request-line-total").textContent = doc && quantity > 0 ? Portal.formatMoney(doc.price * quantity) : "";
    });
    const items = chosenDocuments();
    document.getElementById("requestTotal").textContent = Portal.formatMoney(items.reduce((sum, item) => sum + item.lineTotal, 0));

    // Release methods that every chosen document offers
    const common = items.length ? items[0].release_methods.filter(m => items.every(i => i.release_methods.includes(m))) : [];
    const current = releaseSelect.value;
    releaseSelect.innerHTML = common.length ? common.map(m => `<option value="${m}">${m}</option>`).join("") : '<option value="">Choose a document first</option>';
    releaseSelect.disabled = common.length === 0;
    if (common.includes(current)) releaseSelect.value = current;
    releaseSelect.parentElement.hidden = common.length === 1;

    document.getElementById("dateReleaseDisplay").textContent = releaseLabel(items);
    renderRequirements(items);
  }

  function releaseLabel(items) {
    if (!items.length) return "Choose a document first";
    const first = new Date(Math.min(...items.map(i => i.release.start.getTime())));
    const last = new Date(Math.max(...items.map(i => i.release.end.getTime())));
    return first.getTime() === last.getTime() ? Portal.formatDate(first.toISOString())
      : `${Portal.formatDate(first.toISOString())} – ${Portal.formatDate(last.toISOString())}`;
  }

  // ---------- Requirements and uploads (step 3) ----------
  const fileKey = (rowId, requirementId) => `${rowId}:${requirementId}`;

  function renderRequirements(items) {
    requirementsList.innerHTML = items.map(item => `
      <section class="request-requirement-group">
        <h4>${escapeHtml(item.name)}</h4>
        <ul>${item.requirements.map(req => {
          const key = fileKey(item.rowId, req.id);
          const inputId = `file-${item.rowId}-${req.id}`;
          const file = chosenFiles.get(key);
          const types = req.allowed_types.split(",").map(t => t.toUpperCase()).join(", ");
          return `<li class="request-requirement-item">
            <div class="request-requirement-heading"><strong>${escapeHtml(req.name)}</strong>
              <span class="request-required-badge">${req.is_required ? "Required" : "Optional"}</span></div>
            <p class="request-requirement-description">${escapeHtml(req.description || "Upload a file that satisfies this requirement.")}</p>
            <input class="request-required-file" id="${inputId}" type="file" data-key="${escapeHtml(key)}" data-req-id="${req.id}"
              data-max-mb="${req.max_size_mb}" data-types="${escapeHtml(req.allowed_types)}" accept=".${req.allowed_types.split(",").join(",.")}"
              aria-label="Upload ${escapeHtml(req.name)} for ${escapeHtml(item.name)}">
            <div class="upload-box" data-input-id="${inputId}" data-key="${escapeHtml(key)}" data-types="${escapeHtml(req.allowed_types)}" data-max-mb="${req.max_size_mb}" aria-live="polite">${uploadBoxHtml(checks.get(key), chosenFiles.get(key), inputId, types, req.max_size_mb, key)}</div>
            </li>`;
        }).join("")}</ul>
        <label class="request-confirm-option"><input type="checkbox" class="request-requirement-confirm" data-row-id="${escapeHtml(item.rowId)}"
          ${reviewedRows.has(item.rowId) ? "checked" : ""}> I have reviewed these requirements.</label>
      </section>`).join("") || '<p class="request-date-note">Choose documents in Step 1 to see their requirements.</p>';
  }

  function redrawUploadBox(key) {
    const box = [...requirementsList.querySelectorAll(".upload-box")].find(b => b.dataset.key === key);
    if (!box) return;
    const types = box.dataset.types.split(",").map(t => t.toUpperCase()).join(", ");
    box.innerHTML = uploadBoxHtml(checks.get(key), chosenFiles.get(key), box.dataset.inputId, types, box.dataset.maxMb, key);
  }

  function updateNextButtons() {
    document.querySelectorAll("[data-next-step]").forEach(button => {
      if (Number(button.dataset.nextStep) < 4) return;
      button.disabled = checksRunning > 0;
      button.title = checksRunning > 0 ? "Please wait while your documents are being verified." : "";
    });
  }

  function forgetFile(key, input) {
    const old = checks.get(key);
    if (old && old.checkId) Api.postJson("verify.php", { id: old.checkId }, { action: "discard" }).catch(() => {});
    chosenFiles.delete(key);
    checks.delete(key);
    if (input) input.value = "";
  }

  // Sends the file to the server for verification. The result decides if the student can continue.
  async function verifyFile(key, input, file) {
    const old = checks.get(key);
    chosenFiles.set(key, file);
    checks.set(key, { state: "checking" });
    redrawUploadBox(key);
    checksRunning++;
    updateNextButtons();

    try {
      const result = await verifyUpload(file, input.dataset.reqId, old && old.checkId);
      if (chosenFiles.get(key) !== file) {                             // the student picked another file meanwhile
        if (result.id) Api.postJson("verify.php", { id: result.id }, { action: "discard" }).catch(() => {});
        return;
      }
      if (result.status === "DECLINED") {
        checks.set(key, { state: "declined", message: result.message });
        Portal.toast(result.message, "error", "Document Declined");
      } else {
        checks.set(key, { state: result.status === "ACCEPTED" ? "accepted" : "received", checkId: result.id, message: result.message });
        Portal.toast(result.message, "success", result.status === "ACCEPTED" ? "Document verified" : "Document received");
      }
    } catch (error) {
      if (chosenFiles.get(key) !== file) return;
      const view = verifyErrorView(error);
      checks.set(key, { state: "declined", message: view.message });
      Portal.toast(view.message, "error", view.title);
    } finally {
      checksRunning = Math.max(0, checksRunning - 1);
      updateNextButtons();
      redrawUploadBox(key);
    }
  }

  // Which required documents are still missing or not yet accepted? Returns a list of names.
  function missingRequirements(items) {
    return items.flatMap(item => item.requirements
      .filter(req => req.is_required && !isAccepted(fileKey(item.rowId, req.id)))
      .map(req => `${item.name} - ${req.name}`));
  }
  const isAccepted = key => isAcceptedState(checks.get(key));

  // ---------- Moving between steps ----------
  function purposeProblem(value) {
    const purpose = value.trim();
    if (purpose.length < 3) return "Please enter a purpose with at least 3 characters.";
    const letters = purpose.match(/[a-z]/gi) || [];
    const vowels = purpose.match(/[aeiou]/gi) || [];
    if (!letters.length || /(.)\1{4,}/i.test(purpose) || (letters.length >= 8 && vowels.length / letters.length < 0.16)) {
      return "Please enter a meaningful purpose, not random characters.";
    }
    return "";
  }

  function goToStep(next) {
    const selects = [...documentRows.querySelectorAll(".request-document-select")];
    const items = chosenDocuments();
    if (!items.length || items.length !== selects.length) { showStep(1); showMessage("Choose a document and a valid number of copies (1 to 5) for every row."); return; }
    if (next >= 2 && !releaseSelect.value) { showStep(1); showMessage("The selected documents do not share a release method. Submit them in separate requests."); return; }
    if (next >= 3) {
      const problem = purposeProblem(purposeInput.value);
      if (problem) { showStep(2); purposeInput.setAttribute("aria-invalid", "true"); purposeInput.focus(); showMessage(problem); return; }
      purposeInput.removeAttribute("aria-invalid");
    }
    if (next >= 4) {
      if (checksRunning > 0) { showStep(3); showMessage("Please wait while your documents are being verified."); return; }
      const declined = items.flatMap(item => item.requirements.filter(req => checks.get(fileKey(item.rowId, req.id))?.state === "declined").map(req => `${item.name} - ${req.name}`));
      if (declined.length) {
        showStep(3);
        showMessage(`Document Declined: ${declined[0]}. Please use Upload Again with a correct file before continuing.`);
        return;
      }
      const missing = missingRequirements(items);
      if (missing.length) {
        showStep(3);
        showMessage(`Required document missing: ${missing[0]}${missing.length > 1 ? ` (and ${missing.length - 1} more)` : ""}. Please upload all required documents before continuing.`);
        return;
      }
      if (!items.every(item => reviewedRows.has(item.rowId))) {
        showStep(3);
        showMessage("Confirm that you have reviewed the listed requirements for every selected document.");
        return;
      }
    }
    if (next === 4) fillReview(items);
    showStep(next);
  }

  function fillReview(items) {
    const fileNames = items.flatMap(item => item.requirements
      .map(req => { const key = fileKey(item.rowId, req.id); const file = chosenFiles.get(key); return file && isAccepted(key) ? `${item.name} - ${req.name}: ${file.name}` : null; })
      .filter(Boolean));
    document.getElementById("reviewPurpose").textContent = purposeInput.value.trim();
    document.getElementById("reviewReleaseMethod").textContent = releaseSelect.value;
    document.getElementById("reviewPaymentMethod").textContent = paymentSelect.value;
    document.getElementById("reviewReleaseDate").textContent = releaseLabel(items);
    document.getElementById("reviewAttachments").textContent = fileNames.join(", ") || "None";
    document.getElementById("requestReviewRows").innerHTML = items.map(item => `
      <tr><td>${escapeHtml(item.name)}</td><td>${item.quantity}</td><td>${Portal.formatMoney(item.price)}</td>
      <td>${Portal.formatMoney(item.lineTotal)}</td><td>${escapeHtml(item.release.label)}</td></tr>`).join("");
    document.getElementById("reviewTotal").textContent = Portal.formatMoney(items.reduce((sum, item) => sum + item.lineTotal, 0));
  }

  function startRequest(documentId = "") {
    Portal.goToPage("My Requests");
    requestFormPanel.hidden = false;
    requestsHistoryCard.hidden = true;
    successPanel.hidden = true;
    form.hidden = false;
    form.reset();
    discardUnusedChecks();
    chosenFiles.clear();
    checks.clear();
    reviewedRows.clear();
    updateNextButtons();
    purposeInput.placeholder = PURPOSE_HINT;
    purposeInput.removeAttribute("aria-invalid");
    documentRows.replaceChildren();
    addDocumentRow(documentId);
    showStep(1);
  }

  // Files that were verified but never submitted are removed from the server.
  function discardUnusedChecks() {
    checks.forEach(state => {
      if (state.checkId) Api.postJson("verify.php", { id: state.checkId }, { action: "discard" }).catch(() => {});
    });
  }

  function closeForm() {
    discardUnusedChecks();
    chosenFiles.clear();
    checks.clear();
    requestFormPanel.hidden = true;
    requestsHistoryCard.hidden = false;
    successPanel.hidden = true;
    form.hidden = false;
  }

  // ---------- Submit ----------
  form.addEventListener("submit", async event => {
    event.preventDefault();
    if (activeStep !== 4) return;
    const items = chosenDocuments();
    const missing = missingRequirements(items);
    if (missing.length) { showStep(3); showMessage("Required document missing. Please upload all required documents before continuing."); return; }

    if (checksRunning > 0) { showMessage("Please wait while your documents are being verified."); return; }
    const checkIds = {};
    items.forEach(item => item.requirements.forEach(req => {
      const state = checks.get(fileKey(item.rowId, req.id));
      if (state && state.checkId && isAccepted(fileKey(item.rowId, req.id))) checkIds[`${item.rowId}:${req.id}`] = state.checkId;
    }));
    const payload = {
      purpose: purposeInput.value.trim(),
      release_method: releaseSelect.value,
      payment_method: paymentSelect.value,
      items: items.map(item => ({ row: item.rowId, document_id: item.id, quantity: item.quantity })),
      checks: checkIds
    };
    const formData = new FormData();
    formData.append("payload", JSON.stringify(payload));

    await Portal.busy(submitButton, "Submitting...", async () => {
      try {
        const result = (await Api.postForm("requests.php", formData, { action: "create" })).request;
        latestRequest = { tracking: result.tracking_no, submittedAt: new Date().toISOString(),
          items: items.map(i => ({ name: i.name, price: i.price, quantity: i.quantity, release: i.release.label })) };
        document.getElementById("requestTrackingNumber").textContent = result.tracking_no;
        document.getElementById("successTotal").textContent = Portal.formatMoney(result.total);
        document.getElementById("requestSuccessText").textContent = result.message + " Pay at the registrar office. You will get a notification for every update.";
        checks.forEach(state => { state.checkId = null; });             // the files now belong to the request
        chosenFiles.clear();
        checks.clear();
        form.hidden = true;
        successPanel.hidden = false;
        showStep(5);
        renderQrCode();
        window.reloadRequests();
      } catch (error) {
        showMessage(error.message);
        Portal.toast(error.message, "error", "Request not submitted");
        if (error.data && (error.data.missing || error.data.errors)) showStep(3);
      }
    });
  });

  // ---------- QR code of the tracking number (valid 7 days) ----------
  function renderQrCode() {
    const box = document.getElementById("requestTrackingQrCode");
    const status = document.getElementById("requestQrStatus");
    const download = document.getElementById("downloadTrackingQr");
    const expires = new Date(new Date(latestRequest.submittedAt).getTime() + 7 * 24 * 3600 * 1000);
    box.replaceChildren();
    download.disabled = true;
    if (typeof window.QRCode !== "function") { status.textContent = "QR generation is unavailable. Keep your tracking number above."; return; }
    const text = [`Tracking Number: ${latestRequest.tracking}`, `QR Valid Until: ${expires.toISOString()}`,
      ...latestRequest.items.map(i => `${i.name} | ${i.release} | PHP ${i.price} | ${i.quantity} copies`)].join("\n");
    try {
      new window.QRCode(box, { text, width: 220, height: 220, colorDark: "#123c2d", colorLight: "#ffffff", correctLevel: window.QRCode.CorrectLevel.M });
      download.disabled = false;
      status.textContent = `Valid until ${Portal.formatDate(expires.toISOString())}. Scan to read the request details.`;
    } catch (error) {
      status.textContent = "QR generation failed. Keep your tracking number above.";
    }
  }

  document.getElementById("downloadTrackingQr").addEventListener("click", () => {
    const canvas = document.querySelector("#requestTrackingQrCode canvas");
    if (!canvas || !latestRequest) return;
    const link = document.createElement("a");
    link.download = `${latestRequest.tracking}-QR.png`;
    link.href = canvas.toDataURL("image/png");
    link.click();
  });

  document.getElementById("copyTrackingNumber").addEventListener("click", async event => {
    if (!latestRequest) return;
    try { await navigator.clipboard.writeText(latestRequest.tracking); event.currentTarget.textContent = "Copied"; }
    catch (error) { event.currentTarget.textContent = latestRequest.tracking; }
  });

  // ---------- Events ----------
  document.getElementById("addDocumentRow").addEventListener("click", () => addDocumentRow());
  document.getElementById("startNewRequestBtn").addEventListener("click", () => startRequest());
  document.getElementById("cancelRequestForm").addEventListener("click", closeForm);
  document.getElementById("viewMyRequests").addEventListener("click", closeForm);
  document.getElementById("requestAgainBtn")?.addEventListener("click", () => startRequest());
  document.querySelectorAll("[data-next-step]").forEach(b => b.addEventListener("click", () => goToStep(Number(b.dataset.nextStep))));
  document.querySelectorAll("[data-previous-step]").forEach(b => b.addEventListener("click", () => showStep(Number(b.dataset.previousStep))));

  documentRows.addEventListener("change", event => { if (event.target.matches(".request-document-select, .request-quantity")) updateSummary(); });
  documentRows.addEventListener("input", event => { if (event.target.matches(".request-quantity")) updateSummary(); });
  documentRows.addEventListener("click", event => {
    const remove = event.target.closest(".request-remove-btn");
    if (!remove) return;
    const row = remove.closest(".request-document-row");
    if (documentRows.children.length === 1) {
      row.querySelector(".request-document-select").value = "";
      row.querySelector(".request-quantity").value = "1";
    } else {
      row.remove();
    }
    updateSummary();
  });

  requirementsList.addEventListener("change", async event => {
    const input = event.target.closest(".request-required-file");
    if (input) {
      const key = input.dataset.key;
      const file = input.files[0];
      if (!file) { forgetFile(key, input); redrawUploadBox(key); return; }          // the file dialog was cancelled
      const problem = await fileProblem(file, input);
      if (problem) {
        forgetFile(key, input);
        redrawUploadBox(key);
        Portal.toast(problem.text, "error", problem.title);
        showMessage(problem.text);
        return;
      }
      // The same file cannot be used for two requirements.
      for (const [otherKey, other] of chosenFiles) {
        if (otherKey !== key && other.name === file.name && other.size === file.size && other.lastModified === file.lastModified) {
          input.value = "";
          Portal.toast("You already chose this file for another requirement. Upload a different file.", "error", "Duplicate file");
          return;
        }
      }
      clearMessage();
      verifyFile(key, input, file);
      return;
    }
    const confirm = event.target.closest(".request-requirement-confirm");
    if (confirm) { if (confirm.checked) reviewedRows.add(confirm.dataset.rowId); else reviewedRows.delete(confirm.dataset.rowId); }
  });

  // "Remove file" and "Upload Again"
  requirementsList.addEventListener("click", event => {
    const remove = event.target.closest("[data-remove]");
    if (remove) {
      const key = remove.dataset.remove;
      const input = requirementsList.querySelector(`.request-required-file[data-key="${CSS.escape(key)}"]`);
      forgetFile(key, input);
      redrawUploadBox(key);
      return;
    }
    const again = event.target.closest("[data-again]");
    if (again) {
      const input = document.getElementById(again.dataset.again);
      forgetFile(input.dataset.key, input);
      redrawUploadBox(input.dataset.key);
      input.click();
    }
  });

  requirementsList.addEventListener("dragover", event => {
    const zone = event.target.closest(".upload-box");
    if (zone) { event.preventDefault(); zone.classList.add("drag-active"); }
  });
  requirementsList.addEventListener("dragleave", event => {
    const zone = event.target.closest(".upload-box");
    if (zone && !zone.contains(event.relatedTarget)) zone.classList.remove("drag-active");
  });
  requirementsList.addEventListener("drop", event => {
    const zone = event.target.closest(".upload-box");
    if (!zone) return;
    event.preventDefault();
    zone.classList.remove("drag-active");
    const file = event.dataTransfer?.files?.[0];
    const input = document.getElementById(zone.dataset.inputId);
    if (!file || !input) return;
    const transfer = new DataTransfer();
    transfer.items.add(file);
    input.files = transfer.files;
    input.dispatchEvent(new Event("change", { bubbles: true }));
  });

  document.addEventListener("portal:ready", async () => {
    try {
      catalog = (await Api.get("documents.php")).documents;
      renderCatalog();
    } catch (error) {
      docGrid.innerHTML = `<p class="requests-empty">${escapeHtml(error.message)}</p>`;
    }
  });
})();
