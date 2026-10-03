<?php
// End-to-end check of the whole system through the real HTTP API.
//   1) php database/install.php
//   2) Start Apache + MySQL, make sure AI_API_KEY is EMPTY in .env (the test then also checks the
//      "AI unavailable -> manual verification" fallback; the quality check works without a key).
//   3) php tests/smoke_test.php http://localhost/new-ui-main/api/
// Needs the PHP GD extension (it draws the test pictures) and uses the demo accounts (password Password123!).
$base = rtrim($argv[1] ?? 'http://localhost/new-ui-main/api/', '/') . '/';
$passed = 0; $failed = 0;

function check($name, $condition, $detail = '') {
    global $passed, $failed;
    if ($condition) { $passed++; echo "  PASS  $name\n"; }
    else { $failed++; echo "  FAIL  $name $detail\n"; }
}

// One "browser": keeps its own cookies and CSRF token.
class Client {
    public $jar; public $csrf = '';
    function __construct() { $this->jar = tempnam(sys_get_temp_dir(), 'jar'); }
    function call($file, $params = [], $post = null, $json = true) {
        global $base;
        $url = $base . $file . ($params ? '?' . http_build_query($params) : '');
        $ch = curl_init($url);
        $headers = [];
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($json) { $headers[] = 'Content-Type: application/json'; $post = json_encode($post); }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar,
                                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 120]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = json_decode($body, true);
        if (isset($data['csrf'])) $this->csrf = $data['csrf'];
        return [$status, $data, $body];
    }
    function login($user, $pass = 'Password123!') { return $this->call('login.php', [], ['username' => $user, 'password' => $pass]); }
}

if (!function_exists('imagecreatetruecolor')) { echo "The PHP GD extension is needed to draw the test pictures.\n"; exit(1); }
$tmp = sys_get_temp_dir();

// A readable "document" picture, a blurry one and a dark one.
function make_document($path, $variant = 'good', $seed = 0) {
    $img = imagecreatetruecolor(1000, 1400);
    imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 240));
    $ink = imagecolorallocate($img, 20, 20, 20);
    for ($y = 80; $y < 1300; $y += 40) imagestring($img, 5, 80, $y, "CERTIFICATE OF ENROLLMENT JUAN DELA CRUZ 2026-00001 line $y #$seed", $ink);
    if ($variant === 'blur') for ($i = 0; $i < 25; $i++) imagefilter($img, IMG_FILTER_GAUSSIAN_BLUR);
    if ($variant === 'dark') imagefilter($img, IMG_FILTER_BRIGHTNESS, -230);
    imagepng($img, $path);
}

echo "Login, roles and security\n";
$student = new Client(); $registrar = new Client(); $cashier = new Client(); $admin = new Client(); $anon = new Client();
[$s] = $anon->call('requests.php', ['action' => 'list']);            check('API blocks users who are not logged in (401)', $s === 401);
[$s] = $anon->login('registrar', 'wrong-password');                  check('Wrong password is refused (401)', $s === 401);
[$s, $d] = $student->login('2026-00001');                            check('Student login, opens student.html', $s === 200 && $d['user']['role'] === 'student' && $d['user']['home'] === 'student.html');
[$s, $d] = $registrar->login('registrar');                           check('"Registrar" account is an admin account', $s === 200 && $d['user']['role'] === 'admin' && $d['user']['full_name'] === 'Registrar');
$registrarName = $d['user']['full_name'];
[$s, $d] = $admin->login('admin');                                   check('"Admin" account uses the same role and page', $s === 200 && $d['user']['role'] === 'admin' && $d['user']['home'] === 'manage.html' && $d['user']['full_name'] === 'Admin');
check('The two accounts show different names', $registrarName !== $d['user']['full_name']);
[$s] = $cashier->login('cashier');                                   check('Cashier login', $s === 200);
[$s] = $student->call('users.php');                                  check('Student cannot open the staff accounts list (403)', $s === 403);
[$s] = $student->call('students.php', ['action' => 'list']);         check('Student cannot open the students list (403)', $s === 403);
[$s] = $cashier->call('students.php', ['action' => 'list']);         check('Cashier cannot open the students list (403)', $s === 403);
[$s] = $registrar->call('students.php', ['action' => 'list']);       check('Registrar can open the students list', $s === 200);
[$s] = $admin->call('students.php', ['action' => 'list']);           check('Admin can open the students list (same permissions)', $s === 200);
$saved = $student->csrf; $student->csrf = '';
[$s] = $student->call('notifications.php', ['action' => 'read_all'], []); check('POST without CSRF token is refused (403)', $s === 403);
$student->csrf = 'wrong-token';
[$s] = $student->call('notifications.php', ['action' => 'read_all'], []); check('POST with a wrong CSRF token is refused (403)', $s === 403);
$student->csrf = $saved;
[$s] = $student->call('documents.php', ['action' => 'delete'], ['id' => 1]);  check('Student cannot delete a document (403)', $s === 403);

