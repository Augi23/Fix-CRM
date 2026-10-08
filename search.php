<?php
/* Všechny výsledky globálního vyhledávání (Enter v horním poli bez vybrané položky).
   Přesná shoda jednoho kódu (zakázka, reklamace, doklad…) → rovnou detail — stejně
   jako dřív Enter ze čtečky čárových kódů. */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/global_search.php';

if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
// skeny čtečkou: věrnostní karta, kód umístění, kód zakázky (i z české klávesnice)
// → rovnou přesměrovat; jinak přeloží „patlanici" zpět do $_GET['search']
require_once 'includes/scan_resolver.php';

$q = trim((string)($_GET['search'] ?? ($_GET['q'] ?? '')));
$scope = ((string)($_GET['scope'] ?? '') === 'accounting') ? 'accounting' : 'all';
if (function_exists('crmIsAccountant') && crmIsAccountant()) { $scope = 'accounting'; }

$res = $q !== '' ? gsRun($pdo, $q, $scope, 50) : ['groups' => [], 'total' => 0, 'mode' => 'none', 'suggestion' => null, 'tokens' => []];

// přesná shoda kódu právě u jednoho záznamu → otevřít detail
if ($res['mode'] === 'exact') {
    $exact = [];
    foreach ($res['groups'] as $g) { foreach ($g['items'] as $it) { if (($it['gs_score'] ?? 0) >= 100) { $exact[] = $it; } } }
    if (count($exact) === 1 && ($exact[0]['url'] ?? '#') !== '#') { header('Location: ' . $exact[0]['url']); exit; }
}

