<?php
header("HTTP/1.0 404 Not Found");
header("Content-Type: application/json; charset=UTF-8");
echo json_encode([
    "status" => 404,
    "message" => "API endpoint not found"
]);
?> 