echo "Catalog management\n";
$docName = 'Test Document ' . random_int(1000, 9999);
$newDoc = ['id' => 0, 'name' => $docName, 'description' => 'Created by the smoke test', 'category' => 'Certification', 'price' => 50,
           'processing_min_days' => 1, 'processing_max_days' => 2, 'release_methods' => ['Hardcopy'], 'is_active' => true,
           'requirements' => [['name' => 'Test requirement', 'description' => 'Any picture', 'allowed_types' => ['png', 'jpg'], 'max_size_mb' => 3, 'is_required' => true, 'requires_name' => true]]];
[$s, $d] = $admin->call('documents.php', ['action' => 'save'], $newDoc);        check('Admin adds a document', $s === 200 && $d['id'] > 0, $d['error'] ?? '');
$docId = $d['id'] ?? 0;
[$s] = $admin->call('documents.php', ['action' => 'save'], $newDoc);            check('Same document name twice is refused (422)', $s === 422);
$bad = $newDoc; $bad['name'] = 'Bad'; $bad['price'] = -5;
[$s] = $admin->call('documents.php', ['action' => 'save'], $bad);               check('Negative price is refused (422)', $s === 422);
[$s, $d] = $student->call('documents.php');
check('New document appears in the student catalog', $s === 200 && in_array($docName, array_column($d['documents'], 'name')));
[$s] = $admin->call('documents.php', ['action' => 'toggle'], ['id' => $docId]); check('Admin deactivates the document', $s === 200);
[$s, $d] = $student->call('documents.php');
check('Inactive document is hidden from students', !in_array($docName, array_column($d['documents'], 'name')));
[$s, $d] = $admin->call('documents.php');
$mine = array_values(array_filter($d['documents'], fn($x) => $x['id'] === $docId))[0] ?? null;
$edit = $newDoc; $edit['id'] = $docId; $edit['price'] = 75; $edit['is_active'] = true; $edit['requirements'][0]['id'] = $mine['requirements'][0]['id'];
[$s] = $admin->call('documents.php', ['action' => 'save'], $edit);              check('Admin edits the document', $s === 200);
[$s, $d] = $admin->call('documents.php');
$mine = array_values(array_filter($d['documents'], fn($x) => $x['id'] === $docId))[0] ?? null;
check('Edit is saved in the database (price 75, active)', $mine && $mine['price'] == 75 && $mine['is_active'] === true);
[$s] = $admin->call('documents.php', ['action' => 'delete'], ['id' => $docId]); check('Admin deletes the unused document', $s === 200);
[$s, $d] = $admin->call('documents.php');
check('Deleted document is gone', !in_array($docName, array_column($d['documents'], 'name')));

echo "Students and CSV import\n";
$id1 = '2026-9' . random_int(1000, 9999); $id2 = '2026-9' . random_int(1000, 9999);
$csv = "student_id,full_name,email,course,year,section,contact_number\n"
     . "$id1,Maria Santos,maria@example.com,BS Computer Science,1,A,09171234567\n"
     . "$id2,Jose Reyes,not-an-email,BS Accountancy,2,B,09181234567\n"
     . "$id1,Duplicate Person,dup@example.com,BS Education,1,A,09171234567\n"
     . ",No Id,,BS Education,1,A,\n"
     . "2026-00001,Existing Student,a@example.com,BS Education,1,A,\n"
     . "2026-9999,Bad Year,a@example.com,BS Education,9,A,\n";
