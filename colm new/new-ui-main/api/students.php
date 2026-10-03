<?php
// Students: list, profile, add one, edit, delete, CSV import. Also the student's own profile.
//
//   GET  ?action=profile[&id=USER_ID]     profile + submitted documents (student: own, admin: any student)
//   GET  ?action=list[&q=&course=&page=]  admin
//   GET  ?action=template                 admin - downloads the sample CSV
//   POST ?action=create                   admin - add one student
//   POST ?action=update                   admin - edit a student
//   POST ?action=delete                   admin - delete a student (only if there are no requests)
//   POST ?action=reset_password           admin - new default password, shown once
//   POST ?action=import                   admin - CSV file (field "csv"), field "dry_run" = 1 only checks the file
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/students.php';
register_error_handlers();

$user = require_login();
$action = $_GET['action'] ?? 'profile';

if ($action === 'profile') { require_method('GET'); show_profile($user); }
require_login(['admin']);                    // everything below is for the admin/registrar only

switch ($action) {
    case 'list':           require_method('GET');  list_students(); break;
    case 'template':       require_method('GET');  download_template(); break;
    case 'create':         require_method('POST'); require_csrf(); create_one(); break;
    case 'update':         require_method('POST'); require_csrf(); update_one(); break;
    case 'delete':         require_method('POST'); require_csrf(); delete_one(); break;
    case 'reset_password': require_method('POST'); require_csrf(); reset_password(); break;
    case 'import':         require_method('POST'); require_csrf(); import_csv(); break;
    default: fail('Unknown action.', 404);
}

// ---------- Profile ----------

