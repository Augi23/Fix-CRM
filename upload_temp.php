<?php
$data = file_get_contents('php://input');
if (!$data) { http_response_code(400); echo 'no data'; exit; }
file_put_contents('/tmp/upload.tar.gz', $data);
echo 'OK ' . strlen($data) . ' bytes';