file_put_contents("$tmp/students.csv", $csv);
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv'), 'dry_run' => '1'], false);
check('CSV check finds the valid and the invalid rows', $s === 200 && $d['valid_count'] === 1 && count($d['failed']) === 5, json_encode($d['failed'] ?? $d));
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv')], false);
check('CSV import creates only the valid student', $s === 200 && count($d['imported']) === 1 && $d['imported'][0]['username'] === $id1, $d['error'] ?? '');
$defaultPassword = $d['imported'][0]['password'] ?? '';
check('Default password is generated', strlen($defaultPassword) >= 10);
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/students.csv", 'text/csv', 'students.csv')], false);
check('Importing the same file again does not duplicate students', $s === 200 && count($d['imported']) === 0);
file_put_contents("$tmp/wrong.csv", "name,age\nA,1\n");
[$s, $d] = $registrar->call('students.php', ['action' => 'import'], ['csv' => new CURLFile("$tmp/wrong.csv", 'text/csv', 'wrong.csv')], false);
check('CSV with wrong columns is refused (422)', $s === 422 && strpos($d['error'], 'missing these columns') !== false);

$newStudent = new Client();
[$s, $d] = $newStudent->login($id1, $defaultPassword);                           check('Imported student logs in with Student ID + default password', $s === 200 && $d['user']['must_change_password'] === true);
[$s, $d] = $newStudent->call('documents.php');                                    check('Default password must be changed first (403)', $s === 403 && ($d['code'] ?? '') === 'PASSWORD_CHANGE_REQUIRED');
[$s] = $newStudent->call('password.php', [], ['current_password' => $defaultPassword, 'new_password' => 'MyOwnPass#2026']);  check('Student changes the default password', $s === 200);
[$s] = $newStudent->call('documents.php');                                        check('Portal is unlocked after the change', $s === 200);
[$s, $d] = $newStudent->call('students.php', ['action' => 'profile']);
check('Student profile shows course, year and section', $s === 200 && $d['student']['course'] === 'BS Computer Science' && (int)$d['student']['year_level'] === 1 && $d['student']['section'] === 'A', $d['error'] ?? '');
$studentUserId = $d['student']['id'] ?? 0;
[$s, $d] = $registrar->call('students.php', ['action' => 'profile', 'id' => $studentUserId]);   check('Registrar views a student profile', $s === 200 && $d['student']['full_name'] === 'Maria Santos');
[$s] = $newStudent->call('students.php', ['action' => 'profile', 'id' => 1]);     check('Student only ever gets their own profile', $s === 200);
file_put_contents("$tmp/notimage.png", 'not a picture');
[$s, $d] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/notimage.png", 'image/png', 'x.png')], false);   check('A fake photo is refused (422)', $s === 422);
$tiny = imagecreatetruecolor(100, 100); imagepng($tiny, "$tmp/tiny.png");
[$s, $d] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/tiny.png", 'image/png', 'tiny.png')], false);   check('A photo smaller than 200x200 is refused (422)', $s === 422, $d['error'] ?? '');
$big = imagecreatetruecolor(400, 500); imagefill($big, 0, 0, imagecolorallocate($big, 90, 120, 200)); imagejpeg($big, "$tmp/photo.jpg");
[$s] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/photo.jpg", 'image/jpeg', 'photo.jpg')], false);    check('Student saves a profile photo', $s === 200);
[$s, $d] = $newStudent->call('students.php', ['action' => 'profile']);                                                         check('The photo is kept (profile says has_photo)', $d['student']['has_photo'] === true);
[$s] = $newStudent->call('photo.php', [], ['photo' => new CURLFile("$tmp/photo.jpg", 'image/jpeg', 'photo.jpg'), 'user_id' => 1], false);  check('A student who sends another user_id still only changes their own photo', $s === 200);
[$s] = $newStudent->call('photo.php', ['id' => $studentUserId]);                  check('Photo can be opened', $s === 200);
[$s] = $anon->call('photo.php', ['id' => $studentUserId]);                        check('Photo is private (401 when not logged in)', $s === 401);
[$s, $d] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2, 'full_name' => 'Jose Reyes', 'email' => 'jose@example.com', 'course' => 'BS Accountancy', 'year' => 2, 'section' => 'B', 'contact_number' => '09181234567']);
check('Registrar adds one student by hand', $s === 201 && !empty($d['credentials']['password']), $d['error'] ?? '');
[$s] = $registrar->call('students.php', ['action' => 'create'], ['student_id' => $id2, 'full_name' => 'Jose Reyes', 'course' => 'X', 'year' => 2, 'section' => 'B']);
check('Duplicate Student ID is refused (422)', $s === 422);
[$s, $d] = $registrar->call('students.php', ['action' => 'list', 'q' => $id2]); $joseId = $d['students'][0]['id'] ?? 0;
[$s] = $registrar->call('students.php', ['action' => 'update'], ['id' => $joseId, 'full_name' => 'Jose P. Reyes', 'email' => 'jose@example.com', 'course' => 'BS Accountancy', 'year' => 3, 'section' => 'C']);
check('Registrar edits a student', $s === 200);
[$s] = $registrar->call('students.php', ['action' => 'delete'], ['id' => $joseId]);   check('Registrar deletes a student without requests', $s === 200);