require_once 'includes/header.php';
$tokensF = array_map('gsFold', $res['tokens'] ?? []);
$hl = function (string $text) use ($tokensF): string {
    // zvýraznění všech hledaných slov bez ohledu na diakritiku (pozice ve „složeném" textu)
    $f = gsFold($text); $marks = [];
    foreach ($tokensF as $t) {
        $l = mb_strlen($t); if ($l < 2) { continue; }
        $pos = 0;
        while (($i = mb_strpos($f, $t, $pos)) !== false) { $marks[] = [$i, $l]; $pos = $i + $l; }
    }
    if (!$marks) { return e($text); }
    usort($marks, fn($x, $y) => $x[0] <=> $y[0]);
    $o = ''; $last = 0;
    foreach ($marks as [$i, $l]) {
        if ($i < $last) { continue; }
        $o .= e(mb_substr($text, $last, $i - $last)) . '<mark>' . e(mb_substr($text, $i, $l)) . '</mark>';
        $last = $i + $l;
    }
    return $o . e(mb_substr($text, $last));
};
?>
<style>
  .gsr-wrap { max-width: 980px; margin: 0 auto; }
  .gsr-head { display: flex; align-items: baseline; gap: 12px; margin: 6px 0 18px; }
  .gsr-head h2 { margin: 0; font-size: 22px; font-weight: 600; }
  .gsr-head .n { color: rgba(255,255,255,.5); font-size: 14px; }
  .gsr-dym { margin-bottom: 14px; padding: 10px 14px; border-radius: 12px; background: rgba(10,132,255,.12); color: #cfe4ff; font-size: 13.5px; }
  .gsr-dym a { color: #5ab0ff; font-weight: 600; }
  .gsr-group { margin-bottom: 16px; }
  .gsr-gh { display: flex; align-items: center; gap: 8px; padding: 14px 18px 8px; font-size: 12px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: rgba(255,255,255,.5); }
  .gsr-gh a { margin-left: auto; text-transform: none; letter-spacing: 0; font-weight: 500; color: #5ab0ff; text-decoration: none; }
  .gsr-item { display: flex; align-items: center; gap: 12px; padding: 9px 18px; color: inherit; text-decoration: none; border-top: 1px solid rgba(255,255,255,.05); }
  .gsr-item:hover { background: rgba(10,132,255,.14); color: inherit; }
  .gsr-ic { flex: 0 0 30px; height: 30px; border-radius: 8px; display: grid; place-items: center; color: #fff; font-size: 13px;
            background: linear-gradient(180deg, color-mix(in srgb, var(--c) 85%, #fff), var(--c)); }
  .gsr-t { font-weight: 500; }
  .gsr-s { font-size: 12.5px; color: rgba(255,255,255,.5); }
  .gsr-m { margin-left: auto; font-size: 12px; color: rgba(255,255,255,.45); white-space: nowrap; }
  .gsr-wrap mark { background: rgba(255,214,10,.28); color: inherit; padding: 0 1px; border-radius: 3px; }
  html[data-lg-theme="light"] .gsr-head .n, html[data-lg-theme="light"] .gsr-gh, html[data-lg-theme="light"] .gsr-s, html[data-lg-theme="light"] .gsr-m { color: rgba(0,0,0,.5); }
  html[data-lg-theme="light"] .gsr-item { border-color: rgba(0,0,0,.05); }
  html[data-lg-theme="light"] .gsr-dym { background: rgba(0,122,255,.08); color: #0b3a6e; }
</style>
<div class="container-fluid">
  <div class="gsr-wrap">
    <div class="gsr-head">
      <h2><i class="fas fa-magnifying-glass me-2 text-primary"></i><?php echo $scope === 'accounting' ? 'Hledání v Účetnictví' : 'Výsledky hledání'; ?></h2>
      <?php if ($q !== ''): ?><span class="n">„<?php echo e($q); ?>" · <?php echo (int)$res['total']; ?> výsledků</span><?php endif; ?>
    </div>

    <?php if (!empty($res['suggestion'])): ?>
      <div class="gsr-dym">Měli jste na mysli <a href="search.php?search=<?php echo rawurlencode($res['suggestion']); ?><?php echo $scope === 'accounting' ? '&scope=accounting' : ''; ?>"><?php echo e($res['suggestion']); ?></a>?
        <?php echo $res['mode'] === 'suggestion' ? ' Zobrazuji výsledky pro opravený dotaz.' : ''; ?></div>
    <?php endif; ?>
    <?php if ($res['mode'] === 'similar'): ?>
      <div class="gsr-dym">Přesná shoda nenalezena — zobrazuji podobné výsledky (obsahují část hledaných slov).</div>
    <?php endif; ?>

    <?php if ($q === ''): ?>
      <div class="glass-panel p-4 text-center text-white-50">Napište, co hledáte, do pole nahoře.</div>
    <?php elseif (!$res['groups']): ?>
      <div class="glass-panel p-4 text-center text-white-50"><i class="fas fa-magnifying-glass d-block mb-2" style="font-size:22px;opacity:.6"></i>Nic nenalezeno pro „<?php echo e($q); ?>".</div>
    <?php endif; ?>

    <?php foreach ($res['groups'] as $g): ?>
      <div class="glass-panel gsr-group p-0" style="overflow:hidden">
        <div class="gsr-gh"><i class="fas <?php echo e($g['icon']); ?>"></i><?php echo e($g['label']); ?>
          <span style="font-weight:500;opacity:.7"><?php echo (int)$g['total']; ?></span>
          <?php if (!empty($g['more_url'])): ?><a href="<?php echo e($g['more_url']); ?>">Otevřít v sekci</a><?php endif; ?></div>
        <?php foreach ($g['items'] as $it): ?>
          <a class="gsr-item" href="<?php echo e($it['url']); ?>">
            <span class="gsr-ic" style="--c:<?php echo e($g['color']); ?>"><i class="fas <?php echo e($it['icon'] ?? $g['icon']); ?>"></i></span>
            <span style="min-width:0">
              <div class="gsr-t"><?php echo $hl((string)$it['title']); ?></div>
              <?php if (!empty($it['subtitle'])): ?><div class="gsr-s"><?php echo $hl((string)$it['subtitle']); ?></div><?php endif; ?>
            </span>
            <?php if (!empty($it['meta'])): ?><span class="gsr-m"><?php echo e($it['meta']); ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if ($g['total'] > count($g['items'])): ?>
          <div class="gsr-s" style="padding:8px 18px 12px">… a dalších <?php echo (int)$g['total'] - count($g['items']); ?><?php echo !empty($g['more_url']) ? ' — zobrazíte je v sekci' : ''; ?>.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php require_once 'includes/footer.php'; ?>
