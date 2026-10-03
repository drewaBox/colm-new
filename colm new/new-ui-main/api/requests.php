<?php
// All document-request actions. The action is given in the URL:  api/requests.php?action=...
//
//   GET  list, detail, stats                         (any logged-in user; students only see their own)
//   POST create, resubmit                            (student)
//   POST review_file, set_status, run_ai, attach_release   (admin = registrar)
//   POST mark_paid                                   (personnel)
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/files.php';
require_once __DIR__ . '/../backend/workflow.php';
require_once __DIR__ . '/../backend/ai.php';
register_error_handlers();

$user = require_login();
$action = $_GET['action'] ?? 'list';
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($isPost) {
    require_csrf();
    // When the upload is bigger than post_max_size in php.ini, PHP drops the whole form.
    $isMultipart = strpos($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data') === 0;
    if ($isMultipart && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('The files are too big for the server. Use smaller files or ask the admin to raise post_max_size in php.ini.', 413);
    }
}

switch ($action) {
    case 'list':   require_method('GET');  list_requests($user); break;
    case 'stats':  require_method('GET');  request_stats($user); break;
    case 'detail': require_method('GET');  request_detail($user); break;
    case 'create':   require_method('POST'); require_login(['student']);   create_request($user); break;
    case 'resubmit': require_method('POST'); require_login(['student']);   resubmit_request($user); break;
    case 'review_file':    require_method('POST'); require_login(['admin']); review_file($user); break;
    case 'set_status':     require_method('POST'); require_login(['admin']); set_request_status($user); break;
    case 'run_ai':         require_method('POST'); require_login(['admin']); run_ai_action($user); break;
    case 'attach_release': require_method('POST'); require_login(['admin']); attach_release($user); break;
    case 'mark_paid':      require_method('POST'); require_login(['personnel']); mark_paid($user); break;
    default: fail('Unknown action.', 404);
}

// ---------- Reading ----------

function list_requests($user) {
    $where = [];
    $params = [];
    if ($user['role'] === 'student') { $where[] = 'r.student_id = ?'; $params[] = $user['id']; }
    $status = $_GET['status'] ?? '';
    if ($status !== '' && isset(STATUS_LABELS[$status])) { $where[] = 'r.status = ?'; $params[] = $status; }
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(r.tracking_no LIKE ? OR u.full_name LIKE ? OR u.student_no LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $perPage = 20;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c FROM requests r JOIN users u ON u.id = r.student_id $whereSql", $params)['c'];
    $rows = fetch_all("SELECT r.id, r.tracking_no, r.status, r.payment_status, r.payment_method, r.release_method,
                              r.total_amount, r.target_start, r.target_end, r.created_at, u.full_name, u.student_no
                       FROM requests r JOIN users u ON u.id = r.student_id $whereSql
                       ORDER BY r.created_at DESC, r.id DESC LIMIT $perPage OFFSET $offset", $params);

    $items = [];
    if ($rows) {
        $ids = array_column($rows, 'id');
        $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (fetch_all("SELECT i.request_id, d.name, i.quantity, i.unit_price
                            FROM request_items i JOIN documents d ON d.id = i.document_id
                            WHERE i.request_id IN ($marks) ORDER BY i.id", $ids) as $item) {
            $items[$item['request_id']][] = ['name' => $item['name'], 'quantity' => (int)$item['quantity'],
                                             'line_total' => (float)$item['unit_price'] * $item['quantity']];
        }
    }
    $today = date('Y-m-d');
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['total_amount'] = (float)$r['total_amount'];
        $r['items'] = $items[$r['id']] ?? [];
        $r['status_label'] = STATUS_LABELS[$r['status']];
        $r['is_overdue'] = !in_array($r['status'], ['COMPLETED', 'REJECTED'], true) && $r['target_end'] && $r['target_end'] < $today;
    }
    json_response(['ok' => true, 'requests' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage]);
}

function request_stats($user) {
    $where = $user['role'] === 'student' ? 'WHERE student_id = ?' : 'WHERE 1 = ?';
    $row = fetch_one("SELECT COUNT(*) AS total,
        COALESCE(SUM(status IN ('AI_VERIFYING','FOR_REVIEW','NEEDS_CORRECTION','PROCESSING')), 0) AS processing,
        COALESCE(SUM(status = 'FOR_REVIEW'), 0) AS for_review,
        COALESCE(SUM(payment_status = 'Unpaid' AND status NOT IN ('REJECTED','COMPLETED')), 0) AS pending_payment,
        COALESCE(SUM(status = 'READY'), 0) AS ready,
        COALESCE(SUM(status = 'COMPLETED'), 0) AS completed
        FROM requests $where", [$user['role'] === 'student' ? $user['id'] : 1]);
    json_response(['ok' => true, 'stats' => array_map('intval', $row)]);
}

// Loads a request. A student can only open their own (others get 404, so ids cannot be guessed).
function load_request($id, $user) {
    $request = fetch_one('SELECT r.*, u.full_name, u.student_no FROM requests r JOIN users u ON u.id = r.student_id WHERE r.id = ?', [$id]);
    if (!$request || ($user['role'] === 'student' && (int)$request['student_id'] !== (int)$user['id'])) fail('Request not found.', 404);
    return $request;
}

function request_detail($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    $id = (int)$request['id'];
    // Only the owner student and the admin/registrar may see uploaded files.
    $canSeeFiles = $user['role'] === 'admin' || $user['role'] === 'student';
    $isAdmin = $user['role'] === 'admin';

    $files = [];
    $ai = [];
    if ($canSeeFiles) {
        foreach (fetch_all('SELECT * FROM request_files WHERE request_id = ? AND is_current = 1', [$id]) as $f) $files[] = $f;
        if ($files) {
            $marks = implode(',', array_fill(0, count($files), '?'));
            foreach (fetch_all("SELECT * FROM ai_verifications WHERE file_id IN ($marks) ORDER BY id", array_column($files, 'id')) as $a) {
                $ai[$a['file_id']] = $a; // the newest result wins because of ORDER BY id
            }
        }
    }

    $items = fetch_all('SELECT i.id, i.document_id, i.quantity, i.unit_price, d.name FROM request_items i
                        JOIN documents d ON d.id = i.document_id WHERE i.request_id = ? ORDER BY i.id', [$id]);
    $needsFix = false;
    foreach ($items as &$item) {
        $item['requirements'] = [];
        foreach (fetch_all('SELECT id, name, description, is_required, allowed_types, max_size_mb FROM document_requirements
                            WHERE document_id = ? ORDER BY sort_order, id', [$item['document_id']]) as $req) {
            $req['file'] = null;
            foreach ($files as $f) {
                if ($f['kind'] === 'requirement' && (int)$f['item_id'] === (int)$item['id'] && (int)$f['requirement_id'] === (int)$req['id']) {
                    $req['file'] = file_for_browser($f, $ai[$f['id']] ?? null, $isAdmin);
                }
            }
            $req['needs_fix'] = !$req['file'] || in_array($req['file']['review_status'], ['Rejected', 'Resubmit'], true);
            if ($req['needs_fix']) $needsFix = true;
            $item['requirements'][] = $req;
        }
        $item['release_file'] = null;
        foreach ($files as $f) {
            if ($f['kind'] === 'release' && (int)$f['item_id'] === (int)$item['id']) {
                $item['release_file'] = ['id' => (int)$f['id'], 'original_name' => $f['original_name']];
            }
        }
    }

    $history = fetch_all('SELECT h.old_status, h.new_status, h.remarks, h.created_at, u.full_name AS by_name
                          FROM request_history h LEFT JOIN users u ON u.id = h.changed_by
                          WHERE h.request_id = ? ORDER BY h.id', [$id]);
    $request['status_label'] = STATUS_LABELS[$request['status']];
    $request['can_see_files'] = $canSeeFiles;
    $request['needs_fix'] = $needsFix;
    json_response(['ok' => true, 'request' => $request, 'items' => $items, 'history' => $history]);
}

// Only send the browser what that role may see. Students get the file and its status/reason only:
// the details of the automatic check are for the admin/registrar.
function file_for_browser($f, $ai, $isAdmin) {
    $out = [
        'id' => (int)$f['id'], 'original_name' => $f['original_name'], 'mime_type' => $f['mime_type'],
        'size_bytes' => (int)$f['size_bytes'], 'review_status' => $f['review_status'],
        'review_remarks' => $f['review_remarks'], 'uploaded_at' => $f['uploaded_at'], 'ai' => null,
    ];
    if ($ai && $isAdmin) {
        $out['ai'] = [
            'status' => $ai['status'], 'reason' => $ai['reason'], 'auto_declined' => (bool)$ai['auto_declined'],
            'extracted_text' => $ai['extracted_text'], 'confidence' => $ai['confidence'],
            'name_on_document' => $ai['name_on_document'], 'name_match_score' => $ai['name_match_score'],
            'model' => $ai['model'], 'error_message' => $ai['error_message'], 'created_at' => $ai['created_at'],
        ];
    }
    return $out;
}

// ---------- Creating a request ----------
// The files were already uploaded and checked by api/verify.php. The browser sends the check ids in the "payload":
//   payload.checks = { "ROW:REQUIREMENT_ID": CHECK_ID, ... }

// A verified upload of this student that was not used yet. Returns the row or null.
function load_usable_check($userId, $checkId, $requirementId) {
    if ($checkId <= 0) return null;
    $check = fetch_one("SELECT * FROM upload_checks WHERE id = ? AND user_id = ? AND requirement_id = ? AND used_file_id IS NULL
                        AND status <> 'DECLINED' AND stored_name IS NOT NULL AND created_at > (NOW() - INTERVAL 3 DAY)",
                       [$checkId, $userId, $requirementId]);
    if ($check && !is_file(stored_file_path($check['stored_name']))) return null;
    return $check;
}

// Puts a verified upload into the request: file row, link back to the check, and the result for the registrar.
function attach_check($requestId, $itemId, $requirementId, $check) {
    $fileId = save_file_row($requestId, $itemId, $requirementId, 'requirement', $check['original_name'], $check['stored_name'],
                            ['mime' => $check['mime_type'], 'size' => $check['size_bytes'], 'sha256' => $check['sha256']]);
    query('UPDATE upload_checks SET used_file_id = ? WHERE id = ?', [$fileId, $check['id']]);
    save_ai_result($requestId, $fileId, [
        'status' => $check['result_status'], 'reason' => $check['reason'], 'extracted_text' => $check['extracted_text'],
        'confidence' => $check['confidence'], 'name_on_document' => $check['name_on_document'], 'name_match_score' => $check['name_match_score'],
        'auto_declined' => $check['auto_declined'], 'model' => $check['model'], 'error' => $check['error_message'],
    ]);
    return $fileId;
}

function create_request($user) {
    $payload = json_decode($_POST['payload'] ?? '', true);
    if (!is_array($payload)) fail('Invalid request data.', 422);

    $purpose = clean_text($payload['purpose'] ?? '', 255);
    if (mb_strlen($purpose) < 3 || !preg_match('/[A-Za-z]{2}/', $purpose)) fail('Please enter a meaningful purpose.', 422);
    $releaseMethod = $payload['release_method'] ?? '';
    $paymentMethod = $payload['payment_method'] ?? '';
    if (!in_array($releaseMethod, ['Hardcopy', 'Softcopy'], true)) fail('Choose a release method.', 422);
    if (!in_array($paymentMethod, ['Cash', 'GCash'], true)) fail('Choose a payment method.', 422);

    $rows = $payload['items'] ?? [];
    if (!is_array($rows) || !$rows || count($rows) > 15) fail('Choose at least one document.', 422);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    // 1. Check every chosen document and its quantity.
    $items = [];
    $seen = [];
    foreach ($rows as $row) {
        $documentId = (int)($row['document_id'] ?? 0);
        $quantity = (int)($row['quantity'] ?? 0);
        $rowKey = preg_replace('/[^A-Za-z0-9_-]/', '', (string)($row['row'] ?? ''));
        $doc = fetch_one('SELECT * FROM documents WHERE id = ? AND is_active = 1', [$documentId]);
        if (!$doc) fail('One of the selected documents is not available.', 422);
        if (isset($seen[$documentId])) fail('Each document can only be selected once.', 422);
        $seen[$documentId] = true;
        if ($quantity < 1 || $quantity > 5) fail('You can request 1 to 5 copies of a document.', 422);
        if (!in_array($releaseMethod, explode(',', $doc['release_methods']), true)) {
            fail("\"{$doc['name']}\" is not available as $releaseMethod. Submit it in a separate request.", 422);
        }
        $doc['requirements'] = fetch_all('SELECT * FROM document_requirements WHERE document_id = ? ORDER BY sort_order, id', [$documentId]);
        $items[] = ['row' => $rowKey, 'doc' => $doc, 'quantity' => $quantity];
    }

    // 2. Required document check: every required document must be uploaded AND have passed the verification.
    $missing = []; $errors = []; $uploads = []; $hashes = [];
    foreach ($items as $index => $item) {
        foreach ($item['doc']['requirements'] as $req) {
            $checkId = (int)($checks[$item['row'] . ':' . $req['id']] ?? 0);
            if ($checkId === 0) {
                if ($req['is_required']) $missing[] = ['document' => $item['doc']['name'], 'requirement' => $req['name']];
                continue;
            }
            $check = load_usable_check($user['id'], $checkId, (int)$req['id']);
            if (!$check) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this document has not been verified. Please upload it again.";
                continue;
            }
            if (isset($hashes[$check['sha256']])) {
                $errors[] = "{$item['doc']['name']} - {$req['name']}: this is the same file you uploaded for \"{$hashes[$check['sha256']]}\". Upload a different file.";
                continue;
            }
            $hashes[$check['sha256']] = $req['name'];
            $uploads[$index][$req['id']] = $check;
        }
    }
    if ($missing) fail('Required document missing. Please upload all required documents before continuing.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    // 3. Save everything in one transaction.
    $total = 0; $minDays = 0; $maxDays = 0;
    foreach ($items as $item) {
        $total += $item['doc']['price'] * $item['quantity'];
        $minDays = max($minDays, (int)$item['doc']['processing_min_days']);
        $maxDays = max($maxDays, (int)$item['doc']['processing_max_days']);
    }
    $pdo = db();
    try {
        $pdo->beginTransaction();
        $tracking = make_tracking_number();
        query('INSERT INTO requests (tracking_no, student_id, purpose, release_method, payment_method, total_amount, target_start, target_end)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tracking, $user['id'], $purpose, $releaseMethod, $paymentMethod, $total,
             date('Y-m-d', strtotime("+$minDays days")), date('Y-m-d', strtotime("+$maxDays days"))]);
        $requestId = (int)$pdo->lastInsertId();
        foreach ($items as $index => $item) {
            query('INSERT INTO request_items (request_id, document_id, quantity, unit_price) VALUES (?, ?, ?, ?)',
                [$requestId, $item['doc']['id'], $item['quantity'], $item['doc']['price']]);
            $itemId = (int)$pdo->lastInsertId();
            foreach ($uploads[$index] ?? [] as $requirementId => $check) attach_check($requestId, $itemId, (int)$requirementId, $check);
        }
        add_history($requestId, null, 'SUBMITTED', $user['id'], '');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();       // the verified files stay, so the student can simply try again
        write_log('create_request failed: ' . $e->getMessage());
        fail('Could not save your request. Please try again.', 500);
    }

    add_notification($user['id'], $requestId, 'Request submitted', "Your request $tracking was submitted. Your documents passed the first checks and are now waiting for the registrar.", 'success');
    finish_submission($requestId);
    notify_roles(['admin'], $requestId, 'New request for review', "Request $tracking from {$user['full_name']} is ready for review.", 'info');

    json_response(['ok' => true, 'request' => ['id' => $requestId, 'tracking_no' => $tracking, 'total' => $total, 'status' => 'FOR_REVIEW',
        'message' => 'Your documents were verified and your request is now waiting for the registrar.']], 201);
}

function make_tracking_number() {
    for ($i = 0; $i < 10; $i++) {
        $number = 'COLM-' . date('Ymd') . '-' . random_int(10000, 99999);
        if (!fetch_one('SELECT id FROM requests WHERE tracking_no = ?', [$number])) return $number;
    }
    throw new RuntimeException('Could not create a tracking number.');
}

function save_file_row($requestId, $itemId, $requirementId, $kind, $originalName, $storedName, $info) {
    $name = clean_text(basename(str_replace('\\', '/', $originalName)), 255);
    query('INSERT INTO request_files (request_id, item_id, requirement_id, kind, original_name, stored_name, mime_type, size_bytes, sha256)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$requestId, $itemId, $requirementId, $kind, $name, $storedName, $info['mime'], $info['size'], $info['sha256']]);
    return (int)db()->lastInsertId();
}

// SUBMITTED / NEEDS_CORRECTION -> "Document Checking" -> For Admin Review. The checks themselves already
// happened when the files were uploaded, so this only moves the request forward.
function finish_submission($requestId) {
    change_status($requestId, 'AI_VERIFYING', null);
    change_status($requestId, 'FOR_REVIEW', null);
}

// ---------- Student fixes the documents the registrar asked for ----------

function resubmit_request($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if ($request['status'] !== 'NEEDS_CORRECTION') fail('This request does not need correction.', 422);
    $requestId = (int)$request['id'];
    $payload = json_decode($_POST['payload'] ?? '', true);
    $checks = is_array($payload['checks'] ?? null) ? $payload['checks'] : [];

    $wanted = fetch_all("SELECT i.id AS item_id, d.name AS document_name, r.*
                         FROM request_items i
                         JOIN documents d ON d.id = i.document_id
                         JOIN document_requirements r ON r.document_id = d.id
                         LEFT JOIN request_files f ON f.item_id = i.id AND f.requirement_id = r.id AND f.is_current = 1 AND f.kind = 'requirement'
                         WHERE i.request_id = ? AND (f.id IS NULL OR f.review_status IN ('Rejected','Resubmit'))", [$requestId]);
    if (!$wanted) fail('There is nothing to correct.', 422);

    $hashes = array_column(fetch_all("SELECT sha256 FROM request_files WHERE request_id = ? AND is_current = 1", [$requestId]), 'sha256');
    $missing = []; $errors = []; $uploads = [];
    foreach ($wanted as $w) {
        $check = load_usable_check($user['id'], (int)($checks[$w['item_id'] . ':' . $w['id']] ?? 0), (int)$w['id']);
        if (!$check) { $missing[] = ['document' => $w['document_name'], 'requirement' => $w['name']]; continue; }
        if (in_array($check['sha256'], $hashes, true)) { $errors[] = "{$w['name']}: this file was already uploaded. Upload the corrected file."; continue; }
        $hashes[] = $check['sha256'];
        $uploads[] = [$w, $check];
    }
    if ($missing) fail('Required document missing. Please upload all the documents that need correction.', 422, ['missing' => $missing]);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    $pdo = db();
    try {
        $pdo->beginTransaction();
        foreach ($uploads as [$w, $check]) {
            query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND requirement_id = ? AND kind = 'requirement'", [$w['item_id'], $w['id']]);
            attach_check($requestId, (int)$w['item_id'], (int)$w['id'], $check);
        }
        add_history($requestId, 'NEEDS_CORRECTION', 'NEEDS_CORRECTION', $user['id'], 'Student uploaded corrected documents.');
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        write_log('resubmit failed: ' . $e->getMessage());
        fail('Could not save your corrections. Please try again.', 500);
    }

    finish_submission($requestId);
    add_notification($user['id'], $requestId, 'Corrections received', "Your corrected documents for {$request['tracking_no']} passed the first checks and are waiting for the registrar.", 'success');
    notify_roles(['admin'], $requestId, 'Corrections submitted', "{$user['full_name']} sent corrected documents for {$request['tracking_no']}.", 'info');
    json_response(['ok' => true, 'message' => 'Your corrected documents were verified and sent to the registrar.']);
}

// ---------- Admin / registrar actions ----------

function review_file($user) {
    $input = read_json_body();
    $decision = $input['decision'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($decision, ['Verified', 'Rejected', 'Resubmit'], true)) fail('Invalid decision.', 422);
    if ($decision !== 'Verified' && $remarks === '') fail('Please write a remark so the student knows what to fix.', 422);

    $file = fetch_one("SELECT f.id, f.request_id, r.status FROM request_files f JOIN requests r ON r.id = f.request_id
                       WHERE f.id = ? AND f.kind = 'requirement' AND f.is_current = 1", [(int)($input['file_id'] ?? 0)]);
    if (!$file) fail('File not found.', 404);
    if (!in_array($file['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('Documents can only be reviewed while the request is waiting for review or correction.', 422);

    query('UPDATE request_files SET review_status = ?, review_remarks = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?',
        [$decision, $remarks !== '' ? $remarks : null, $user['id'], $file['id']]);

    // Nothing is declined any more (for example the admin approved an AI-declined file): back to review.
    if ($file['status'] === 'NEEDS_CORRECTION') {
        $stillBad = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1
                               AND review_status IN ('Rejected','Resubmit')", [$file['request_id']])['c'];
        if ($stillBad == 0) change_status((int)$file['request_id'], 'FOR_REVIEW', $user['id'], 'All documents are accepted.');
    }
    json_response(['ok' => true]);
}

function set_request_status($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    $id = (int)$request['id'];
    $new = $input['status'] ?? '';
    $remarks = clean_text($input['remarks'] ?? '', 500);
    if (!in_array($new, ['FOR_REVIEW', 'PROCESSING', 'NEEDS_CORRECTION', 'READY', 'COMPLETED', 'REJECTED'], true)) {
        fail('That status is set by the system, not by hand.', 422);
    }
    if (in_array($new, ['NEEDS_CORRECTION', 'REJECTED'], true) && $remarks === '') fail('Please write a remark for the student.', 422);

    if ($new === 'PROCESSING') {
        $notVerified = fetch_one("SELECT COUNT(*) AS c FROM request_files WHERE request_id = ? AND kind = 'requirement' AND is_current = 1 AND review_status <> 'Verified'", [$id])['c'];
        if ($notVerified > 0) fail('Please verify every uploaded document first.', 422);
    }
    if ($new === 'READY') {
        if ($request['total_amount'] > 0 && $request['payment_status'] !== 'Paid') fail('The payment has not been confirmed yet.', 422);
        if ($request['release_method'] === 'Softcopy') {
            $without = fetch_one("SELECT COUNT(*) AS c FROM request_items i WHERE i.request_id = ?
                                  AND NOT EXISTS (SELECT 1 FROM request_files f WHERE f.item_id = i.id AND f.kind = 'release' AND f.is_current = 1)", [$id])['c'];
            if ($without > 0) fail('Attach the softcopy file for every document first.', 422);
        }
    }
    change_status($id, $new, $user['id'], $remarks);
    json_response(['ok' => true]);
}

function run_ai_action($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if (!in_array($request['status'], ['FOR_REVIEW', 'NEEDS_CORRECTION'], true)) fail('The documents can only be checked again while the request is waiting for review or correction.', 422);
    $fileIds = null;
    if (!empty($input['file_id'])) {
        $file = fetch_one("SELECT id FROM request_files WHERE id = ? AND request_id = ? AND kind = 'requirement' AND is_current = 1", [(int)$input['file_id'], $request['id']]);
        if (!$file) fail('File not found.', 404);
        $fileIds = [(int)$file['id']];
    }
    $run = run_ai_for_request((int)$request['id'], $fileIds);
    json_response(['ok' => true, 'counts' => $run['counts'], 'summary' => ai_summary_text($run)]);
}

function attach_release($user) {
    $request = load_request((int)($_GET['id'] ?? 0), $user);
    if ($request['status'] !== 'PROCESSING' || $request['release_method'] !== 'Softcopy') fail('A softcopy can only be attached while a softcopy request is processing.', 422);
    $item = fetch_one('SELECT id FROM request_items WHERE id = ? AND request_id = ?', [(int)($_POST['item_id'] ?? 0), $request['id']]);
    if (!$item) fail('Document not found.', 404);
    try {
        $file = get_uploaded('file');
        if (!$file) fail('Choose a file to attach.', 422);
        $info = validate_file($file['tmp_name'], $file['name'], 5, 'pdf,jpg,jpeg,png');
        $stored = store_file($file['tmp_name'], $info['ext']);
    } catch (RuntimeException $e) { fail($e->getMessage(), 422); }

    query("UPDATE request_files SET is_current = 0 WHERE item_id = ? AND kind = 'release'", [$item['id']]);
    save_file_row((int)$request['id'], (int)$item['id'], null, 'release', $file['name'], $stored, $info);
    json_response(['ok' => true]);
}

// ---------- Personnel action ----------

function mark_paid($user) {
    $input = read_json_body();
    $request = load_request((int)($input['id'] ?? 0), $user);
    if ($request['payment_status'] === 'Paid') fail('This request is already paid.', 422);
    if ($request['status'] === 'REJECTED') fail('A rejected request cannot be paid.', 422);
    query("UPDATE requests SET payment_status = 'Paid' WHERE id = ?", [$request['id']]);
    add_history((int)$request['id'], $request['status'], $request['status'], $user['id'], 'Payment received.');
    add_notification($request['student_id'], (int)$request['id'], 'Payment confirmed', "Payment for request {$request['tracking_no']} was received.", 'success');
    json_response(['ok' => true]);
}
