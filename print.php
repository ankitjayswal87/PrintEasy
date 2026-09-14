<?php
require_once __DIR__ . '/config.php';
require_login();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    http_response_code(400);
    exit('Invalid document ID.');
}

$stmt = db()->prepare("
    SELECT id, media_url, page_count, content, document_type
    FROM documents
    WHERE id = :id
    LIMIT 1
");
$stmt->execute([':id' => $id]);

$doc = $stmt->fetch();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$mediaUrl = $doc['media_url'];

if (!preg_match('#^https?://#i', $mediaUrl)) {
    http_response_code(400);
    exit('Invalid document URL.');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>Print Document</title>

    <style>
        html,
        body {
            margin: 0;
            padding: 0;
            width: 100%;
            height: 100%;
        }

        iframe {
            width: 100%;
            height: 100vh;
            border: 0;
        }
    </style>
</head>

<body>

<iframe
    id="documentFrame"
    src="<?= e($mediaUrl) ?>"
    title="Document"
></iframe>

<script>
const frame = document.getElementById('documentFrame');

frame.onload = function () {
    setTimeout(function () {
        try {
            frame.contentWindow.focus();
            frame.contentWindow.print();
        } catch (e) {
            window.print();
        }
    }, 500);
};
</script>

</body>
</html>