<?php
/* Poller tiskových úloh (agent na pokladním Macu, BEZ přihlášení do CRM):
     GET ?token=<tajný token pobočky>  →  200 + syrové ESC/POS bajty nejstarší
     čekající úlohy své pobočky, jinak 204 (nic k tisku), 403 (špatný token).
   Bajty vydá jen držiteli tokenu — tiskne tedy pořád jen ten jeden počítač.
   Úloha starší 3 minut se zahodí (agent byl mimo — starou účtenku už nikdo
   nečeká a bouře dotisků po výpadku je horší než nic). */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';
header('Cache-Control: no-store');

$token = trim((string)($_GET['token'] ?? ($_SERVER['HTTP_X_PRINT_TOKEN'] ?? '')));
$branch = afxPrintPollBranchByToken($token);
if ($branch <= 0) {
    usleep(300000);   // zpomalit hádání tokenů
    http_response_code(403);
    exit;
}

afxEnsurePrintJobsTable();
try {
    // pár pokusů: propadnutí přes prošlé úlohy až k první čerstvé
    for ($i = 0; $i < 10; $i++) {
        $st = $pdo->prepare("SELECT id, payload, created_at FROM pos_print_jobs
            WHERE branch_id = ? AND taken_at IS NULL ORDER BY id ASC LIMIT 1");
        $st->execute([$branch]);
        $job = $st->fetch(PDO::FETCH_ASSOC);
        if (!$job) { http_response_code(204); exit; }

        // atomické převzetí — dva pollery si úlohu nerozdělí
        $up = $pdo->prepare("UPDATE pos_print_jobs SET taken_at = NOW() WHERE id = ? AND taken_at IS NULL");
        $up->execute([(int)$job['id']]);
        if ($up->rowCount() === 0) { continue; }   // vzal ji souběžný poll — další kolo

        if (strtotime((string)$job['created_at']) < time() - 180) { continue; }   // prošlá → zahodit

        $bytes = base64_decode((string)$job['payload'], true);
        if ($bytes === false || $bytes === '') { continue; }
        header('Content-Type: application/octet-stream');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }
    http_response_code(204);
} catch (Throwable $e) {
    error_log('print_poll: ' . $e->getMessage());
    http_response_code(204);
}
