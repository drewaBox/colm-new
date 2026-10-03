# COLM Registrar – Student Document Management System

Plain PHP + MySQL + JavaScript. No framework, no Composer, no npm.
Delete any old `registrar.html`, `admin.html`, `cashier.html` and `index.html` in `frontend/` left over from the first UI (they are not used any more).

Needs **PHP 8.0+** (extensions: `pdo_mysql`, `curl`, `fileinfo`, `mbstring`, `gd`; `intl` is optional) and **MySQL/MariaDB**. All of these are in XAMPP (enable `extension=gd` in `php.ini` if it is off).

## What it does

* **Students** log in, browse the Document Catalog, upload the required files, track requests, see the verification result of every file (**Pending / Verified / Declined – with the reason**), have a **profile** with a formal photo, and chat with the **AI Assistant**.
* **Admin / Registrar** – ONE kind of account (`role = admin`) and ONE interface (`manage.html`) with the same permissions. Only the name shown differs (the demo has an account named "Admin" and one named "Registrar"). They manage students (add, edit, delete, **CSV import**), view profiles, manage the **Catalog** (add / edit / delete / activate documents and their requirements), review documents (Approve / Decline / run AI again), process requests and manage staff accounts.
* **Cashier** (role `personnel`) only confirms payments.

## Folder guide

| Folder | What is inside |
|---|---|
| `frontend/` | `login.html`, `student.html`, `manage.html`, `css/style.css`, `js/` |
| `api/` | One PHP file per feature: `verify.php` (upload check), `ai_test.php` (admin AI test), `login.php`, `requests.php`, `documents.php`, `students.php`, `photo.php`, `chat.php`, `notifications.php`, `users.php`, `file.php`, … |
| `backend/` | Shared code: `database.php`, `auth.php`, `helpers.php`, `files.php`, `workflow.php`, `ai.php` (verification), `names.php` (name matching), `students.php` (CSV + validation) |
| `database/` | `install.sql` (tables + catalog) and `install.php` (installs **and upgrades**) |
| `storage/uploads/` | Uploaded files (blocked from the browser) |
| `students_template.csv` | Sample CSV (also downloadable on the Students page) |
| `tests/` | `smoke_test.php`, `ai_parse_test.php`, `names_test.php` |

`frontend/js/`: `api.js` (talks to the server), `auth.js` (login), `navigation.js` (menu, pop-ups), `notifications.js`, `requests.js` (request list + details + review), `newrequest.js` (student request form), `profile.js`, `chat.js`, `upload.js` (upload box + file checks), `students.js` (Students page + CSV import), `catalog.js` (Catalog management), `manage.js` (staff dashboard + accounts).

## Run on XAMPP

1. Copy this folder to `C:\xampp\htdocs\new-ui-main`, start **Apache** and **MySQL**.
2. Copy `.env.example` to `.env` (the defaults work with a fresh XAMPP).
3. `php database/install.php` – creates the database, tables, catalog and demo accounts. **It also upgrades an older database**: old `registrar` accounts become admin accounts and the new columns/tables are added. Existing data is never deleted.
4. Open `http://localhost/new-ui-main/`.

Demo accounts (development only, password `Password123!`): `2026-00001` (student), `registrar` (shown as "Registrar"), `admin` (shown as "Admin"), `cashier`.

## CSV import (Students page → Import CSV)

Columns: `student_id, full_name, email, course, year, section, contact_number` (see `students_template.csv`).
1. Choose the file and click **Check file**: nothing is saved, every row is validated (missing/invalid values, duplicate IDs in the file or already in the system) and the problems are listed with their line number.
2. Click **Import students**. Valid rows are created, invalid rows are skipped and listed.
3. Each student gets a login: **username = Student ID**, plus the default **Password123!** password that is only stored as a hash. The result window shows the credentials once and lets you download them as CSV; the student must choose a new password at the first login (the portal stays locked until then). **Reset password** on the Students page restores the default **Password123!** password.

## Document verification (what the student sees)

The student never sees the word "AI". The upload box shows **Upload your document / Choose File**; after a file is chosen it turns green with **✓ File Selected** and the exact file name, then "Verifying your document...". The result appears in the same box and as a toast:

* **Verified** (green): "Document verified successfully. You may proceed."
* **Received** (green): the basic checks passed but the system could not read the document automatically (no AI key or AI offline); the registrar will check it. The student may continue.
* **Document Declined** (red) with the reason and an **Upload Again** button. The student cannot go to the next step, and the server also refuses to create the request, while any required file is missing or declined.

Each file is sent to `api/verify.php` as soon as it is chosen (`backend/ai.php` does the work):

1. **Local checks** (PHP only, work without a key): supported type, real file content, size; pictures: too small, too dark, blank, too blurry (`BLUR_THRESHOLD`, `DARK_THRESHOLD` in `.env`); PDFs: cut off / damaged, password protected.
2. **Document reading** (only when `AI_API_KEY` is set): readable, right document, complete, required information visible, and the name printed on it.
3. **Name check done by our own code**: the printed name is compared with the account name (any word order, accents and small misreadings tolerated).

Reasons shown to students: "The uploaded image is too blurry to read.", "The document is incomplete.", "The uploaded file does not match the required document.", "The document could not be read.", "The required information could not be detected.", and the name-mismatch message. A verified file stays on the server (table `upload_checks`) until the request is submitted; when the request is created it becomes a normal request file and the check result is copied to `ai_verifications` for the registrar. Files that are removed or never submitted are deleted (and cleaned after 3 days).

The admin/registrar sees the technical details (automatic check result, name found, match %, confidence, extracted text, errors) on every file and can approve it, decline it with a reason or run the check again. The automatic check never gives final approval. Students never receive those details from the API.

## Chatbot

`api/chat.php` sends the question to the same AI service with a system prompt built on the server from the catalog, the process rules and the asking person's **own** requests and file results. Answers are shown as plain text (never as HTML). The chat history stays when the page is reloaded and can be cleared. If the AI cannot be reached the person sees "Unable to process your message. Please try again." (the admin also gets a hint such as a wrong key or model name). Test it with Settings > Test AI connection. Limits: 30 questions per hour per user; history is stored in `chat_messages` and can be cleared.

## Request flow

`SUBMITTED → AI_VERIFYING → FOR_REVIEW → PROCESSING → READY → COMPLETED`, plus `NEEDS_CORRECTION` and `REJECTED`. Allowed changes are in `backend/workflow.php`; every change is written to `request_history` and the student is notified.

## Security summary

Prepared statements everywhere, `password_hash()` / `password_verify()`, session cookies `httponly` + `samesite`, session id regenerated at login, CSRF token on every POST, roles checked in every API file (students cannot call admin APIs or open admin pages, and the pages redirect by role), login lockout after 5 wrong passwords, uploads checked by real content (not only extension), size and type limits, random file names, files outside direct web access and served only to the owner or the admin/registrar, output escaped in the browser, secrets only in `.env`.

## Deploy checklist

* `.env`: `APP_ENV=production`, real `DB_*`, `AI_API_KEY`, `INITIAL_ADMIN_PASSWORD` (10+ characters). Never commit `.env`.
* `php database/install.php` once on the server (in production it creates only one account named "Admin").
* Put `UPLOAD_DIR` outside the web folder and writable only by the web server user.
* Use HTTPS. Apache needs `AllowOverride All` for the `.htaccess` files (they hide `.env`, `backend/`, `database/`, `storage/`, `tests/`); on Nginx add equivalent `deny` rules.
* `php.ini`: `upload_max_filesize` / `post_max_size` bigger than a full request (several files of up to 3 MB each).
* If `api/` is not next to `frontend/`, change `API_URL` at the top of `frontend/js/api.js`.
* Change the demo passwords or do not install them (production does not).

## Tests

```
php tests/names_test.php
php tests/ai_parse_test.php
php tests/smoke_test.php http://localhost/new-ui-main/api/
```
Re-run `php database/install.php` after copying new files (it adds the new `upload_checks` table).
`smoke_test.php` needs the database installed, Apache running, GD enabled and an **empty `AI_API_KEY`**. It covers login and role blocking, CSRF, catalog add/edit/deactivate/delete, CSV import (valid, invalid, duplicate, wrong columns), forced password change, profile and photo, a blurry upload that is declined with the exact reason, the student's correction, admin approval, payment, completion, notifications, the chatbot (not-configured path) and staff accounts.
