<?php

// Router for the PHP built-in server in CurlTransportTest: answers with what it received.

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'];
if ($uri === '/redirect') {
    header('Location: /elsewhere', true, 302);

    return true;
}
if ($uri === '/slow') {
    usleep(2_000_000);
}
http_response_code(201);
header('Content-Type: application/json');
header('X-Echo: yes');
echo json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'uri' => $uri,
    'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
    'body' => file_get_contents('php://input'),
]);

return true;
