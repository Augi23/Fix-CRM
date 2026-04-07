<?php
/**
 * API: Check for CRM Update
 * Uses git status/commit info from the current repair-crm repository.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$info = function_exists('getGitRepoInfo') ? getGitRepoInfo($repoRoot) : [];

if (empty($info) || empty($info['local_commit'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Nepodařilo se načíst git informace repozitáře.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$branch = $info['branch'] ?: 'main';
$localLabel = trim($branch . ' @ ' . ($info['local_short'] ?: '—'));
$remoteLabel = $info['remote_short'] ? trim($branch . ' @ ' . $info['remote_short']) : $localLabel;
$buildLabel = sprintf('ahead %d / behind %d', (int)($info['ahead_by'] ?? 0), (int)($info['behind_by'] ?? 0));

if ($info['behind_by'] > 0) {
    $message = 'Na gitu je nová verze k dispozici.';
} elseif ($info['ahead_by'] > 0) {
    $message = 'Lokální repozitář je napřed vůči originu.';
} elseif (!empty($info['dirty'])) {
    $message = 'Pracovní strom obsahuje neuložené změny.';
} else {
    $message = 'Jsi na aktuálním gitu.';
}

$response = [
    'success' => true,
    'message' => $message,
    'hint' => !empty($info['dirty']) ? 'Pozor, pracovní strom není čistý.' : '',
    'new_version' => $localLabel,
    'current_version' => $localLabel,
    'local_version' => $localLabel,
    'latest_version' => $remoteLabel,
    'release_date' => $info['remote_date'] ?: $info['local_date'],
    'build' => $buildLabel,
    'has_update' => ((int)($info['behind_by'] ?? 0) > 0),
    'dirty' => !empty($info['dirty']),
    'branch' => $branch,
    'ahead_by' => (int)($info['ahead_by'] ?? 0),
    'behind_by' => (int)($info['behind_by'] ?? 0),
    'remote_commit' => $info['remote_commit'] ?: '',
    'remote_version' => $remoteLabel,
    'changelog' => array_map(static function (array $commit): array {
        return [
            'version' => $commit['short'] ?? substr((string)($commit['hash'] ?? ''), 0, 7),
            'release_date' => $commit['date'] ?? '',
            'description' => $commit['message'] ?? '',
        ];
    }, $info['changelog'] ?? []),
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
