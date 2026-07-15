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
        'message' => !empty($info['error']) ? $info['error'] : 'Failed to load repository git information.',
        'detail'  => (string)($info['error_detail'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (!empty($info['error'])) {
    echo json_encode([
        'success' => false,
        'message' => $info['error'],
        'detail'  => (string)($info['error_detail'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$branch = $info['branch'] ?: 'main';
$localLabel = trim($branch . ' @ ' . ($info['local_short'] ?: '—'));
$remoteLabel = $info['remote_short'] ? trim($branch . ' @ ' . $info['remote_short']) : $localLabel;
$buildLabel = sprintf('ahead %d / behind %d', (int)($info['ahead_by'] ?? 0), (int)($info['behind_by'] ?? 0));

// Lidská čísla verzí (VERSION soubor) — UI ukazuje VŽDY „1.6.2", ne „main @ hash".
// Git štítky zůstávají jen jako doplňkový build údaj (local_build/remote_build).
// Dřív se do local_version/remote_version posílaly git štítky a stránka se po
// „Zkontrolovat aktualizace" přepnula z „Verze 1.6.1" na „vmain @ 1d2cca1".
$localSemver = function_exists('crmAppVersion') ? trim((string)crmAppVersion()) : '';
if (!preg_match('/^\d+\.\d+\.\d+$/', $localSemver)) { $localSemver = ''; }
$remoteSemver = trim((string)($info['remote_version'] ?? ''));

if ($info['behind_by'] > 0) {
    $message = 'A new version is available on git.';
} elseif ($info['ahead_by'] > 0) {
    $message = 'Local repository is ahead of origin.';
} elseif (!empty($info['dirty'])) {
    $message = 'Working tree contains uncommitted changes.';
} else {
    $message = 'Repository is up to date.';
}

$response = [
    'success' => true,
    'message' => $message,
    'hint' => !empty($info['dirty']) ? 'Warning: working tree is not clean.' : '',
    'new_version' => $localSemver ?: $localLabel,
    'current_version' => $localSemver ?: $localLabel,
    'local_version' => $localSemver ?: $localLabel,
    'latest_version' => $remoteSemver ?: ($localSemver ?: $remoteLabel),
    'release_date' => $info['remote_date'] ?: $info['local_date'],
    'build' => $buildLabel,
    'local_build' => $localLabel,
    'remote_build' => $remoteLabel,
    'has_update' => ((int)($info['behind_by'] ?? 0) > 0),
    'dirty' => !empty($info['dirty']),
    'branch' => $branch,
    'ahead_by' => (int)($info['ahead_by'] ?? 0),
    'behind_by' => (int)($info['behind_by'] ?? 0),
    'remote_name' => (string)($info['remote_name'] ?? 'origin'),
    'remote_url' => (string)($info['remote_url'] ?? ''),
    'remote_commit' => $info['remote_commit'] ?: '',
    'remote_version' => $remoteSemver ?: ($localSemver ?: $remoteLabel),
    'changelog' => array_map(static function (array $commit): array {
        $sha = $commit['short'] ?? substr((string)($commit['hash'] ?? ''), 0, 7);
        $date = $commit['date'] ?? '';
        $message = $commit['message'] ?? '';
        $author = $commit['author'] ?? '';
        return [
            // New schema (used by settings.php JS)
            'sha' => $sha,
            'date' => $date,
            'message' => $message,
            'author' => $author,
            // Backward-compatible aliases
            'version' => $sha,
            'release_date' => $date,
            'description' => $message,
        ];
    }, $info['changelog'] ?? []),
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
