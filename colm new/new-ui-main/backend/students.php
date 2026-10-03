<?php
// Student accounts: creating one student, checking and importing a CSV file, default passwords.
require_once __DIR__ . '/database.php';

const CSV_COLUMNS = ['student_id', 'full_name', 'email', 'course', 'year', 'section', 'contact_number'];
const CSV_MAX_ROWS = 1000;

// Default password used for newly created student accounts.
function make_default_password() {
    return 'Password123!';
}

// "3", "3rd", "3rd Year", "Year 3" -> 3. Returns null if it is not a year 1-6.
function parse_year_level($value) {
    if (preg_match('/\b([1-6])\b|\b([1-6])(st|nd|rd|th)\b/i', (string)$value, $m)) return (int)($m[1] !== '' ? $m[1] : $m[2]);
    return null;
}

// Checks one student's data. Returns [cleaned data, list of error messages].
function validate_student($row) {
    $errors = [];
    $studentId = trim((string)($row['student_id'] ?? ''));
    $fullName = preg_replace('/\s+/', ' ', trim((string)($row['full_name'] ?? '')));
    $email = trim((string)($row['email'] ?? ''));
    $course = trim((string)($row['course'] ?? ''));
    $section = trim((string)($row['section'] ?? ''));
    $contact = trim((string)($row['contact_number'] ?? ''));
    $year = parse_year_level($row['year'] ?? '');

    if ($studentId === '') $errors[] = 'Student ID is missing.';
    elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9-]{2,29}$/', $studentId)) $errors[] = 'Student ID may only contain letters, numbers and dashes (3-30 characters).';
    if ($fullName === '') $errors[] = 'Full name is missing.';
    elseif (mb_strlen($fullName) < 3 || mb_strlen($fullName) > 120 || !preg_match('/^[\p{L}\p{M}\s.\'-]+(,[\p{L}\p{M}\s.\'-]+)?$/u', $fullName)) $errors[] = 'Full name is not valid.';
    if ($email !== '' && (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 120)) $errors[] = 'Email address is not valid.';
    if ($course === '') $errors[] = 'Course is missing.';
    elseif (mb_strlen($course) > 80) $errors[] = 'Course is too long (maximum 80 characters).';
    if ($year === null) $errors[] = 'Year must be a number from 1 to 6.';
    if ($section === '') $errors[] = 'Section is missing.';
    elseif (mb_strlen($section) > 20) $errors[] = 'Section is too long (maximum 20 characters).';
    if ($contact !== '' && !preg_match('/^[0-9+() -]{7,20}$/', $contact)) $errors[] = 'Contact number is not valid.';

    return [[
        'student_id' => $studentId, 'full_name' => $fullName, 'email' => $email, 'course' => $course,
        'year' => $year, 'section' => $section, 'contact_number' => $contact,
    ], $errors];
}

// Does this student ID (or username) already exist?
function student_id_taken($studentId) {
    return fetch_one('SELECT id FROM users WHERE username = ? OR student_no = ?', [$studentId, $studentId]) !== null;
}

// Creates the login account and the student profile. Returns the new user id.
function create_student($data, $password) {
    $pdo = db();
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        query('INSERT INTO users (username, password_hash, full_name, email, role, student_no, must_change_password) VALUES (?, ?, ?, ?, ?, ?, 1)',
            [$data['student_id'], password_hash($password, PASSWORD_DEFAULT), $data['full_name'],
             $data['email'] !== '' ? $data['email'] : null, 'student', $data['student_id']]);
        $userId = (int)$pdo->lastInsertId();
        query('INSERT INTO students (user_id, course, year_level, section, contact_no) VALUES (?, ?, ?, ?, ?)',
            [$userId, $data['course'], $data['year'], $data['section'], $data['contact_number'] !== '' ? $data['contact_number'] : null]);
        if ($own) $pdo->commit();
        return $userId;
    } catch (Throwable $e) {
        if ($own && $pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

// Reads the uploaded CSV file into rows. Throws RuntimeException with a clear message if the file is not usable.
// Returns a list of ['line' => number in the file, 'data' => [column => value]].
function read_students_csv($path) {
    $text = file_get_contents($path);
    if ($text === false || trim($text) === '') throw new RuntimeException('The CSV file is empty.');
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") $text = substr($text, 3);                 // Excel adds a BOM
    if (!mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
    if (strpos($text, "\0") !== false) throw new RuntimeException('This does not look like a CSV text file.');

    $lines = preg_split('/\r\n|\r|\n/', $text);
    $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $text);
    rewind($stream);

    $header = fgetcsv($stream, 0, $delimiter);
    $header = array_map(fn($h) => strtolower(trim(str_replace([' ', '-'], '_', (string)$h))), $header ?: []);
    // Accept a few common alternative column names.
    $aliases = ['id_number' => 'student_id', 'student_no' => 'student_id', 'id' => 'student_id', 'name' => 'full_name',
                'fullname' => 'full_name', 'year_level' => 'year', 'contact' => 'contact_number', 'contact_no' => 'contact_number',
                'mobile' => 'contact_number', 'phone' => 'contact_number', 'program' => 'course'];
    $header = array_map(fn($h) => $aliases[$h] ?? $h, $header);
    $missing = array_values(array_diff(CSV_COLUMNS, $header));
    if ($missing) throw new RuntimeException('The CSV is missing these columns: ' . implode(', ', $missing) . '. Download the sample file to see the correct format.');

    $rows = [];
    $line = 1;
    while (($fields = fgetcsv($stream, 0, $delimiter)) !== false) {
        $line++;
        if ($fields === [null] || trim(implode('', $fields)) === '') continue; // empty line
        if (count($rows) >= CSV_MAX_ROWS) throw new RuntimeException('The CSV has more than ' . CSV_MAX_ROWS . ' students. Split it into smaller files.');
        $data = [];
        foreach ($header as $i => $name) $data[$name] = $fields[$i] ?? '';
        $rows[] = ['line' => $line, 'data' => $data];
    }
    fclose($stream);
    if (!$rows) throw new RuntimeException('The CSV has no student rows.');
    return $rows;
}

// Checks every row. Nothing is saved. Returns ['valid' => [...], 'failed' => [...]].
function check_students_csv($rows) {
    $valid = []; $failed = []; $seen = [];
    foreach ($rows as $row) {
        [$data, $errors] = validate_student($row['data']);
        if (!$errors) {
            $key = mb_strtolower($data['student_id']);
            if (isset($seen[$key])) $errors[] = "Student ID appears twice in this file (first on line {$seen[$key]}).";
            elseif (student_id_taken($data['student_id'])) $errors[] = 'A student or account with this ID already exists.';
            else $seen[$key] = $row['line'];
        }
        if ($errors) $failed[] = ['line' => $row['line'], 'student_id' => $data['student_id'], 'full_name' => $data['full_name'], 'errors' => $errors];
        else $valid[] = ['line' => $row['line'], 'data' => $data];
    }
    return ['valid' => $valid, 'failed' => $failed];
}

// The sample file students download from the admin page.
function sample_students_csv() {
    return "student_id,full_name,email,course,year,section,contact_number\r\n"
         . "2026-10001,Maria Santos,maria.santos@example.com,BS Computer Science,1,A,09171234567\r\n"
         . "2026-10002,Jose Reyes,jose.reyes@example.com,BS Accountancy,2,B,09181234567\r\n"
         . "2026-10003,Ana Garcia,ana.garcia@example.com,BS Education,3,C,09191234567\r\n";
}