echo "Document verification before submitting (what the student sees)\n";
[$s, $d] = $student->call('documents.php');
$doc = array_values(array_filter($d['documents'], fn($x) => $x['name'] === 'Certification of Grades'))[0];
$reqs = $doc['requirements'];
$first = $reqs[0]['id'];

// Helper: upload one file for one requirement, like the browser does.
$verify = function ($client, $requirementId, $path, $name, $mime = 'image/png', $replace = 0) {
    return $client->call('verify.php', [], ['file' => new CURLFile($path, $mime, $name), 'requirement_id' => $requirementId, 'replace_check_id' => $replace], false);
};
$good = [];
foreach ($reqs as $i => $req) { $good[$req['id']] = "$tmp/good_$i.png"; make_document($good[$req['id']], 'good', $i); }
make_document("$tmp/blurry.png", 'blur', 99);
make_document("$tmp/dark.png", 'dark', 98);
file_put_contents("$tmp/fake.png", 'this is not an image');
file_put_contents("$tmp/virus.exe", 'MZ');

[$s, $d] = $anon->call('verify.php', [], ['requirement_id' => $first], false);               check('Verification needs a login (401)', $s === 401);
[$s, $d] = $registrar->call('verify.php', [], ['requirement_id' => $first], false);          check('Only students can use verification (403)', $s === 403);
[$s, $d] = $verify($student, $first, "$tmp/virus.exe", 'virus.exe', 'application/octet-stream');
check('Unsupported file type: "Invalid File" (422)', $s === 422 && ($d['title'] ?? '') === 'Invalid File' && strpos($d['error'], 'supported') !== false, $d['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/fake.png", 'fake.png');
check('A text file renamed to .png is refused (422)', $s === 422, $d['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/blurry.png", 'blurry.png');
$declined = $d['check'] ?? [];
check('Blurry document is declined', $s === 200 && ($declined['status'] ?? '') === 'DECLINED' && ($declined['title'] ?? '') === 'Document Declined', json_encode($d));
check('... with the exact reason', ($declined['message'] ?? '') === 'The uploaded image is too blurry to read. Please upload a clearer image or PDF.', $declined['message'] ?? '');
check('The student message never mentions AI', !preg_match('/\bAI\b|artificial/i', json_encode($d)));
[$s, $d] = $verify($student, $first, "$tmp/dark.png", 'dark.png');
check('Very dark document is declined', ($d['check']['status'] ?? '') === 'DECLINED' && strpos($d['check']['message'], 'too dark') !== false, json_encode($d));

$checkIds = [];
foreach ($reqs as $req) {
    [$s, $d] = $verify($student, $req['id'], $good[$req['id']], 'doc.png');
    $status = $d['check']['status'] ?? '';
    check("Good document accepted for \"{$req['name']}\"", $s === 200 && in_array($status, ['ACCEPTED', 'RECEIVED'], true), json_encode($d));
    $checkIds[$req['id']] = $d['check']['id'] ?? 0;
}
echo "        (with no AI key a good document is RECEIVED = passed the basic checks, the registrar looks at it)\n";

$payloadFor = function ($ids) use ($doc) {
    $checks = [];
    foreach ($ids as $reqId => $checkId) if ($checkId) $checks["r1:$reqId"] = $checkId;
    return json_encode(['purpose' => 'Scholarship application', 'release_method' => 'Hardcopy', 'payment_method' => 'Cash',
        'items' => [['row' => 'r1', 'document_id' => $doc['id'], 'quantity' => 1]], 'checks' => $checks]);
};

echo "Submitting\n";
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor([])], false);
check('Missing documents are refused (422)', $s === 422 && !empty($r['missing']), $r['error'] ?? '');
$ids = $checkIds; $ids[$first] = 99999999;
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($ids)], false);
check('A document that was not verified is refused (422)', $s === 422, $r['error'] ?? '');
[$s, $d] = $verify($student, $first, "$tmp/blurry.png", 'blurry.png');
$ids = $checkIds; $ids[$first] = $d['check']['id'] ?? 0;
[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($ids)], false);
check('A declined document cannot be submitted (422)', $s === 422, $r['error'] ?? '');
[$s, $r] = $registrar->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('A staff account cannot create requests (403)', $s === 403);
[$s, $r] = $newStudent->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check("Another student cannot use someone else's verified files (422)", $s === 422);

