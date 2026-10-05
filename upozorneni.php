<?php
/**
 * CHYTRÁ UPOZORNĚNÍ — přehled a nastavení.
 *
 * Každý zaměstnanec: svoje upozornění, kanály (Telegram / appka / e-mail /
 * SMS), předstih připomínky směny, co nechce dostávat, zkušební zpráva.
 * Vedení (admin + Boss): pravidla, časy, globální kanály, plánovač, simulace
 * „nanečisto" a log odeslaného. Logika je v upozorneni/lib.php.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'upozorneni/lib.php';
$afxPageTitle = 'Upozornění';
require_once 'includes/header.php';

afxNotifyEnsureSchema();
afxNotifyRememberBaseUrl();

$canManage = function_exists('crmCanManageSettings') && crmCanManageSettings();
$myKey  = afxNotifyMyKey();
$myKeys = afxNotifyMyKeys();
$me     = $myKey !== '' ? afxNotifyRecipient($myKey) : null;
$prefs  = afxNotifyPrefs($myKey);
$cfg    = afxNotifyConfig(true);
$rules  = afxNotifyRules();
$now    = new DateTimeImmutable();
$inbox  = afxNotifyInbox($myKeys, 40);
[$unread] = afxNotifyUnread($myKeys);
// otevřením stránky je vše viděné; nová zůstávají při téhle návštěvě zvýrazněná
if ($unread) { afxNotifyMarkRead($myKeys); }
$upcoming = afxNotifyUpcoming($now, $canManage ? null : $myKey);
$botUser  = trim((string)get_setting('fixer_bot_username', ''));
$pushOn   = function_exists('apnsConfigured') && apnsConfigured();
$tgOn     = defined('TG_BOT_TOKEN') && TG_BOT_TOKEN !== '';

// pravidla, která se přihlášeného týkají (ztlumit jde jen ta)
$myRole = $me['role'] ?? getCurrentStaffRole();
$isLead = in_array($myRole, ['boss', 'admin', 'manager'], true) || $canManage;
$relevant = array_filter($rules, static function ($r, $id) use ($isLead, $myRole) {
    $staffRules = ['shift_reminder', 'shift_evening', 'shift_uncovered', 'shift_gap', 'shift_week', 'shift_change',
                   'shift_noshow', 'pos_open', 'pos_close', 'orders_stale', 'complaints_deadline'];
    if ($isLead) { return true; }
    if ($myRole === 'accountant') { return $id === 'invoices_overdue'; }
    return in_array($id, $staffRules, true);
}, ARRAY_FILTER_USE_BOTH);

$log = [];
$lastRun = $trigger = '';
$tickUrl = '';
if ($canManage) {
    try {
        $log = $pdo->query('SELECT * FROM smart_notify_log ORDER BY id DESC LIMIT 80')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $log = []; }
    $lastRun = (string)get_setting('smart_notify_last_run', '');
    $trigger = (string)get_setting('smart_notify_last_trigger', '');
    $tickUrl = afxNotifyBaseUrl() . '/upozorneni/tick.php?key=' . afxNotifyTickToken();
}
$levelIcon = ['urgent' => 'fa-triangle-exclamation text-danger', 'warn' => 'fa-circle-exclamation text-warning', 'info' => 'fa-circle-info text-info'];
$chanLabel = ['crm' => 'CRM', 'telegram' => 'Telegram', 'push' => 'Push', 'email' => 'E-mail', 'sms' => 'SMS'];
$ago = static function (string $dt) use ($now): string {
    $s = $now->getTimestamp() - strtotime($dt);
    if ($s < 60) { return 'teď'; }
    if ($s < 3600) { return 'před ' . intdiv($s, 60) . ' min'; }
    if ($s < 86400) { return 'před ' . intdiv($s, 3600) . ' h'; }
    return date('j. n. H:i', strtotime($dt));
};
?>

<div class="container-fluid px-3 px-md-4 py-4 un">

    <!-- ── Hlavička ──────────────────────────────────────────────────────── -->
    <div class="un-hero mb-4">
        <div class="un-hero-glow" aria-hidden="true"></div>
        <div class="d-flex flex-wrap align-items-center gap-3 position-relative">
            <div class="un-hero-icon"><i class="fas fa-bell"></i><?php if ($unread): ?><b><?php echo (int)$unread; ?></b><?php endif; ?></div>
            <div class="me-auto">
                <h4 class="mb-1">Chytrá upozornění</h4>
                <div class="un-sub">CRM samo hlídá rozpis, pokladnu, zakázky, reklamace i sklad a dá vědět správnému člověku ve správnou chvíli.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="un-pill <?php echo ($me && $me['telegram']) ? 'ok' : 'off'; ?>"><i class="fab fa-telegram"></i>Telegram <?php echo ($me && $me['telegram']) ? 'propojen' : 'nepropojen'; ?></span>
                <span class="un-pill ok" title="<?php echo $pushOn ? 'iPhone: push přes APNs · Android: appka se ptá sama' : 'Android: appka se ptá sama · iPhone: chybí APNs klíč (Nastavení → Integrace)'; ?>"><i class="fas fa-mobile-screen"></i>Appka <?php echo $pushOn ? 'iOS + Android' : 'Android'; ?></span>
                <?php if ($canManage): ?>
                <span class="un-pill <?php echo ($lastRun && strtotime($lastRun) > time() - 600) ? 'ok' : 'warn'; ?>" title="Poslední kontrola pravidel">
                    <i class="fas fa-heart-pulse"></i><?php echo $lastRun ? 'Kontrola ' . e($ago($lastRun)) : 'Zatím neběželo'; ?></span>
                <?php endif; ?>
                <a class="btn btn-sm btn-outline-light" href="rozpis.php"><i class="fas fa-calendar-days me-1"></i>Rozpis</a>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- ── Moje upozornění ───────────────────────────────────────────── -->
        <div class="col-xl-7">
            <section class="glass-panel border-secondary p-3 p-md-4 h-100">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <h5 class="mb-0 me-auto"><i class="fas fa-inbox me-2 text-info"></i>Moje upozornění</h5>
                    <?php if ($unread): ?><span class="un-pill ok"><i class="fas fa-bolt"></i><?php echo (int)$unread; ?> nových</span><?php endif; ?>
                </div>
                <?php if (!$inbox): ?>
                    <div class="un-empty"><i class="fas fa-bell-slash"></i>Zatím tu nic není. Až se bude dít něco důležitého, objeví se to tady (a podle nastavení i v Telegramu nebo appce).</div>
                <?php else: ?>
                <ul class="un-feed">
                    <?php foreach ($inbox as $m):
                        $lvl = (string)$m['level']; $isNew = $m['read_at'] === null;
                        $ico = $rules[$m['rule']]['icon'] ?? 'fa-bell';
                    ?>
                    <li class="un-item lvl-<?php echo e($lvl); ?><?php echo $isNew ? ' is-new' : ''; ?>">
                        <div class="un-item-ico"><i class="fas <?php echo e($ico); ?>"></i></div>
                        <div class="un-item-body">
                            <div class="un-item-head">
                                <span class="un-item-title"><?php echo e((string)$m['title']); ?></span>
                                <span class="un-item-time"><?php echo e($ago((string)$m['created_at'])); ?></span>
                            </div>
                            <div class="un-item-text"><?php echo nl2br(e((string)$m['body'])); ?></div>
                            <?php if ((string)$m['url'] !== ''): ?>
                                <a class="un-item-link" href="<?php echo e((string)$m['url']); ?>">Otevřít <i class="fas fa-arrow-right"></i></a>
                            <?php endif; ?>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>

        <!-- ── Moje nastavení ────────────────────────────────────────────── -->
        <div class="col-xl-5">
            <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
                <h5 class="mb-3"><i class="fas fa-sliders me-2 text-info"></i>Jak chci dostávat upozornění</h5>
                <?php if ($myKey === ''): ?>
                    <div class="un-empty">Účet nemá vazbu na zaměstnance — osobní nastavení není kam uložit.</div>
                <?php else: ?>
                <form id="unPrefs" autocomplete="off">
                    <div class="un-chan-grid mb-3">
                        <?php foreach ([
                            ['ch_telegram', 'fab fa-telegram', 'Telegram', $me && $me['telegram'] ? 'propojený' : 'chybí propojení', $tgOn && !empty($cfg['global']['ch_telegram'])],
                            ['ch_push', 'fas fa-mobile-screen', 'Appka', 'iPhone i Android, se zvukem', !empty($cfg['global']['ch_push'])],
                            ['ch_email', 'fas fa-envelope', 'E-mail', ($me['email'] ?? '') !== '' ? (string)$me['email'] : 'bez e-mailu', !empty($cfg['global']['ch_email'])],
                            ['ch_sms', 'fas fa-comment-sms', 'SMS', 'jen kritické věci', !empty($cfg['global']['ch_sms'])],
                        ] as [$k, $ico, $lbl, $hint, $avail]): ?>
                        <label class="un-chan<?php echo $avail ? '' : ' is-na'; ?>" title="<?php echo $avail ? '' : 'Kanál není zapnutý vedením / nenastavený'; ?>">
                            <input type="checkbox" name="<?php echo $k; ?>" value="1" <?php echo !empty($prefs[$k]) ? 'checked' : ''; ?>>
                            <span class="un-chan-box"><i class="<?php echo $ico; ?>"></i><b><?php echo $lbl; ?></b><small><?php echo e($hint); ?></small></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="un-note mb-3"><i class="fas fa-circle-info me-1"></i>V CRM se upozornění ukazují vždy. Telegram se v noci (<?php echo e($cfg['global']['quiet_from'] . '–' . $cfg['global']['quiet_to']); ?>) doručuje potichu, kromě naléhavých věcí.
                    <?php if ($me && !$me['telegram'] && $tgOn): ?>
                        <br><b>Propojení Telegramu:</b> nech si od vedení v Nastavení → Zaměstnanci vyplnit své Telegram uživatelské jméno<?php echo $botUser !== '' ? ' a pak napiš botovi <a href="https://t.me/' . e($botUser) . '" target="_blank" rel="noopener">@' . e($botUser) . '</a> cokoli' : ''; ?> — propojí se samo.
                    <?php endif; ?></div>

                    <div class="row g-3 mb-3">
                        <div class="col-sm-7">
                            <label class="form-label un-lbl" for="unMin">Připomínka před směnou</label>
                            <select class="form-select" name="reminder_minutes" id="unMin">
                                <option value="">Výchozí (<?php echo (int)$cfg['rules']['shift_reminder']['minutes']; ?> min)</option>
                                <?php foreach ([15, 30, 45, 60, 90, 120, 180, 240] as $m): ?>
                                    <option value="<?php echo $m; ?>" <?php echo ($prefs['reminder_minutes'] !== null && (int)$prefs['reminder_minutes'] === $m) ? 'selected' : ''; ?>>
                                        <?php echo $m < 60 ? $m . ' min' : ($m / 60) . ' h'; ?> předem</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-5 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" name="evening_before" id="unEve" value="1" <?php echo !empty($prefs['evening_before']) ? 'checked' : ''; ?>>
                                <label class="form-check-label un-lbl" for="unEve">Večer předem</label>
                            </div>
                        </div>
                    </div>

                    <div class="un-lbl mb-2">Co chci dostávat</div>
                    <div class="un-mute mb-3">
                        <?php foreach ($relevant as $id => $r): ?>
                        <label class="un-mute-row">
                            <input class="form-check-input" type="checkbox" name="want[]" value="<?php echo e($id); ?>" <?php echo in_array($id, $prefs['muted'], true) ? '' : 'checked'; ?>>
                            <i class="fas <?php echo e($r['icon']); ?>"></i><span><?php echo e($r['title']); ?></span>
                            <?php if (empty($cfg['rules'][$id]['enabled'])): ?><em>vypnuto vedením</em><?php endif; ?>
                        </label>
                        <?php endforeach; ?>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-info" data-busy="Ukládám…"><i class="fas fa-check me-1"></i>Uložit</button>
                        <button type="button" class="btn btn-outline-light" id="unTest" data-busy="Posílám…"><i class="fas fa-paper-plane me-1"></i>Poslat zkušební</button>
                    </div>
                </form>
                <?php endif; ?>
            </section>

            <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
                <h5 class="mb-1"><i class="fas fa-music me-2 text-info"></i>Zvuky upozornění</h5>
                <div class="un-note mb-3">Vlastní sada AppleFix — stejné zvuky zní v appce na iPhonu i Androidu a tady v CRM. Každý typ upozornění poznáš po zvuku, aniž bys koukal na telefon.</div>
                <div class="un-sounds">
                    <?php foreach (AFX_NOTIFY_SOUNDS as $sk => [$sl, $sd]): ?>
                    <button type="button" class="un-sound snd-<?php echo e($sk); ?>" data-sound="<?php echo e($sk); ?>" aria-label="Přehrát zvuk: <?php echo e($sl); ?>">
                        <span class="un-sound-play"><i class="fas fa-play"></i></span>
                        <span class="un-sound-txt"><b><?php echo e($sl); ?></b><small><?php echo e($sd); ?></small></span>
                        <span class="un-eq" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
                    </button>
                    <?php endforeach; ?>
                </div>
                <div class="un-note mt-3"><i class="fas fa-mobile-screen me-1"></i>V appce potřebuješ verzi <b>iOS 1.3.0</b> / <b>Android 1.4.0</b> nebo novější. Na Androidu si hlasitost a vibrace jednotlivých typů můžeš doladit v Nastavení telefonu → Aplikace → AppleFix CRM → Oznámení.</div>
            </section>

            <section class="glass-panel border-secondary p-3 p-md-4">
                <h5 class="mb-3"><i class="fas fa-clock me-2 text-info"></i><?php echo $canManage ? 'Naplánováno (36 h)' : 'Co mi přijde'; ?></h5>
                <?php if (!$upcoming): ?>
                    <div class="un-empty small-empty">V nejbližších hodinách žádná připomínka směny.</div>
                <?php else: ?>
                <ul class="un-upc">
                    <?php foreach ($upcoming as $u): ?>
                    <li><span class="un-upc-at"><?php echo e(afxNotifyRelDay($u['at']->format('Y-m-d'), $now) . ' ' . $u['at']->format('H:i')); ?></span>
                        <span class="un-upc-t"><?php echo $canManage ? '<b>' . e($u['to']) . '</b> · ' : ''; ?><?php echo e($u['title']); ?></span></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </section>
        </div>
    </div>

    <?php if ($canManage): ?>
    <!-- ══ VEDENÍ ═══════════════════════════════════════════════════════════ -->
    <div class="un-section-title"><i class="fas fa-user-shield me-2"></i>Nastavení pro celou firmu <span>vidí jen admin a Boss</span></div>

    <form id="unConfig" autocomplete="off">
        <div class="row g-4">
            <div class="col-xl-8">
                <?php foreach (AFX_NOTIFY_GROUPS as $gid => [$gLabel, $gIcon]): ?>
                <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
                    <h5 class="mb-3"><i class="fas <?php echo e($gIcon); ?> me-2 text-info"></i><?php echo e($gLabel); ?></h5>
                    <div class="un-rules">
                    <?php foreach ($rules as $id => $r): if ($r['group'] !== $gid) { continue; } $rc = $cfg['rules'][$id]; ?>
                        <article class="un-rule<?php echo !empty($rc['enabled']) ? ' is-on' : ''; ?>" data-rule="<?php echo e($id); ?>">
                            <div class="un-rule-head">
                                <div class="un-rule-ico"><i class="fas <?php echo e($r['icon']); ?>"></i></div>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="un-rule-title"><?php echo e($r['title']); ?><?php if (!empty($r['sms'])): ?> <span class="un-tag">SMS</span><?php endif; ?></div>
                                    <div class="un-rule-aud"><i class="fas fa-user-group me-1"></i><?php echo e($r['audience']); ?></div>
                                </div>
                                <div class="form-check form-switch m-0">
                                    <input class="form-check-input un-rule-on" type="checkbox" role="switch" data-k="enabled" aria-label="Zapnout: <?php echo e($r['title']); ?>" <?php echo !empty($rc['enabled']) ? 'checked' : ''; ?>>
                                </div>
                            </div>
                            <div class="un-rule-desc"><?php echo e($r['desc']); ?></div>
                            <?php if ($r['params']): ?>
                            <div class="un-params">
                                <?php foreach ($r['params'] as $p => $def): $v = $rc[$p]; $fid = 'p_' . $id . '_' . $p; ?>
                                <div class="un-param un-param-<?php echo e($def['type']); ?>">
                                    <?php if ($def['type'] === 'bool'): ?>
                                        <div class="form-check form-switch m-0">
                                            <input class="form-check-input" type="checkbox" role="switch" id="<?php echo e($fid); ?>" data-k="<?php echo e($p); ?>" <?php echo $v ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="<?php echo e($fid); ?>"><?php echo e($def['label']); ?></label>
                                        </div>
                                    <?php else: ?>
                                        <label for="<?php echo e($fid); ?>"><?php echo e($def['label']); ?></label>
                                        <?php if ($def['type'] === 'int'): ?>
                                            <input type="number" class="form-control form-control-sm" id="<?php echo e($fid); ?>" data-k="<?php echo e($p); ?>"
                                                   min="<?php echo (int)$def['min']; ?>" max="<?php echo (int)$def['max']; ?>" value="<?php echo (int)$v; ?>">
                                        <?php elseif ($def['type'] === 'time'): ?>
                                            <input type="time" class="form-control form-control-sm" id="<?php echo e($fid); ?>" data-k="<?php echo e($p); ?>" value="<?php echo e((string)$v); ?>">
                                        <?php elseif ($def['type'] === 'times'): ?>
                                            <input type="text" class="form-control form-control-sm" id="<?php echo e($fid); ?>" data-k="<?php echo e($p); ?>" value="<?php echo e((string)$v); ?>" placeholder="14:00, 20:00">
                                        <?php elseif ($def['type'] === 'day'): ?>
                                            <select class="form-select form-select-sm" id="<?php echo e($fid); ?>" data-k="<?php echo e($p); ?>">
                                                <?php foreach (AFX_NOTIFY_DAYS as $dk => $dl): ?>
                                                    <option value="<?php echo $dk; ?>" <?php echo $v === $dk ? 'selected' : ''; ?>><?php echo $dl; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                    </div>
                </section>
                <?php endforeach; ?>
            </div>

            <div class="col-xl-4">
                <div class="un-sticky">
                <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
                    <h5 class="mb-3"><i class="fas fa-tower-broadcast me-2 text-info"></i>Kanály</h5>
                    <div class="form-check form-switch mb-3 un-master">
                        <input class="form-check-input" type="checkbox" role="switch" id="g_enabled" data-g="enabled" <?php echo !empty($cfg['global']['enabled']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="g_enabled"><b>Chytrá upozornění zapnutá</b></label>
                    </div>
                    <?php foreach ([
                        ['ch_telegram', 'Telegram', $tgOn ? 'bot je nastavený' : 'chybí token bota (Nastavení → Integrace)'],
                        ['ch_push', 'Appka iOS + Android (se zvukem)', $pushOn ? 'iPhone přes APNs, Android sám' : 'Android sám; pro iPhone chybí APNs klíč'],
                        ['ch_email', 'E-mail', 'kdo si ho zapne a má vyplněný e-mail'],
                        ['ch_sms', 'SMS (GoSMS, placené)', 'jen kritické: nikdo na směně'],
                    ] as [$k, $lbl, $hint]): ?>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="g_<?php echo $k; ?>" data-g="<?php echo $k; ?>" <?php echo !empty($cfg['global'][$k]) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="g_<?php echo $k; ?>"><?php echo $lbl; ?><small class="un-hint"><?php echo e($hint); ?></small></label>
                    </div>
                    <?php endforeach; ?>
                    <div class="row g-2 mt-2">
                        <div class="col-6"><label class="un-lbl" for="g_qf">Tichý Telegram od</label>
                            <input type="time" class="form-control form-control-sm" id="g_qf" data-g="quiet_from" value="<?php echo e((string)$cfg['global']['quiet_from']); ?>"></div>
                        <div class="col-6"><label class="un-lbl" for="g_qt">do</label>
                            <input type="time" class="form-control form-control-sm" id="g_qt" data-g="quiet_to" value="<?php echo e((string)$cfg['global']['quiet_to']); ?>"></div>
                        <div class="col-12"><label class="un-lbl" for="g_ae">E-mail administrátora</label>
                            <input type="email" class="form-control form-control-sm" id="g_ae" data-g="admin_email" value="<?php echo e((string)$cfg['global']['admin_email']); ?>" placeholder="admin@firma.cz"></div>
                        <div class="col-12"><label class="un-lbl" for="g_ap">Telefon administrátora (SMS)</label>
                            <input type="tel" class="form-control form-control-sm" id="g_ap" data-g="admin_phone" value="<?php echo e((string)$cfg['global']['admin_phone']); ?>" placeholder="+420…"></div>
                        <div class="col-12"><label class="un-lbl" for="g_bu">Adresa CRM v odkazech</label>
                            <input type="url" class="form-control form-control-sm" id="g_bu" data-g="base_url" value="<?php echo e((string)$cfg['global']['base_url']); ?>" placeholder="<?php echo e(afxNotifyBaseUrl()); ?>"></div>
                    </div>
                    <button type="submit" class="btn btn-info w-100 mt-3" data-busy="Ukládám…"><i class="fas fa-check me-1"></i>Uložit nastavení</button>
                </section>

                <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
                    <h5 class="mb-3"><i class="fas fa-gears me-2 text-info"></i>Plánovač</h5>
                    <div class="un-note mb-2">Kontrola běží sama, když je CRM otevřené (i z tiskového agenta pobočky). Aby chodily i ranní připomínky, když ještě nikdo nic nemá otevřené, nastav jednu z možností:</div>
                    <div class="un-lbl">Systémový cron (každou minutu)</div>
                    <input type="text" class="form-control form-control-sm un-mono mb-2" readonly onclick="this.select()"
                           value="* * * * * php <?php echo e(__DIR__); ?>/upozorneni/cron.php > /dev/null 2>&1">
                    <div class="un-lbl">nebo externí cron (např. cron-job.org) na adresu</div>
                    <div class="input-group input-group-sm mb-2">
                        <input type="text" class="form-control un-mono" id="unTick" readonly onclick="this.select()" value="<?php echo e($tickUrl); ?>">
                        <button type="button" class="btn btn-outline-light" id="unToken" title="Nový klíč"><i class="fas fa-rotate"></i></button>
                    </div>
                    <div class="un-note">Poslední kontrola: <b><?php echo $lastRun ? e(date('j. n. H:i:s', strtotime($lastRun))) : '—'; ?></b><?php echo $trigger !== '' ? ' (' . e($trigger) . ')' : ''; ?></div>
                    <div class="d-flex gap-2 mt-3">
                        <button type="button" class="btn btn-outline-info flex-fill" id="unDry" data-busy="Počítám…"><i class="fas fa-flask me-1"></i>Nanečisto</button>
                        <button type="button" class="btn btn-outline-warning flex-fill" id="unRun" data-busy="Kontroluji…"><i class="fas fa-play me-1"></i>Spustit teď</button>
                    </div>
                    <div id="unRunOut" class="mt-3"></div>
                </section>
                </div>
            </div>
        </div>
    </form>

    <section class="glass-panel border-secondary p-3 p-md-4 mb-4">
        <h5 class="mb-3"><i class="fas fa-list-check me-2 text-info"></i>Odeslaná upozornění <span class="un-hint d-inline">posledních 80</span></h5>
        <?php if (!$log): ?>
            <div class="un-empty small-empty">Zatím se nic neodeslalo.</div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-dark table-sm align-middle un-log mb-0">
                <thead><tr><th>Kdy</th><th>Komu</th><th>Upozornění</th><th>Kanály</th></tr></thead>
                <tbody>
                <?php foreach ($log as $l): ?>
                    <tr>
                        <td class="text-nowrap"><?php echo e(date('j. n. H:i', strtotime((string)$l['created_at']))); ?></td>
                        <td class="text-nowrap"><?php echo e((string)$l['recipient_name']); ?></td>
                        <td><i class="fas <?php echo e($levelIcon[$l['level']] ?? $levelIcon['info']); ?> me-1"></i>
                            <span title="<?php echo e((string)$l['body']); ?>"><?php echo e((string)$l['title']); ?></span>
                            <?php if ((string)$l['error'] !== ''): ?><div class="un-err"><?php echo e((string)$l['error']); ?></div><?php endif; ?></td>
                        <td class="text-nowrap"><?php foreach (array_filter(explode(',', (string)$l['channels'])) as $c): ?><span class="un-ch"><?php echo e($chanLabel[$c] ?? $c); ?></span><?php endforeach; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div>

<style>
/* fix-crm-v2.css nastavuje p/span/label na 18px !important — drobné texty
   v kartách si tu velikost drží samy, jinak by se rozsypaly. */
