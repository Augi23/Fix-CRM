<?php
/**
 * API: Run CRM Update via git pull
 * Uses HTTPS GitHub remote so it works without SSH keys on the web stack.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
    echo json_encode([
        'success' => false,
        'message' => __('csrf_invalid'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!hasPermission('admin_access')) {
    echo json_encode([
        'success' => false,
        'message' => 'You do not have permission to run update.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$info = function_exists('getGitRepoInfo') ? getGitRepoInfo($repoRoot) : [];

if (empty($info) || empty($info['local_commit'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load repository git information.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!empty($info['dirty'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Please commit or discard local changes first, working tree is not clean.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ((int)($info['behind_by'] ?? 0) <= 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Repository is already up to date.',
        'updated' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (empty($info['remote_slug'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Repository has no GitHub remote, update cannot be started.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$remoteUrl = 'https://github.com/' . $info['remote_slug'] . '.git';
$exitCode = 0;
$targetBranch = $info['branch'] ?: 'main';
$pullOutput = runGitCommand($repoRoot, 'pull --ff-only ' . escapeshellarg($remoteUrl) . ' ' . escapeshellarg($targetBranch), $exitCode);

if ($exitCode !== 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Update failed: ' . ($pullOutput ?: 'unknown error'),
        'output' => $pullOutput,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$newInfo = function_exists('getGitRepoInfo') ? getGitRepoInfo($repoRoot) : $info;

echo json_encode([
    'success' => true,
    'message' => 'Repository was updated from git.',
    'updated' => true,
    'previous_version' => trim(($info['branch'] ?? 'main') . ' @ ' . ($info['local_short'] ?? '—')),
    'new_version' => trim(($newInfo['branch'] ?? $info['branch']) . ' @ ' . ($newInfo['local_short'] ?? $info['local_short'])),
    'current_version' => trim(($newInfo['branch'] ?? $info['branch']) . ' @ ' . ($newInfo['local_short'] ?? $info['local_short'])),
    'build' => sprintf('ahead %d / behind %d', (int)($newInfo['ahead_by'] ?? 0), (int)($newInfo['behind_by'] ?? 0)),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
