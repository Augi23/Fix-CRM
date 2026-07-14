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

if (!empty($info['error'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Git repository check failed: ' . $info['error'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// Do NOT hard-block on a "dirty" working tree. `git pull --ff-only` refuses on its own to
// overwrite locally-modified TRACKED files (and untracked files never block it), so git is
// the authority: a non-conflicting local change (e.g. a dev helper script tweaked on the
// server) no longer permanently locks the in-app updater, while a real conflict is surfaced
// verbatim from git below.
$dirtyWarning = !empty($info['dirty'])
    ? ('Local changes present (left untouched): ' . trim((string)($info['dirty_files'] ?? '')))
    : '';

if ((int)($info['behind_by'] ?? 0) <= 0) {
    echo json_encode([
        'success' => true,
        'message' => 'Repository is already up to date.',
        'updated' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$remoteName = (string)($info['remote_name'] ?? 'origin');
$exitCode = 0;
$targetBranch = $info['branch'] ?: 'main';
// Pull by remote NAME; token auth (when configured) is injected via env header (gitBeginAuth),
// keeping it out of argv. Stays fast-forward-only.
$authActive = function_exists('gitBeginAuth') ? gitBeginAuth($repoRoot, $remoteName) : false;
$pullOutput = runGitCommand($repoRoot, 'pull --ff-only ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($targetBranch), $exitCode);
if ($authActive && function_exists('gitEndAuth')) { gitEndAuth(); }
$pullOutput = function_exists('sanitizeGitText') ? sanitizeGitText($pullOutput) : $pullOutput;

if ($exitCode !== 0) {
    // AUTO-OPRAVA: server má být vždy shodný s originem (aktualizace jsou pull-only,
    // nikdo server needituje ručně). Když fast-forward zablokuje zastaralý lokálně
    // změněný SLEDOVANÝ soubor, odložíme ho do git stash (obnovitelné) a zkusíme JEDNOU
    // znovu. Nesledované soubory (uploady, zálohy) stash nechává být.
    $recovered = false;
    if (!empty($info['dirty'])) {
        $stashCode = 0;
        runGitCommand($repoRoot, 'stash push -m ' . escapeshellarg('afx-auto-pred-aktualizaci'), $stashCode);
        if ($stashCode === 0) {
            $authActive2 = function_exists('gitBeginAuth') ? gitBeginAuth($repoRoot, $remoteName) : false;
            $pullRetry = runGitCommand($repoRoot, 'pull --ff-only ' . escapeshellarg($remoteName) . ' ' . escapeshellarg($targetBranch), $exitCode);
            if ($authActive2 && function_exists('gitEndAuth')) { gitEndAuth(); }
            $pullOutput = function_exists('sanitizeGitText') ? sanitizeGitText($pullRetry) : $pullRetry;
            $recovered = ($exitCode === 0);
        }
    }
    if (!$recovered) {
        $failMsg = 'Update failed: ' . ($pullOutput ?: 'unknown error');
        if ($dirtyWarning !== '') {
            $failMsg .= ' — ' . $dirtyWarning . '. Discard or commit those changes, then retry.';
        }
        echo json_encode([
            'success' => false,
            'message' => $failMsg,
            'output' => $pullOutput,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    // Aktualizace prošla až po automatickém odložení lokálních změn serveru.
    $dirtyWarning = 'Zastaralé lokální změny na serveru byly odloženy do git stash a aktualizace proběhla.';
}

$newInfo = function_exists('getGitRepoInfo') ? getGitRepoInfo($repoRoot) : $info;

if (function_exists('crmAuditLog')) {
    crmAuditLog('system.update', [
        'entity_type' => 'system',
        'summary' => 'Aktualizace CRM: ' . trim(($info['local_short'] ?? '?')) . ' → ' . trim(($newInfo['local_short'] ?? '?')),
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Repository was updated from git.',
    'updated' => true,
    'previous_version' => trim(($info['branch'] ?? 'main') . ' @ ' . ($info['local_short'] ?? '—')),
    'new_version' => trim(($newInfo['branch'] ?? $info['branch']) . ' @ ' . ($newInfo['local_short'] ?? $info['local_short'])),
    'current_version' => trim(($newInfo['branch'] ?? $info['branch']) . ' @ ' . ($newInfo['local_short'] ?? $info['local_short'])),
    'build' => sprintf('ahead %d / behind %d', (int)($newInfo['ahead_by'] ?? 0), (int)($newInfo['behind_by'] ?? 0)),
    'warning' => $dirtyWarning !== '' ? $dirtyWarning : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