.un .un-sub, .un .un-note, .un .un-hint, .un .un-rule-desc, .un .un-rule-aud, .un .un-item-text,
.un .un-item-time, .un .un-params label, .un .un-lbl, .un .un-chan small, .un .un-mute-row span,
.un .un-mute-row em, .un .un-upc span, .un .un-log, .un .un-log span, .un .un-ch, .un .un-err,
.un .un-pill, .un .un-tag, .un .un-section-title span, .un .un-empty, .un .form-check-label { font-size: 14px !important; }
.un .un-rule-desc, .un .un-item-text { font-size: 14.5px !important; }
.un .un-hint, .un .un-rule-aud, .un .un-item-time, .un .un-tag, .un .un-ch, .un .un-err, .un .un-chan small { font-size: 12.5px !important; }
.un .un-item-title, .un .un-rule-title { font-size: 16px !important; }
/* style.css dává KAŽDÉMU panelu ve sloupci height:100% — tady jich je ve sloupci
   víc pod sebou a přetékaly by přes sebe. Výšku na celý řádek má jen přehled (h-100). */
.un .row > [class*='col-'] > .glass-panel:not(.h-100) { height: auto; }
.un-upc { max-height: 340px; overflow: auto; }

.un-hero { position: relative; overflow: hidden; padding: 22px 24px; border-radius: 22px;
    border: 1px solid rgba(255,255,255,.10); background: linear-gradient(135deg, rgba(10,132,255,.16), rgba(28,28,30,.94) 45%, rgba(191,90,242,.12)); }