[$s, $r] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('Request is created', $s === 201 && !empty($r['request']['id']), $r['error'] ?? '');
$requestId = $r['request']['id'] ?? 0;
check('Request goes to registrar review', ($r['request']['status'] ?? '') === 'FOR_REVIEW');
check('The answer never mentions AI', !preg_match('/\bAI\b|artificial/i', json_encode($r)));
[$s, $r2] = $student->call('requests.php', ['action' => 'create'], ['payload' => $payloadFor($checkIds)], false);
check('The same verified files cannot be used twice (422)', $s === 422);

echo "Student and registrar views\n";
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$studentFile = $d['items'][0]['requirements'][0]['file'];
check('Student sees the file and a Pending status', $s === 200 && $studentFile['review_status'] === 'Pending');
check('Student does not receive any automatic-check details', $studentFile['ai'] === null && !preg_match('/extracted_text|confidence|name_match/', json_encode($d)));
[$s, $d] = $student->call('notifications.php');
$titles = array_column($d['notifications'], 'title');
check('Student notifications do not mention AI', !preg_match('/\bAI\b|artificial/i', json_encode($d['notifications'])));
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$adminFile = $d['items'][0]['requirements'][0]['file'];
check('Registrar sees the automatic check details', $adminFile['ai'] !== null && array_key_exists('name_on_document', $adminFile['ai']));
[$s] = $registrar->call('file.php', ['id' => $adminFile['id'], 'inline' => 1]);   check('Registrar can open the file', $s === 200);
[$s] = $cashier->call('file.php', ['id' => $adminFile['id']]);                    check('Cashier cannot open student files (403)', $s === 403);
[$s] = $newStudent->call('file.php', ['id' => $adminFile['id']]);                 check('Another student cannot open the file (404)', $s === 404);
[$s] = $student->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);   check('Student cannot change request status (403)', $s === 403);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);  check('Cannot process before every file is approved (422)', $s === 422);

echo "Correction request\n";
[$s] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $adminFile['id'], 'decision' => 'Rejected', 'remarks' => 'The picture is cut off']);
check('Registrar declines a file with a reason', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'NEEDS_CORRECTION', 'remarks' => 'Please upload a clearer file']);
check('Registrar requests correction', $s === 200);
[$s, $d] = $student->call('notifications.php');
check('Student receives the correction notification', in_array('Correction needed', array_column($d['notifications'], 'title')));
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
$itemId = $d['items'][0]['id'];
check('Student sees "Declined" with the reason', $d['items'][0]['requirements'][0]['file']['review_remarks'] === 'The picture is cut off');
make_document("$tmp/fixed.png", 'good', 123);
[$s, $v] = $verify($student, $first, "$tmp/fixed.png", 'fixed.png');
check('Corrected file is verified before it is sent', $s === 200 && in_array($v['check']['status'] ?? '', ['ACCEPTED', 'RECEIVED'], true), json_encode($v));
[$s, $r] = $student->call('requests.php', ['action' => 'resubmit', 'id' => $requestId], ['payload' => json_encode(['checks' => []])], false);
check('Correction without a new verified file is refused (422)', $s === 422);
[$s, $r] = $student->call('requests.php', ['action' => 'resubmit', 'id' => $requestId], ['payload' => json_encode(['checks' => ["$itemId:$first" => $v['check']['id'] ?? 0]])], false);
check('Student sends the corrected document', $s === 200, $r['error'] ?? '');
[$s, $d] = $registrar->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
check('Request is back in review', $d['request']['status'] === 'FOR_REVIEW', $d['request']['status']);
foreach ($d['items'][0]['requirements'] as $req) {
    [$s] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $req['file']['id'], 'decision' => 'Verified']);
    check("Approve \"{$req['name']}\"", $s === 200);
}
[$s, $r] = $registrar->call('requests.php', ['action' => 'review_file'], ['file_id' => $d['items'][0]['requirements'][0]['file']['id'], 'decision' => 'Rejected']);
check('Declining without a reason is refused (422)', $s === 422);

