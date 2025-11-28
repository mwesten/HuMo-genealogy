<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

ob_start();

use Genealogy\App\Model\ChatGenealogyApiModel;

try {
    $action = $_GET['action'] ?? '';
    if ($action === 'chat') {
        $model = new ChatGenealogyApiModel($config);
        $question = (string)($_POST['question'] ?? '');
        $answer = $model->handleRequest($question);

        // Clean any notices before output
        if (ob_get_length()) {
            ob_clean();
        }
        echo json_encode(['answer' => $answer], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['error' => 'Not found']);
} catch (Throwable $e) {
    /*
    // Log server-side; don’t leak to client
    error_log('[API] ' . $e->getMessage());
    http_response_code(500);
    if (ob_get_length()) {
        ob_clean();
    }
    echo json_encode(['error' => 'Server error']);
    */
}