.un-hero-glow { position: absolute; inset: -40% -10% auto auto; width: 420px; height: 420px; border-radius: 50%;
    background: radial-gradient(circle, rgba(13,202,240,.28), transparent 65%); filter: blur(10px);
    animation: unFloat 9s ease-in-out infinite alternate; pointer-events: none; }
@keyframes unFloat { from { transform: translate(0,0) scale(1); } to { transform: translate(-60px,40px) scale(1.15); } }
.un-hero-icon { position: relative; width: 58px; height: 58px; border-radius: 18px; display: grid; place-items: center;
    font-size: 24px; color: #fff; background: linear-gradient(140deg, #0a84ff, #5e5ce6); box-shadow: 0 10px 30px rgba(10,132,255,.35); }
.un-hero-icon i { animation: unRing 4s ease-in-out infinite; transform-origin: 50% 10%; }
@keyframes unRing { 0%,86%,100% { transform: rotate(0); } 89% { transform: rotate(14deg); } 92% { transform: rotate(-12deg); } 95% { transform: rotate(6deg); } }
.un-hero-icon b { position: absolute; top: -6px; right: -6px; min-width: 22px; height: 22px; padding: 0 6px; border-radius: 999px;
    background: #ff453a; color: #fff; font-size: 12px; display: grid; place-items: center; box-shadow: 0 0 0 3px rgba(28,28,30,.9); }
.un-sub { color: rgba(255,255,255,.6); max-width: 620px; }
.un-pill { display: inline-flex; align-items: center; gap: 7px; padding: 6px 12px; border-radius: 999px;
    border: 1px solid rgba(255,255,255,.14); background: rgba(255,255,255,.05); color: rgba(255,255,255,.75); white-space: nowrap; }
.un-pill.ok i { color: #30d158; } .un-pill.off i { color: rgba(255,255,255,.35); } .un-pill.warn i { color: #ffd60a; }

.un-empty { display: flex; align-items: center; gap: 12px; padding: 22px; border-radius: 14px; color: rgba(255,255,255,.45);
    border: 1px dashed rgba(255,255,255,.14); }
.un-empty i { font-size: 22px; opacity: .6; }
.un-empty.small-empty { padding: 14px; }

.un-feed { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
@media (min-width: 1200px) { .un-feed { max-height: 760px; overflow: auto; padding-right: 4px; } }
.un-item { --lc: #0dcaf0; display: flex; gap: 12px; padding: 12px 14px; border-radius: 14px;
    background: rgba(255,255,255,.035); border: 1px solid rgba(255,255,255,.08); position: relative;
    animation: unIn .35s ease both; }
.un-item.lvl-warn { --lc: #ffd60a; } .un-item.lvl-urgent { --lc: #ff453a; }
.un-item.is-new { background: color-mix(in srgb, var(--lc) 9%, rgba(28,28,30,.9)); border-color: color-mix(in srgb, var(--lc) 40%, transparent); }
.un-item.is-new::before { content: ''; position: absolute; left: -1px; top: 12px; bottom: 12px; width: 3px; border-radius: 3px; background: var(--lc); }
@keyframes unIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
.un-item-ico { flex: none; width: 36px; height: 36px; border-radius: 11px; display: grid; place-items: center;
    background: color-mix(in srgb, var(--lc) 18%, transparent); color: var(--lc); }
.un-item-body { min-width: 0; flex: 1; }
.un-item-head { display: flex; gap: 10px; align-items: baseline; }
.un-item-title { font-weight: 650; margin-right: auto; }
.un-item-time { color: rgba(255,255,255,.4); white-space: nowrap; }
.un-item-text { color: rgba(255,255,255,.72); margin-top: 3px; line-height: 1.45; word-break: break-word; }
.un-item-link { display: inline-flex; gap: 6px; align-items: center; margin-top: 6px; font-size: 14px; color: var(--lc); text-decoration: none; }
.un-item-link:hover { text-decoration: underline; }

.un-chan-grid { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px; }
.un-chan { cursor: pointer; margin: 0; }
.un-chan input { position: absolute; opacity: 0; pointer-events: none; }
.un-chan-box { display: grid; grid-template-columns: 26px 1fr; grid-template-rows: auto auto; column-gap: 8px; padding: 10px 12px;
    border-radius: 13px; border: 1px solid rgba(255,255,255,.12); background: rgba(255,255,255,.03); transition: all .18s ease; }
.un-chan-box i { grid-row: span 2; align-self: center; font-size: 19px; color: rgba(255,255,255,.4); }
.un-chan-box small { color: rgba(255,255,255,.45); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.un-chan input:checked + .un-chan-box { border-color: rgba(13,202,240,.65); background: rgba(13,202,240,.10); box-shadow: 0 0 0 1px rgba(13,202,240,.25) inset; }
.un-chan input:checked + .un-chan-box i { color: #0dcaf0; }
.un-chan input:focus-visible + .un-chan-box { outline: 2px solid #0dcaf0; outline-offset: 2px; }
.un-chan.is-na .un-chan-box { opacity: .5; }
.un-note { color: rgba(255,255,255,.55); line-height: 1.5; }
.un-note a { color: #0dcaf0; }
.un-lbl { color: rgba(255,255,255,.7); font-weight: 600; }
.un-mute { display: flex; flex-direction: column; gap: 2px; max-height: 300px; overflow: auto; padding-right: 4px; }
.un-mute-row { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 9px; cursor: pointer; margin: 0; }
.un-mute-row:hover { background: rgba(255,255,255,.05); }
.un-mute-row .form-check-input { margin: 0; flex: none; }
.un-mute-row i { width: 18px; text-align: center; color: rgba(255,255,255,.45); }
.un-mute-row span { flex: 1; }
.un-mute-row em { color: #ffd60a; font-style: normal; opacity: .75; }

.un-upc { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.un-upc li { display: flex; gap: 12px; align-items: baseline; }
.un-upc-at { flex: none; min-width: 92px; font-variant-numeric: tabular-nums; color: #0dcaf0; font-weight: 600; }
.un-upc-t { color: rgba(255,255,255,.75); }

.un-section-title { margin: 36px 0 16px; font-size: 19px; font-weight: 700; display: flex; align-items: center; flex-wrap: wrap; gap: 6px; }
.un-section-title span { font-weight: 500; color: rgba(255,255,255,.4); margin-left: 6px; }

.un-rules { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 12px; }
@media (max-width: 1100px) { .un-rules { grid-template-columns: minmax(0,1fr); } }
.un-rule { padding: 14px; border-radius: 16px; border: 1px solid rgba(255,255,255,.09); background: rgba(28,28,30,.7);
    transition: border-color .2s ease, background .2s ease, opacity .2s ease; opacity: .62; }
.un-rule.is-on { opacity: 1; border-color: rgba(13,202,240,.35); background: linear-gradient(160deg, rgba(13,202,240,.08), rgba(28,28,30,.85) 55%); }
.un-rule-head { display: flex; align-items: flex-start; gap: 12px; }
.un-rule-ico { flex: none; width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center;
    background: rgba(255,255,255,.07); color: rgba(255,255,255,.55); transition: all .2s ease; }
.un-rule.is-on .un-rule-ico { background: linear-gradient(140deg, #0a84ff, #0dcaf0); color: #fff; box-shadow: 0 6px 18px rgba(13,202,240,.25); }
.un-rule-title { font-weight: 650; line-height: 1.3; }
.un-rule-aud { color: rgba(255,255,255,.45); margin-top: 2px; }
.un-rule-desc { color: rgba(255,255,255,.62); margin: 10px 0 0; line-height: 1.45; }
.un-tag { display: inline-block; padding: 1px 7px; border-radius: 999px; background: rgba(255,214,10,.15); color: #ffd60a; font-weight: 700; vertical-align: 2px; }
.un-params { display: flex; flex-wrap: wrap; gap: 10px 14px; margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,.07); }
.un-param { display: flex; flex-direction: column; gap: 4px; min-width: 120px; flex: 1 1 140px; }
.un-param label { color: rgba(255,255,255,.55); }
.un-param-bool { justify-content: flex-end; flex-basis: 100%; }
.un-rule:not(.is-on) .un-params { pointer-events: none; }
.form-switch .form-check-input { cursor: pointer; }
.un-master { padding: 10px 12px 10px 3em; border-radius: 12px; background: rgba(13,202,240,.08); border: 1px solid rgba(13,202,240,.25); }
.un-hint { display: block; color: rgba(255,255,255,.42); }
.un-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 12.5px !important; }
.un-sticky { position: sticky; top: 16px; }
@media (max-width: 1199px) { .un-sticky { position: static; } }

.un-log td, .un-log th { border-color: rgba(255,255,255,.07) !important; background: transparent !important; }
.un-log th { color: rgba(255,255,255,.45); font-weight: 600; }
.un-ch { display: inline-block; padding: 1px 7px; margin: 1px 3px 1px 0; border-radius: 999px; background: rgba(255,255,255,.08); }
.un-err { color: #ff7b72; margin-top: 2px; }
.un-run-item { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.04); border: 1px solid rgba(255,255,255,.08); margin-bottom: 8px; }
.un-run-item b { display: block; }
.un-run-item div { white-space: pre-line; color: rgba(255,255,255,.65); font-size: 13.5px; margin-top: 4px; }
.un-run-item small { color: #0dcaf0; font-size: 12.5px; }
/* zvuky */
.un .un-sound-txt b { font-size: 15px !important; } .un .un-sound-txt small { font-size: 12.5px !important; }
.un-sounds { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px; }
@media (max-width: 480px) { .un-sounds { grid-template-columns: minmax(0,1fr); } }
.un-sound { --sc: #0dcaf0; display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 14px; text-align: left;
    border: 1px solid color-mix(in srgb, var(--sc) 28%, rgba(255,255,255,.08)); background: color-mix(in srgb, var(--sc) 7%, rgba(28,28,30,.8));
    color: #fff; cursor: pointer; transition: transform .15s ease, border-color .2s ease, background .2s ease; min-width: 0; }
.un-sound:hover { border-color: color-mix(in srgb, var(--sc) 60%, transparent); transform: translateY(-1px); }
.un-sound:focus-visible { outline: 2px solid var(--sc); outline-offset: 2px; }
.un-sound.snd-shift { --sc: #0dcaf0; } .un-sound.snd-info { --sc: #64d2ff; } .un-sound.snd-warn { --sc: #ffd60a; }
.un-sound.snd-urgent { --sc: #ff453a; } .un-sound.snd-done { --sc: #30d158; } .un-sound.snd-cash { --sc: #bf5af2; }
.un-sound-play { flex: none; width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-size: 12px;
    background: var(--sc); color: #0c0c0d; box-shadow: 0 0 16px color-mix(in srgb, var(--sc) 45%, transparent); }
.un-sound-txt { display: flex; flex-direction: column; min-width: 0; flex: 1; }
.un-sound-txt small { color: rgba(255,255,255,.5); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.un-eq { display: flex; align-items: flex-end; gap: 2px; height: 16px; opacity: 0; transition: opacity .2s; }
.un-eq i { width: 3px; height: 4px; border-radius: 2px; background: var(--sc); }
.un-sound.is-playing .un-eq { opacity: 1; }
.un-sound.is-playing .un-eq i { animation: unEq .7s ease-in-out infinite alternate; }
.un-sound.is-playing .un-eq i:nth-child(2) { animation-delay: .15s; } .un-sound.is-playing .un-eq i:nth-child(3) { animation-delay: .3s; }
.un-sound.is-playing .un-eq i:nth-child(4) { animation-delay: .45s; }
@keyframes unEq { from { height: 3px; } to { height: 16px; } }
@media (prefers-reduced-motion: reduce) { .un-hero-glow, .un-hero-icon i, .un-item, .un-eq i { animation: none !important; } }
</style>

<script>
(function () {
    var CSRF = '<?php echo e($_SESSION['csrf_token'] ?? ''); ?>';
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
    var post = function (data, done, btn) {
        data.csrf_token = CSRF;
        var html = btn ? btn.innerHTML : '';
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>' + (btn.dataset.busy || 'Pracuji…'); }
        $.post('upozorneni/api.php', data, function (r) { done(r || {}); })
            .fail(function () { done({ success: false, message: 'Server neodpověděl — zkus to znovu.' }); })
            .always(function () { if (btn) { btn.disabled = false; btn.innerHTML = html; } });
    };
    var flash = function (r) { showAlert(esc(r.message || (r.success ? 'Hotovo.' : 'Nepovedlo se.'))); };

    // osobní nastavení
    var prefs = document.getElementById('unPrefs');
    if (prefs) {
        var collect = function () {
            var d = { action: 'prefs', reminder_minutes: prefs.reminder_minutes.value,
                evening_before: prefs.evening_before.checked ? 1 : 0, muted: [] };
            ['ch_telegram', 'ch_push', 'ch_email', 'ch_sms'].forEach(function (k) { d[k] = prefs[k].checked ? 1 : 0; });
            prefs.querySelectorAll('input[name="want[]"]').forEach(function (c) { if (!c.checked) { d.muted.push(c.value); } });
            return d;
        };
        prefs.addEventListener('submit', function (e) {
            e.preventDefault();
            post(collect(), flash, prefs.querySelector('button[type=submit]'));
        });
        document.getElementById('unTest').addEventListener('click', function () {
            var b = this;
            post(collect(), function () { post({ action: 'test' }, function (r) { flash(r); }, b); });
        });
    }

    // otevřením stránky se upozornění berou jako přečtená (server je už označil)
    var badge = document.getElementById('afxSmartCount');
    if (badge) { badge.hidden = true; }
    <?php if ($unread && $inbox): ?>
    try { localStorage.setItem('afx_smart_seen', '<?php echo (int)$inbox[0]['id']; ?>'); } catch (e) {}
    <?php endif; ?>

    // ukázka zvuků
    document.querySelectorAll('.un-sound').forEach(function (b) {
        b.addEventListener('click', function () {
            document.querySelectorAll('.un-sound.is-playing').forEach(function (x) { x.classList.remove('is-playing'); });
            var a = window.afxSmartSound ? window.afxSmartSound(b.dataset.sound) : null;
            b.classList.add('is-playing');
            var stop = function () { b.classList.remove('is-playing'); };
            if (a) { a.addEventListener('ended', stop); a.addEventListener('error', stop); }
            setTimeout(stop, 2600);
        });
    });

    // nastavení pro vedení
    var cfgForm = document.getElementById('unConfig');
    if (cfgForm) {
        cfgForm.querySelectorAll('.un-rule-on').forEach(function (sw) {
            sw.addEventListener('change', function () { sw.closest('.un-rule').classList.toggle('is-on', sw.checked); });
        });
        cfgForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var conf = { global: {}, rules: {} };
            cfgForm.querySelectorAll('[data-g]').forEach(function (el) {
                conf.global[el.dataset.g] = el.type === 'checkbox' ? el.checked : el.value;
            });
            cfgForm.querySelectorAll('.un-rule').forEach(function (card) {
                var r = {};
                card.querySelectorAll('[data-k]').forEach(function (el) { r[el.dataset.k] = el.type === 'checkbox' ? el.checked : el.value; });
                conf.rules[card.dataset.rule] = r;
            });
            post({ action: 'config', config: JSON.stringify(conf) }, flash, cfgForm.querySelector('button[type=submit]'));
        });

        var out = document.getElementById('unRunOut');
        var run = function (dry, btn) {
            post({ action: 'run', dry: dry ? 1 : 0 }, function (r) {
                if (!r.success) { out.innerHTML = '<div class="un-note">' + esc(r.message) + '</div>'; return; }
                var items = r.items || [];
                var h = '<div class="un-lbl mb-2">' + esc(r.message) + '</div>';
                if (dry && !items.length) { h += '<div class="un-note">Právě teď by neodešlo nic — všechno je v pořádku, nebo už to bylo posláno.</div>'; }
                items.forEach(function (it) {
                    h += '<div class="un-run-item"><small>→ ' + esc(it.to) + '</small><b>' + esc(it.title) + '</b><div>' + esc(it.body) + '</div></div>';
                });
                out.innerHTML = h;
                if (!dry) { setTimeout(function () { location.reload(); }, 1600); }
            }, btn);
        };
        document.getElementById('unDry').addEventListener('click', function () { run(true, this); });
        document.getElementById('unRun').addEventListener('click', function () {
            var b = this;
            showConfirm('Spustit kontrolu a rovnou odeslat, co je splatné?', function () { run(false, b); });
        });
        document.getElementById('unToken').addEventListener('click', function () {
            var b = this;
            showConfirm('Vygenerovat nový klíč? Externí cron bude potřeba přenastavit.', function () {
                post({ action: 'token' }, function (r) {
                    if (r.success && r.token) {
                        var i = document.getElementById('unTick');
                        i.value = i.value.replace(/key=[a-f0-9]+/, 'key=' + r.token);
                    }
                    flash(r);
                }, b);
            });
        });
    }
}());
</script>

<?php require_once 'includes/footer.php'; ?>