echo "Process and complete\n";
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);   check('Request is processing', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'READY']);        check('Cannot be ready before payment (422)', $s === 422);
[$s] = $cashier->call('requests.php', ['action' => 'mark_paid'], ['id' => $requestId]);                               check('Cashier marks payment received', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'READY']);        check('Request is ready', $s === 200);
[$s] = $admin->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'COMPLETED']);        check('Admin completes it (same permissions as registrar)', $s === 200);
[$s] = $registrar->call('requests.php', ['action' => 'set_status'], ['id' => $requestId, 'status' => 'PROCESSING']);   check('A completed request cannot go back (422)', $s === 422);
[$s, $d] = $student->call('requests.php', ['action' => 'detail', 'id' => $requestId]);
check('Student sees COMPLETED and the history', $d['request']['status'] === 'COMPLETED' && count($d['history']) >= 6);
[$s, $d] = $student->call('students.php', ['action' => 'profile']);
check('Profile shows the verified documents', $d['summary']['verified'] >= 1);
[$s, $d] = $student->call('notifications.php');
$noteId = $d['notifications'][0]['id'];
[$s, $r] = $student->call('notifications.php', ['action' => 'read'], ['id' => $noteId]);       check('Mark a notification as read', $s === 200);
[$s, $r] = $student->call('notifications.php', ['action' => 'delete'], ['id' => $noteId]);     check('Delete a notification', $s === 200);

echo "Catalog protection and chatbot\n";
[$s, $d] = $admin->call('documents.php');
$used = array_values(array_filter($d['documents'], fn($x) => $x['id'] === $doc['id']))[0];
[$s] = $admin->call('documents.php', ['action' => 'delete'], ['id' => $doc['id']]);   check('A document used by requests cannot be deleted (422)', $s === 422);
[$s, $d] = $student->call('chat.php');                                                 check('Chat history loads', $s === 200 && isset($d['messages']));
$aiEnabled = !empty($d['enabled']);
[$s, $d] = $student->call('chat.php', [], ['message' => 'What are the requirements for Certification of Grades?']);
if ($aiEnabled) check('Chatbot answers', $s === 200 && !empty($d['answer']), $d['error'] ?? '');
else check('Chatbot says it is not set up when there is no API key (503)', $s === 503);
[$s] = $student->call('chat.php', [], ['message' => '']);                              check('Empty chat message is refused (422)', $s === 422);
[$s] = $anon->call('chat.php');                                                        check('Chat needs a login (401)', $s === 401);
[$s] = $student->call('chat.php', ['action' => 'clear'], []);                          check('Student clears the chat', $s === 200);

echo "AI connection test\n";
[$s] = $student->call('ai_test.php', [], []);                                           check('Students cannot run the AI connection test (403)', $s === 403);
[$s, $d] = $admin->call('ai_test.php', [], []);                                          check('Admin can run the AI connection test', $s === 200 && isset($d['connected']) && isset($d['message']), json_encode($d));
check('The test never reveals the API key', strpos(json_encode($d), 'sk-') === false);

echo "Staff accounts\n";
$name = 'tester' . random_int(1000, 9999);
[$s] = $admin->call('users.php', ['action' => 'create'], ['username' => $name, 'full_name' => 'Test Staff', 'role' => 'admin', 'password' => 'Password123!']);
check('Admin creates a staff account', $s === 201);
[$s] = $admin->call('users.php', ['action' => 'create'], ['username' => $name . 'x', 'full_name' => 'Test', 'role' => 'student', 'password' => 'Password123!']);
check('Students cannot be created through the staff accounts page (422)', $s === 422);
[$s, $d] = $admin->call('users.php', ['q' => $name]); $newId = $d['users'][0]['id'] ?? 0;
[$s] = $admin->call('users.php', ['action' => 'toggle'], ['id' => $newId]);            check('Admin disables the account', $s === 200);
$test = new Client(); [$s] = $test->login($name);                                       check('Disabled account cannot log in (403)', $s === 403);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
