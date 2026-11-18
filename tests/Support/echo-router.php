<?php
// Router for `php -S`: echoes the request back as JSON, with a few canned behaviours, for MaxTlsHttpClientTest
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ('/redirect' === $path) {
    header('Location: /echo', true, 302);
    return;
}
if (preg_match('#^/status/(\d+)$#', $path, $m)) {
    http_response_code((int) $m[1]);
    header('Content-Type: application/json');
    echo json_encode(['status' => (int) $m[1]]);
    return;
}

header('Content-Type: application/json');
header('X-Echo: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $_SERVER['REQUEST_URI'],
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
    // multipart bodies are consumed by PHP, which leaves the parts here
    'files' => array_map(static fn(array $f) => file_get_contents($f['tmp_name']), $_FILES),
]);
