<?php
header('Access-Control-Allow-Origin: https://nicolaecatrina.com');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
http_response_code(503);
echo json_encode(['error' => 'Service temporarily unavailable']);
