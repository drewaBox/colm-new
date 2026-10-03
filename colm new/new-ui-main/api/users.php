<?php
// Admin only (admin and registrar are the same kind of account).
// Student accounts are created on the Students page (api/students.php), not here.
// GET                       -> list users (optional ?q= and ?role=)
// POST ?action=create       {username, full_name, email, role, student_no, password}
// POST ?action=toggle       {id}              -> enable / disable an account
// POST ?action=reset        {id, password}    -> set a new password
require_once __DIR__ . '/../backend/auth.php';
register_error_handlers();
$admin = require_login(['admin']);
const ROLES = ['student', 'personnel', 'admin'];   // admin = admin/registrar (one type of account)

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $where = []; $params = [];
    $search = clean_text($_GET['q'] ?? '', 50);
    if ($search !== '') { $where[] = '(username LIKE ? OR full_name LIKE ? OR student_no LIKE ?)'; array_push($params, "%$search%", "%$search%", "%$search%"); }
    if (in_array($_GET['role'] ?? '', ROLES, true)) { $where[] = 'role = ?'; $params[] = $_GET['role']; }
    $users = fetch_all('SELECT id, username, full_name, email, role, student_no, is_active, created_at FROM users '
        . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY role, full_name LIMIT 200', $params);
    foreach ($users as &$u) { $u['id'] = (int)$u['id']; $u['is_active'] = (bool)$u['is_active']; }
    json_response(['ok' => true, 'users' => $users]);
}

require_method('POST');
require_csrf();
$action = $_GET['action'] ?? '';
$input = read_json_body();

if ($action === 'create') {
    $username = clean_text($input['username'] ?? '', 50);
    $fullName = clean_text($input['full_name'] ?? '', 120);
    $email = clean_text($input['email'] ?? '', 120);
    $role = $input['role'] ?? '';
    $password = (string)($input['password'] ?? '');
    $studentNo = clean_text($input['student_no'] ?? '', 30);

    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $username)) fail('Username: 3-50 letters, numbers, dot, dash or underscore.', 422);
    if ($fullName === '') fail('Please enter the full name.', 422);
    if (!in_array($role, ROLES, true) || $role === 'student') fail('Choose Admin/Registrar or Cashier. Students are added on the Students page.', 422);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('The email address is not valid.', 422);
    if (strlen($password) < 8) fail('The password must be at least 8 characters.', 422);
    $studentNo = '';

    if (fetch_one('SELECT id FROM users WHERE username = ? OR (student_no IS NOT NULL AND student_no = ?)', [$username, $studentNo])) {
        fail('That username or student number is already used.', 422);
    }
    query('INSERT INTO users (username, password_hash, full_name, email, role, student_no) VALUES (?, ?, ?, ?, ?, ?)',
        [$username, password_hash($password, PASSWORD_DEFAULT), $fullName, $email !== '' ? $email : null, $role, $studentNo !== '' ? $studentNo : null]);
    json_response(['ok' => true], 201);
}

$id = (int)($input['id'] ?? 0);
$target = fetch_one('SELECT id, is_active FROM users WHERE id = ?', [$id]);
if (!$target) fail('User not found.', 404);

if ($action === 'toggle') {
    if ($id === (int)$admin['id']) fail('You cannot disable your own account.', 422);
    query('UPDATE users SET is_active = ? WHERE id = ?', [$target['is_active'] ? 0 : 1, $id]);
    json_response(['ok' => true]);
}
if ($action === 'reset') {
    $password = (string)($input['password'] ?? '');
    if (strlen($password) < 8) fail('The password must be at least 8 characters.', 422);
    query('UPDATE users SET password_hash = ?, failed_logins = 0, locked_until = NULL WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
    json_response(['ok' => true]);
}
fail('Unknown action.', 404);
