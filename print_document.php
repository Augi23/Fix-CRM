<?php
/* Statický náhled dokumentu (výkupní list / zástavní formulář) — jen ke čtení.
   Používá ho podpisová stanice (iframe) a prohlížení/tisk podepsaných dokumentů.
   Podpis z podpisové stanice se vkládá nad levou podpisovou linku. */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'includes/documents.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['tech_id'])) die(__('unauthorized'));

$doc = crmGetDocument((int)($_GET['id'] ?? 0));
if (!$doc) die(__('print_not_found'));

$type = (string)$doc['doc_type'];
$cfg = crmDocTypes()[$type] ?? null;
if (!$cfg) die(__('print_not_found'));

$lang = crmDocLangOrDefault($_GET['lang'] ?? ($doc['lang'] ?? 'cs'));
$date = !empty($doc['doc_date']) ? date('d.m.Y', strtotime((string)$doc['doc_date'])) : date('d.m.Y');
?>
<!DOCTYPE html>
<html lang="<?php echo e($lang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e(__($cfg['title_key'], $lang) . ' ' . (string)$doc['doc_number']); ?></title>
    <link rel="stylesheet" href="assets/css/sf-pro.css?v=<?php echo (int)@filemtime(__DIR__ . '/assets/css/sf-pro.css'); ?>">
    <style>
        body { margin: 0; background: #eceff3; padding: 26px 18px;
               font-family: 'SF Pro Display', -apple-system, "Segoe UI", Arial, sans-serif; }
        <?php echo crmDocumentSheetCss(); ?>
    </style>
</head>
<body>
<?php echo crmRenderDocumentSheet($type, $doc['fields'] ?? [], $lang, 'static', (string)$doc['doc_number'], $date, (int)$doc['id']); ?>
</body>
</html>
