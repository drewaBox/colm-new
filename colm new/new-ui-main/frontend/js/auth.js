// Login page and the login check for every portal page.
const roleNames = { student: "Student", personnel: "Cashier", registrar: "Registrar", admin: "Administrator" };

function setupLoginPage() {
  const form = document.getElementById("loginForm");
  const errorBox = document.getElementById("loginError");
  const notice = document.getElementById("loginNotice");
  const usernameInput = document.getElementById("loginUsername");
  const passwordInput = document.getElementById("loginPassword");
  const rememberMe = document.getElementById("rememberMe");
  const submitButton = form.querySelector(".login-submit");

  // "Remember me" only remembers the username (never the password).
  const savedUsername = localStorage.getItem("colmRememberedUser");
  if (savedUsername) {
    usernameInput.value = savedUsername;
    rememberMe.checked = true;
  }

  // The session ended while the person was working (timeout, logged out elsewhere).
  try {
    if (sessionStorage.getItem("colmSessionExpired")) {
      sessionStorage.removeItem("colmSessionExpired");
      notice.textContent = "Your session has ended. Please log in again.";
    }
  } catch (error) { /* storage may be off */ }

  // Already logged in? Go straight to the portal.
  Api.get("me.php").then(data => window.location.replace(data.user.home)).catch(() => {});

  document.getElementById("passwordVisibility").addEventListener("click", event => {
    const show = passwordInput.type === "password";
    passwordInput.type = show ? "text" : "password";
    event.currentTarget.textContent = show ? "visibility_off" : "visibility";
    event.currentTarget.setAttribute("aria-label", show ? "Hide password" : "Show password");
  });

  document.getElementById("forgotPassword").addEventListener("click", () => {
    errorBox.textContent = "";
    notice.textContent = "To reset your password, please visit the Registrar's Office or contact your administrator.";
  });

  form.addEventListener("submit", async event => {
    event.preventDefault();
    errorBox.textContent = "";
    notice.textContent = "";
    submitButton.disabled = true;
    submitButton.textContent = "Signing in...";
    try {
      const data = await Api.postJson("login.php", {
        username: usernameInput.value.trim(),
        password: passwordInput.value
      });
      if (rememberMe.checked) localStorage.setItem("colmRememberedUser", usernameInput.value.trim());
      else localStorage.removeItem("colmRememberedUser");
      window.location.replace(data.user.home);
    } catch (error) {
      errorBox.textContent = error.message;
      passwordInput.value = "";
      submitButton.disabled = false;
      submitButton.textContent = "Login";
    }
  });
}

async function setupPortalPage() {
  let user;
  try {
    user = (await Api.get("me.php")).user;
  } catch (error) {
    window.location.replace("login.html");
    return;
  }

  // Students use student.html, everyone else uses manage.html.
  const pageIsForStudents = document.body.dataset.portalRole === "Student";
  if ((user.role === "student") !== pageIsForStudents) {
    window.location.replace(user.home);
    return;
  }

  // Remove menu items this role is not allowed to use (the server checks the role again on every request).
  document.querySelectorAll("[data-roles]").forEach(element => {
    if (!element.dataset.roles.split(",").includes(user.role)) element.remove();
  });

  const roleName = roleNames[user.role];
  const setText = (id, text) => { const element = document.getElementById(id); if (element) element.textContent = text; };
  setText("studentName", user.full_name);
  setText("studentRole", roleName);
  setText("profileName", user.full_name);
  setText("profileRole", roleName);
  setText("profileId", user.student_no || user.username);
  setText("welcomeName", user.full_name.split(" ")[0]);
  setText("portalBrandTitle", user.role === "student" ? "STUDENT PORTAL" : "STAFF PORTAL");
  setText("portalEyebrow", roleName.toUpperCase() + " PORTAL");
  document.title = `${user.role === "student" ? "Student" : "Staff"} Portal | COLM Registrar`;

  document.getElementById("logoutBtn").addEventListener("click", async () => {
    try { await Api.postJson("logout.php"); } catch (error) { /* leave anyway */ }
    window.location.replace("login.html");
  });

  window.portalUser = user;
  document.getElementById("portalApp").hidden = false;
  document.dispatchEvent(new CustomEvent("portal:ready", { detail: user }));
}

if (document.getElementById("loginForm")) setupLoginPage();
else if (document.getElementById("portalApp")) setupPortalPage();
