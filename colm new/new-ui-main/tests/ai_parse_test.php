<?php
// Tests the document verification rules WITHOUT calling the internet.   Run:  php tests/ai_parse_test.php
// (needs the PHP GD extension for the picture tests; they are skipped without it)
require_once __DIR__ . '/../backend/ai.php';

$failed = 0;
function expect($name, $actual, $wanted) {
    global $failed;
    if ($actual === $wanted) { echo "  PASS  $name\n"; return; }
    $failed++; echo "  FAIL  $name (got " . var_export($actual, true) . ")\n";
}
function answer($overrides = []) {
    return array_merge(['readable' => true, 'problem' => 'none', 'name_on_document' => 'JUAN DELA CRUZ', 'correct_document' => true,
                        'complete' => true, 'required_info_visible' => true, 'reason' => 'Looks valid.',
                        'extracted_text' => 'Certificate of Enrollment', 'confidence' => 0.92], $overrides);
}
$req = ['name' => 'Valid ID', 'description' => '', 'ai_hint' => '', 'requires_name' => 1];
$decide = fn($a, $r = null) => ai_decide(is_array($a) ? json_encode($a) : $a, $r ?? $req, 'Juan Dela Cruz');

echo "How the AI answer is turned into a decision\n";
expect('Good document -> PASS', $decide(answer())['status'], 'PASS');
expect('PASS is not an automatic decline', $decide(answer())['auto_declined'], false);

$r = $decide(answer(['readable' => false, 'problem' => 'blurry']));
expect('Blurry -> FAIL', $r['status'], 'FAIL');
expect('Blurry reason', $r['reason'], 'The uploaded image is too blurry to read. Please upload a clearer image or PDF.');
expect('Blurry is an automatic decline', $r['auto_declined'], true);
expect('Unreadable reason', $decide(answer(['readable' => false, 'problem' => 'unreadable']))['reason'], 'The document could not be read. Please upload a clearer copy.');

$r = $decide(answer(['name_on_document' => 'MARIA SANTOS']));
expect('Different name -> FAIL', $r['status'], 'FAIL');
expect('Different name reason', $r['reason'], "The name on the submitted document does not match the student's registered account information.");
expect('Name match score is saved', is_int($r['name_match_score']), true);
expect('Name written as "CRUZ, JUAN D." still matches', $decide(answer(['name_on_document' => 'CRUZ, JUAN D.']))['status'], 'PASS');
expect('No name on the document -> FAIL', $decide(answer(['name_on_document' => '']))['status'], 'FAIL');
$noName = ['name' => 'Request form', 'description' => '', 'ai_hint' => '', 'requires_name' => 0];
expect('Requirement without name check -> PASS', $decide(answer(['name_on_document' => '']), $noName)['status'], 'PASS');

expect('Wrong document, sure -> FAIL', $decide(answer(['correct_document' => false]))['status'], 'FAIL');
expect('Wrong document reason', $decide(answer(['correct_document' => false]))['reason'], 'The uploaded file does not match the required document.');
expect('Wrong document, unsure -> REVIEW', $decide(answer(['correct_document' => false, 'confidence' => 0.4]))['status'], 'REVIEW');
expect('Incomplete, sure -> FAIL', $decide(answer(['complete' => false]))['status'], 'FAIL');
expect('Incomplete reason', $decide(answer(['complete' => false]))['reason'], 'The document is incomplete. Please upload the complete document.');
expect('Information missing reason', $decide(answer(['required_info_visible' => false]))['reason'], 'The required information could not be detected. Please upload a clearer copy.');
expect('Low confidence -> REVIEW', $decide(answer(['confidence' => 0.3]))['status'], 'REVIEW');
expect('Confidence above 1 is limited', $decide(answer(['confidence' => 5]))['confidence'], 1.0);

expect('JSON inside extra text', $decide("Here:\n```json\n" . json_encode(answer()) . "\n```")['status'], 'PASS');
expect('"readable" missing -> UNAVAILABLE', $decide(['name_on_document' => 'X'])['status'], 'UNAVAILABLE');
expect('Not JSON -> UNAVAILABLE', $decide('I cannot help with that.')['status'], 'UNAVAILABLE');
expect('Empty text -> UNAVAILABLE', ai_decide('', $req, 'Juan Dela Cruz')['status'], 'UNAVAILABLE');
expect('Failure message for the registrar', ai_decide('{}', $req, 'Juan Dela Cruz')['reason'], 'AI verification unavailable. Manual verification required.');

