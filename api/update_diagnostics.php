<?php
/**
 * API: Update / Git self-update diagnostics (admin only).
 * Reports the server-side prerequisites for the in-app git self-update so the
 * exact blocker is visible (exec disabled, git missing, repo ownership, token, auth).
 * Never outputs the access token itself — only whether one is configured.
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!crmCanRunUpdates()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$repoRoot = realpath(__DIR__ . '/..') ?: dirname(__DIR__);
$execAvailable = function_exists('exec');

// Process user running PHP
$phpUser = '';
if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
    $pw = @posix_getpwuid(posix_geteuid());
    $phpUser = $pw['name'] ?? (string)@posix_geteuid();
} elseif ($execAvailable) {
    $phpUser = trim((string)@shell_exec('whoami'));
}

// Repo owner on disk
$repoOwner = '';
if (function_exists('fileowner') && function_exists('posix_getpwuid')) {
    $oid = @fileowner($repoRoot . '/.git');
    if ($oid !== false) {
        $pw = @posix_getpwuid($oid);
        $repoOwner = $pw['name'] ?? (string)$oid;
    }
}

$code = 0;
$gitVersion = $execAvailable ? runGitCommand($repoRoot, 'version', $code) : 'exec() disabled';
$gitVersionOk = ($code === 0 && stripos($gitVersion, 'git version') !== false);

// getGitRepoInfo() performs a real authenticated fetch (with refspec, env-header auth) and
// computes ahead/behind from the refreshed tracking ref. We report its result directly rather
// than a separate --dry-run probe (which cannot detect a stale-ref / stuck behind-count).
$info = function_exists('getGitRepoInfo') ? getGitRepoInfo($repoRoot) : [];

$hasLocal = !empty($info['local_short']);
$fetchOk  = $hasLocal && empty($info['error']);

$checks = [
    'exec_available'    => $execAvailable,
    'git_found'         => $gitVersionOk,
    'git_version'       => $gitVersion,
    'php_user'          => $phpUser,
    'repo_root'         => $repoRoot,
    'is_git_repo'       => is_dir($repoRoot . '/.git'),
    'repo_owner'        => $repoOwner,
    // safe.directory=* is injected on every git call, so an owner mismatch is already neutralised.
    'ownership_match'   => ($repoOwner !== '' && $phpUser !== '' && $repoOwner === $phpUser),
    'ownership_handled' => true,
    'branch'            => $info['branch'] ?? 'unknown',
    'local_commit'      => $info['local_short'] ?? '',
    'remote_commit'     => $info['remote_short'] ?? '',
    'remote_url'        => sanitizeGitText((string)($info['remote_url'] ?? '')),
    'token_present'     => githubAccessToken() !== '',
    'fetch_ok'          => $fetchOk,
    'behind_by'         => (int)($info['behind_by'] ?? 0),
    'ahead_by'          => (int)($info['ahead_by'] ?? 0),
    'dirty'             => !empty($info['dirty']),
    'dirty_files'       => (string)($info['dirty_files'] ?? ''),
    'update_available'  => !empty($info['update_available']),
    'error'             => (string)($info['error'] ?? ''),
    'error_detail'      => (string)($info['error_detail'] ?? ''),
];

// Human-readable verdict + next step
$verdict = 'OK — self-update is functional (repository up to date).';
if (!$checks['exec_available']) {
    $verdict = 'BLOCKED: PHP exec() is disabled on this host. Git self-update cannot run here.';
} elseif (!$checks['git_found']) {
    $verdict = 'BLOCKED: git binary not found in PATH for the web user.';
} elseif (!$checks['is_git_repo']) {
    $verdict = 'BLOCKED: deployment is not a git checkout (.git missing). Re-deploy via git clone.';
} elseif (empty($checks['local_commit'])) {
    $verdict = 'BLOCKED: git cannot read the local repository (corrupt/broken). See error_detail.';
} elseif (!$checks['fetch_ok']) {
    $verdict = $checks['token_present']
        ? 'BLOCKED: fetch failed even with a token — check the GITHUB_TOKEN scope (needs Contents: Read).'
        : 'BLOCKED: cannot reach the remote. For a PRIVATE repo add GITHUB_TOKEN=... to .env.';
} elseif ($checks['update_available']) {
    $verdict = 'OK — git works. Update available (behind ' . $checks['behind_by'] . '). Use “Install update”.';
}

// Non-blocking note: local changes no longer prevent the update (git --ff-only handles conflicts).
if (!empty($checks['dirty']) && strpos($verdict, 'BLOCKED') !== 0) {
    $verdict .= ' Note: local changes present — they will not block the update.';
}

echo json_encode([
    'success' => true,
    'verdict' => $verdict,
    'checks'  => $checks,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
