// Staff pages (admin/registrar and cashier): work queue and staff accounts.
(function () {
  let user = null;

  // ---------- Dashboard: requests that need this person's attention ----------
  async function loadQueue() {
    const body = document.getElementById("queueBody");
    const hint = document.getElementById("queueHint");
    // The registrar looks at requests waiting for review; personnel at unpaid ones; admin at everything recent.
    const wanted = {
      admin: { params: { status: "FOR_REVIEW" }, text: "Requests waiting for review." },
      personnel: { params: {}, text: "Latest requests. Open one to confirm payment.", unpaidOnly: true }
    }[user.role];
    try {
      const data = await Api.get("requests.php", { action: "list", ...wanted.params });
      let rows = data.requests;
      if (wanted.unpaidOnly) rows = rows.filter(r => r.payment_status === "Unpaid" && r.status !== "REJECTED");
      hint.textContent = wanted.text;
      body.innerHTML = rows.length ? rows.slice(0, 8).map(r => `
        <tr><td>${escapeHtml(r.tracking_no)}</td><td>${escapeHtml(r.full_name)}</td>
        <td>${r.items.map(i => escapeHtml(i.name)).join("<br>")}</td><td>${window.statusPill(r.status)}</td>
        <td><button class="request-table-action" type="button" data-open="${r.id}">${user.role === "admin" ? "Review" : "View"}</button></td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">Nothing is waiting right now.</td></tr>';
    } catch (error) {
      body.innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }
  document.getElementById("queueBody").addEventListener("click", event => {
    const button = event.target.closest("[data-open]");
    if (button) window.openRequestDetail(Number(button.dataset.open));
  });

  // ---------- Staff accounts (admin/registrar) ----------
  const staffRoleLabel = role => (role === "admin" ? "Admin / Registrar" : role === "personnel" ? "Cashier" : role);
  const usersBody = document.getElementById("usersBody");
  const roleFilter = document.getElementById("userRoleFilter");

  async function loadUsers() {
    if (!usersBody) return;
    const params = {};
    if (roleFilter.value) params.role = roleFilter.value;
    const search = document.getElementById("searchInput").value.trim();
    if (search) params.q = search;
    try {
      const users = (await Api.get("users.php", params)).users;
      usersBody.innerHTML = users.length ? users.map(u => `
        <tr><td>${escapeHtml(u.username)}</td><td>${escapeHtml(u.full_name)}</td><td>${escapeHtml(staffRoleLabel(u.role))}</td>
        <td>${u.is_active ? "Active" : "Disabled"}</td>
        <td><button class="request-table-action" type="button" data-toggle-user="${u.id}">${u.is_active ? "Disable" : "Enable"}</button>
            <button class="request-table-action" type="button" data-reset-user="${u.id}" data-name="${escapeHtml(u.full_name)}">Reset password</button></td></tr>`).join("")
        : '<tr><td colspan="5" class="requests-empty">No users found.</td></tr>';
      document.dispatchEvent(new CustomEvent("table:rendered"));
    } catch (error) {
      usersBody.innerHTML = `<tr><td colspan="5" class="requests-empty">${escapeHtml(error.message)}</td></tr>`;
    }
  }

  document.getElementById("userForm")?.addEventListener("submit", async event => {
    event.preventDefault();
    const message = document.getElementById("userMessage");
    message.className = "form-message";
    const button = event.currentTarget.querySelector("button[type=submit]");
    await Portal.busy(button, "Creating...", async () => {
      try {
        await Api.postJson("users.php", {
          username: document.getElementById("newUsername").value,
          full_name: document.getElementById("newFullName").value,
          email: document.getElementById("newEmail").value,
          role: document.getElementById("newRole").value,
          password: document.getElementById("newPasswordUser").value
        }, { action: "create" });
        event.currentTarget.reset();
        message.textContent = "User created.";
        message.classList.add("success");
        loadUsers();
      } catch (error) {
        message.textContent = error.message;
        message.classList.add("error");
      }
    });
  });

  usersBody?.addEventListener("click", async event => {
    const toggle = event.target.closest("[data-toggle-user]");
    if (toggle) {
      await Portal.busy(toggle, "Saving...", async () => {
        await Api.postJson("users.php", { id: Number(toggle.dataset.toggleUser) }, { action: "toggle" });
        loadUsers();
      });
      return;
    }
    const reset = event.target.closest("[data-reset-user]");
    if (reset) {
      const password = window.prompt(`New password for ${reset.dataset.name} (at least 8 characters):`);
      if (!password) return;
      await Portal.busy(reset, "Saving...", async () => {
        await Api.postJson("users.php", { id: Number(reset.dataset.resetUser), password }, { action: "reset" });
        Portal.toast("Password changed.", "success");
      });
    }
  });
  roleFilter?.addEventListener("change", loadUsers);

  document.getElementById("searchInput").addEventListener("input", () => {
    if (Portal.currentPage === "Accounts") loadUsers();
  });

  document.addEventListener("page:change", event => {
    user = window.portalUser;
    if (event.detail === "Overview & KPIs") loadQueue();
    if (event.detail === "Accounts") loadUsers();
  });
  document.addEventListener("portal:ready", event => { user = event.detail; });
  // ---------- Settings: test the AI connection ----------
  document.getElementById("aiTestBtn")?.addEventListener("click", async event => {
    const message = document.getElementById("aiTestMessage");
    message.className = "form-message";
    message.textContent = "Testing...";
    await Portal.busy(event.currentTarget, "Testing...", async () => {
      try {
        const result = await Api.postJson("ai_test.php", {}, {}, 40000);
        const s = result.status;
        const extras = `Key set: ${s.key_set ? "yes" : "no"} · Model: ${s.model} · Image checks (GD): ${s.gd ? "on" : "off"} · curl: ${s.curl ? "on" : "off"}`;
        message.textContent = `${result.message} (${extras})`;
        message.classList.add(result.connected ? "success" : "error");
      } catch (error) {
        message.textContent = error.message;
        message.classList.add("error");
      }
    });
  });

  // Refresh the work queue after a request changed.
  document.addEventListener("requests:changed", () => {
    if (Portal.currentPage === "Overview & KPIs") loadQueue();
  });
})();