echo "What the student is told (never mentions AI)\n";
[$status, $title, $message] = student_view_of_result($decide(answer(['readable' => false, 'problem' => 'blurry'])));
expect('Declined: status', $status, 'DECLINED');
expect('Declined: title', $title, 'Document Declined');
[$status, $title, $message] = student_view_of_result($decide(answer()));
expect('Verified: status', $status, 'ACCEPTED');
expect('Verified: message', $message, 'Document verified successfully. You may proceed.');
[$status, $title, $message] = student_view_of_result(ai_unavailable('no key'));
expect('AI unavailable: student may still continue', $status, 'RECEIVED');
$allStudentTexts = implode(' ', [MSG_PASSED, MSG_RECEIVED, REASON_BLURRY, REASON_DARK, REASON_LOW_RESOLUTION, REASON_BLANK, REASON_CORRUPT, REASON_PROTECTED,
    REASON_UNREADABLE, REASON_WRONG_DOCUMENT, REASON_INCOMPLETE, REASON_INFO_MISSING, REASON_NAME_MISMATCH, REASON_NAME_MISSING, $message]);
expect('No student text contains "AI"', preg_match('/\bA\.?I\b|artificial|pre-?verify/i', $allStudentTexts), 0);

echo "AI answer when the API fails\n";
putenv('AI_API_KEY=');
$noKey = ai_send(['max_tokens' => 5, 'messages' => [['role' => 'user', 'content' => 'hi']]]);
if (!ai_is_configured()) {
    expect('No key -> no request, code no_key', $noKey['code'], 'no_key');
} else {
    echo "  SKIP  AI_API_KEY is set in .env, so the no-key test is skipped\n";
}

echo "Local quality checks (pictures and PDFs made on the fly)\n";
$dir = sys_get_temp_dir();
if (function_exists('imagecreatetruecolor')) {
    $sharp = imagecreatetruecolor(1000, 1400);
    imagefill($sharp, 0, 0, imagecolorallocate($sharp, 245, 245, 240));
    $ink = imagecolorallocate($sharp, 20, 20, 20);
    for ($y = 80; $y < 1300; $y += 40) imagestring($sharp, 5, 80, $y, 'CERTIFICATE OF ENROLLMENT JUAN DELA CRUZ 2026-00001 ' . $y, $ink);
    imagepng($sharp, "$dir/q_sharp.png");
    expect('Sharp document passes', check_image_quality("$dir/q_sharp.png"), null);

    $blurred = imagecreatefrompng("$dir/q_sharp.png");
    for ($i = 0; $i < 25; $i++) imagefilter($blurred, IMG_FILTER_GAUSSIAN_BLUR);
    imagepng($blurred, "$dir/q_blur.png");
    $p = check_image_quality("$dir/q_blur.png");
    expect('Blurry document is caught with the blur message', $p['message'] ?? null, REASON_BLURRY);

    $dark = imagecreatefrompng("$dir/q_sharp.png");
    imagefilter($dark, IMG_FILTER_BRIGHTNESS, -230);
    imagepng($dark, "$dir/q_dark.png");
    expect('Very dark document is caught', (check_image_quality("$dir/q_dark.png")['message'] ?? null), REASON_DARK);

    $small = imagecreatetruecolor(200, 200);
    imagepng($small, "$dir/q_small.png");
    expect('Tiny image is caught', (check_image_quality("$dir/q_small.png")['message'] ?? null), REASON_LOW_RESOLUTION);

    $blank = imagecreatetruecolor(800, 800);
    imagefill($blank, 0, 0, imagecolorallocate($blank, 255, 255, 255));
    imagepng($blank, "$dir/q_blank.png");
    expect('Blank page is caught', (check_image_quality("$dir/q_blank.png")['message'] ?? null), REASON_BLANK);

    file_put_contents("$dir/q_broken.png", substr(file_get_contents("$dir/q_sharp.png"), 0, 400) . str_repeat("\0", 200));
    expect('Damaged picture is caught', is_array(check_image_quality("$dir/q_broken.png")), true);

    // The whole check, blurry file: declined even when no AI key is set (nothing is sent anywhere).
    $result = verify_document_upload("$dir/q_blur.png", 'image/png', $req, 'Certification of Grades', 'Juan Dela Cruz');
    expect('verify_document_upload declines a blurry file', $result['status'], 'FAIL');
    expect('... automatically', $result['auto_declined'], true);
    expect('... with the blur reason', $result['reason'], REASON_BLURRY);
} else {
    echo "  SKIP  GD extension is not installed, picture tests skipped\n";
}
file_put_contents("$dir/q_ok.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
expect('Normal PDF passes the basic check', check_pdf_basic("$dir/q_ok.pdf"), null);
file_put_contents("$dir/q_cut.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\n");
expect('Cut-off PDF is caught', check_pdf_basic("$dir/q_cut.pdf")['message'] ?? null, REASON_CORRUPT);
file_put_contents("$dir/q_locked.pdf", "%PDF-1.4\n1 0 obj<</Encrypt 2 0 R>>endobj\n%%EOF\n");
expect('Password-protected PDF is caught', check_pdf_basic("$dir/q_locked.pdf")['message'] ?? null, REASON_PROTECTED);

echo $failed ? "\n$failed failed\n" : "\nAll passed\n";
exit($failed ? 1 : 0);
