<?php
// Admin only. POST -> sends a tiny test message to the AI service and reports what happened.
// Used by the "Test AI connection" button on the Settings page. The API key is never sent to the browser.
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/ai.php';
register_error_handlers();
require_method('POST');
require_login(['admin']);
require_csrf();

$status = [
    'key_set' => ai_is_configured(),
    'model'   => ai_model(),
    'gd'      => function_exists('imagecreatefromstring'),
    'curl'    => function_exists('curl_init'),
];
if (!$status['key_set']) {
    json_response(['ok' => true, 'connected' => false, 'status' => $status, 'message' => 'AI_API_KEY is not set in the .env file. Document verification still works with the basic checks, and the chatbot is off.']);
}

$reply = ai_send(['max_tokens' => 20, 'messages' => [['role' => 'user', 'content' => 'Reply with the single word: ready']]], 15);
$problems = [
    'auth' => 'The service refused the key. Check that AI_API_KEY is correct and active.',
    'model' => 'The model name was not found. Check AI_MODEL in .env.',
    'rate_limit' => 'The service is busy or your plan limit was reached. Try again later.',
    'network' => 'The server could not reach the AI service. Check the internet connection and that the PHP curl extension is enabled.',
    'server' => 'The AI service returned an error. Try again later.',
    'bad_response' => 'The AI service answered with something unexpected.',
];
json_response(['ok' => true, 'connected' => $reply['ok'], 'status' => $status,
    'message' => $reply['ok'] ? 'Connected. The AI service answered correctly.' : ($problems[$reply['code']] ?? 'The connection failed.') . ' (' . $reply['error'] . ')']);