function show_profile($user) {
    $id = $user['role'] === 'student' ? (int)$user['id'] : (int)($_GET['id'] ?? 0);
    if ($user['role'] !== 'student' && $user['role'] !== 'admin') fail('You are not allowed to do this.', 403);

    $student = fetch_one("SELECT u.id, u.username, u.full_name, u.email, u.student_no, u.is_active, u.created_at,
                                 s.course, s.year_level, s.section, s.contact_no, (s.photo_stored IS NOT NULL) AS has_photo
                          FROM users u LEFT JOIN students s ON s.user_id = u.id
                          WHERE u.id = ? AND u.role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);

    // Every document the student currently has uploaded, with its verification result.
    $documents = fetch_all("SELECT f.id AS file_id, f.request_id, f.original_name, f.review_status, f.review_remarks, f.uploaded_at,
                                   r.name AS requirement_name, d.name AS document_name, rq.tracking_no, rq.status AS request_status
                            FROM request_files f
                            JOIN requests rq ON rq.id = f.request_id
                            JOIN request_items i ON i.id = f.item_id
                            JOIN documents d ON d.id = i.document_id
                            JOIN document_requirements r ON r.id = f.requirement_id
                            WHERE rq.student_id = ? AND f.kind = 'requirement' AND f.is_current = 1
                            ORDER BY f.uploaded_at DESC, f.id DESC LIMIT 100", [$id]);
    $summary = ['total' => 0, 'pending' => 0, 'verified' => 0, 'declined' => 0];
    foreach ($documents as &$doc) {
        $doc['verification'] = verification_label($doc['review_status']);
        $summary['total']++;
        if ($doc['verification'] === 'Verified') $summary['verified']++;
        elseif ($doc['verification'] === 'Declined') $summary['declined']++;
        else $summary['pending']++;
    }
    $requests = fetch_one('SELECT COUNT(*) AS total FROM requests WHERE student_id = ?', [$id]);

    $overall = 'No documents submitted';
    if ($summary['total'] > 0) {
        $overall = $summary['declined'] > 0 ? 'Declined' : ($summary['pending'] > 0 ? 'Pending' : 'Verified');
    }
    $student['id'] = (int)$student['id'];
    $student['is_active'] = (bool)$student['is_active'];
    $student['has_photo'] = (bool)$student['has_photo'];
    json_response(['ok' => true, 'student' => $student, 'documents' => $documents, 'summary' => $summary,
                   'verification_status' => $overall, 'request_count' => (int)$requests['total']]);
}

// The three statuses the school wants to show: Pending, Verified, Declined.
function verification_label($reviewStatus) {
    if ($reviewStatus === 'Verified') return 'Verified';
    if ($reviewStatus === 'Rejected' || $reviewStatus === 'Resubmit') return 'Declined';
    return 'Pending';
}

// ---------- List ----------

function list_students() {
    $where = ["u.role = 'student'"]; $params = [];
    $search = clean_text($_GET['q'] ?? '', 60);
    if ($search !== '') {
        $where[] = '(u.full_name LIKE ? OR u.student_no LIKE ? OR u.email LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%");
    }
    $course = clean_text($_GET['course'] ?? '', 80);
    if ($course !== '') { $where[] = 's.course = ?'; $params[] = $course; }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $perPage = 25;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $offset = ($page - 1) * $perPage;
    $total = (int)fetch_one("SELECT COUNT(*) AS c FROM users u LEFT JOIN students s ON s.user_id = u.id $whereSql", $params)['c'];
    $rows = fetch_all("SELECT u.id, u.username, u.full_name, u.email, u.student_no, u.is_active, u.must_change_password,
                              s.course, s.year_level, s.section, s.contact_no,
                              (SELECT COUNT(*) FROM requests r WHERE r.student_id = u.id) AS request_count
                       FROM users u LEFT JOIN students s ON s.user_id = u.id $whereSql
                       ORDER BY u.full_name LIMIT $perPage OFFSET $offset", $params);
    foreach ($rows as &$row) {
        $row['id'] = (int)$row['id'];
        $row['is_active'] = (bool)$row['is_active'];
        $row['must_change_password'] = (bool)$row['must_change_password'];
        $row['request_count'] = (int)$row['request_count'];
    }
    $courses = array_column(fetch_all("SELECT DISTINCT course FROM students WHERE course IS NOT NULL AND course <> '' ORDER BY course"), 'course');
    json_response(['ok' => true, 'students' => $rows, 'total' => $total, 'page' => $page, 'per_page' => $perPage, 'courses' => $courses]);
}

function download_template() {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="students_template.csv"');
    echo sample_students_csv();
    exit;
}

// ---------- Add / edit / delete ----------

function student_input() {
    $input = read_json_body();
    return [
        'student_id' => $input['student_id'] ?? '', 'full_name' => $input['full_name'] ?? '', 'email' => $input['email'] ?? '',
        'course' => $input['course'] ?? '', 'year' => $input['year'] ?? '', 'section' => $input['section'] ?? '',
        'contact_number' => $input['contact_number'] ?? '', 'id' => (int)($input['id'] ?? 0),
    ];
}

function create_one() {
    $input = student_input();
    [$data, $errors] = validate_student($input);
    if (!$errors && student_id_taken($data['student_id'])) $errors[] = 'A student or account with this ID already exists.';
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    $password = make_default_password();
    $userId = create_student($data, $password);
    json_response(['ok' => true, 'id' => $userId, 'credentials' => ['username' => $data['student_id'], 'password' => $password, 'full_name' => $data['full_name']]], 201);
}

function update_one() {
    $input = student_input();
    $existing = fetch_one("SELECT id, username FROM users WHERE id = ? AND role = 'student'", [$input['id']]);
    if (!$existing) fail('Student not found.', 404);
    // The student ID is also the username, so it cannot be changed here.
    $input['student_id'] = $existing['username'];
    [$data, $errors] = validate_student($input);
    if ($errors) fail($errors[0], 422, ['errors' => $errors]);

    query('UPDATE users SET full_name = ?, email = ? WHERE id = ?', [$data['full_name'], $data['email'] !== '' ? $data['email'] : null, $existing['id']]);
    query('UPDATE students SET course = ?, year_level = ?, section = ?, contact_no = ? WHERE user_id = ?',
        [$data['course'], $data['year'], $data['section'], $data['contact_number'] !== '' ? $data['contact_number'] : null, $existing['id']]);
    json_response(['ok' => true]);
}

function delete_one() {
    $id = (int)(read_json_body()['id'] ?? 0);
    $student = fetch_one("SELECT id, full_name FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    $requests = (int)fetch_one('SELECT COUNT(*) AS c FROM requests WHERE student_id = ?', [$id])['c'];
    if ($requests > 0) fail("{$student['full_name']} has $requests document request(s). Disable the account instead of deleting it so the records are kept.", 422);

    $photo = fetch_one('SELECT photo_stored FROM students WHERE user_id = ?', [$id]);
    query('DELETE FROM users WHERE id = ?', [$id]);              // the students row and chat history are deleted with it
    if ($photo && $photo['photo_stored']) {
        require_once __DIR__ . '/../backend/files.php';
        delete_stored_file($photo['photo_stored']);
    }
    json_response(['ok' => true]);
}

function reset_password() {
    $id = (int)(read_json_body()['id'] ?? 0);
    $student = fetch_one("SELECT id, username, full_name FROM users WHERE id = ? AND role = 'student'", [$id]);
    if (!$student) fail('Student not found.', 404);
    $password = make_default_password();
    query('UPDATE users SET password_hash = ?, must_change_password = 1, failed_logins = 0, locked_until = NULL WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $id]);
    json_response(['ok' => true, 'credentials' => ['username' => $student['username'], 'password' => $password, 'full_name' => $student['full_name']]]);
}

// ---------- CSV import ----------

function import_csv() {
    $dryRun = !empty($_POST['dry_run']);
    $file = $_FILES['csv'] ?? null;
    if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) fail('Choose a CSV file first.', 422);
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) fail('The upload failed. Please try again.', 422);
    if ($file['size'] > 2 * 1024 * 1024) fail('The CSV file is too large (maximum 2 MB).', 422);
    if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'csv') fail('Please upload a .csv file.', 422);

    try {
        $rows = read_students_csv($file['tmp_name']);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), 422);
    }
    $checked = check_students_csv($rows);

    if ($dryRun) {
        json_response(['ok' => true, 'dry_run' => true, 'valid_count' => count($checked['valid']), 'failed' => $checked['failed'],
                       'preview' => array_map(fn($r) => ['line' => $r['line']] + $r['data'], array_slice($checked['valid'], 0, 10))]);
    }

    // Import every valid row. A row that fails does not stop the others.
    $imported = []; $failed = $checked['failed'];
    foreach ($checked['valid'] as $row) {
        $password = make_default_password();
        try {
            create_student($row['data'], $password);
            $imported[] = ['line' => $row['line'], 'student_id' => $row['data']['student_id'], 'full_name' => $row['data']['full_name'],
                           'username' => $row['data']['student_id'], 'password' => $password];
        } catch (Throwable $e) {
            write_log('CSV import row ' . $row['line'] . ' failed: ' . $e->getMessage());
            $failed[] = ['line' => $row['line'], 'student_id' => $row['data']['student_id'], 'full_name' => $row['data']['full_name'],
                         'errors' => ['Could not be saved (it may already exist).']];
        }
    }
    usort($failed, fn($a, $b) => $a['line'] <=> $b['line']);
    json_response(['ok' => true, 'dry_run' => false, 'imported' => $imported, 'failed' => $failed]);
}
