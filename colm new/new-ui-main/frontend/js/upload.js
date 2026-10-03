// Shared by the request form (newrequest.js) and the "fix my documents" window (requests.js):
// how an upload box looks in each state, and the quick checks done in the browser before a file is sent.
//
// A box state is one of:
//   undefined / no file  -> "Upload your document" + Choose File
//   { state: "checking" }                       -> green "File Selected" + filename + "Verifying your document..."
//   { state: "accepted" | "received", ... }     -> green "File Selected" + filename + "Document verified"
//   { state: "declined", message }              -> red "Document Declined" + reason + "Upload Again"

const isAcceptedState = state => !!state && (state.state === "accepted" || state.state === "received");

// key = text that identifies the box (used by the Remove button), inputId = id of the hidden <input type="file">.
function uploadBoxHtml(state, file, inputId, typesText, maxMb, key) {
  if (!file || !state) {
    return `<label class="request-requirement-dropzone" for="${inputId}">
        <span class="material-symbol request-dropzone-icon" aria-hidden="true">cloud_upload</span>
        <strong>Upload your document</strong>
        <span class="upload-choose">Choose File</span>
        <span class="request-requirement-formats">Allowed formats: ${typesText} (Max ${maxMb} MB)</span>
        <span class="request-required-file-name">Or drag the file here</span>
      </label>`;
  }
  const name = `<span class="request-required-file-name upload-filename" title="${escapeHtml(file.name)}">${escapeHtml(file.name)}</span>`;
  const selected = `<span class="upload-selected"><span class="material-symbol" aria-hidden="true">check_circle</span> ✓ File Selected</span>${name}`;
  if (state.state === "checking") {
    return `<div class="request-requirement-dropzone has-file is-checking">${selected}
      <span class="upload-status"><span class="upload-spinner" aria-hidden="true"></span> Verifying your document...</span></div>`;
  }
  if (state.state === "declined") {
    return `<div class="request-requirement-dropzone is-declined" role="alert">
      <span class="upload-declined-title"><span class="material-symbol" aria-hidden="true">cancel</span> Document Declined</span>
      ${name}
      <span class="upload-reason"><strong>Reason:</strong> ${escapeHtml(state.message)}</span>
      <button type="button" class="upload-again" data-again="${inputId}">Upload Again</button></div>`;
  }
  const note = state.state === "accepted" ? "✓ Document verified. You may proceed." : "✓ Document received. The registrar will check it.";
  return `<div class="request-requirement-dropzone has-file is-accepted">${selected}
    <span class="upload-status upload-ok">${note}</span>
    <button type="button" class="upload-remove" data-remove="${escapeHtml(key)}">Remove file</button></div>`;
}

// Quick checks in the browser (the server checks everything again). Returns null when fine,
// otherwise { title, text }. The input needs data-max-mb and data-types.
async function fileProblem(file, input) {
  if (!file.size) return { title: "Invalid File", text: "The selected file is empty." };
  const maxMb = Number(input.dataset.maxMb);
  if (file.size > maxMb * 1048576) return { title: "File too large", text: `The selected file must be ${maxMb} MB or smaller.` };
  const ext = file.name.split(".").pop().toLowerCase();
  const allowed = input.dataset.types.split(",").map(t => t.toUpperCase());
  if (!input.dataset.types.split(",").includes(ext)) {
    const list = allowed.length > 1 ? allowed.slice(0, -1).join(", ") + " or " + allowed[allowed.length - 1] : allowed[0];
    return { title: "Invalid File", text: `Please upload a supported ${list} file.` };
  }
  // Look at the first bytes of the file, not only at its name.
  const signatures = { pdf: [0x25, 0x50, 0x44, 0x46, 0x2d], jpg: [0xff, 0xd8, 0xff], jpeg: [0xff, 0xd8, 0xff], png: [0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a] };
  const bytes = new Uint8Array(await file.slice(0, 8).arrayBuffer());
  if (!signatures[ext].every((byte, i) => bytes[i] === byte)) {
    return { title: "Invalid File", text: "The file content does not match its extension. The file may be damaged." };
  }
  return null;
}

// Sends one file to api/verify.php. Returns { status: "ACCEPTED" | "RECEIVED" | "DECLINED", id, message, title }.
// Throws ApiError when the file is not allowed or the server could not be reached.
async function verifyUpload(file, requirementId, replaceCheckId) {
  const form = new FormData();
  form.append("file", file);
  form.append("requirement_id", requirementId);
  if (replaceCheckId) form.append("replace_check_id", replaceCheckId);
  return (await Api.postForm("verify.php", form, {}, 120000)).check;
}

// The text shown when verifying a file failed because of a problem (not because the document was bad).
function verifyErrorView(error) {
  const serverProblem = error.status === 0 || error.status >= 500;
  return {
    title: serverProblem ? "Verification failed" : ((error.data && error.data.title) || "Invalid File"),
    message: serverProblem ? "We could not verify your document right now. Please try again." : error.message
  };
}
