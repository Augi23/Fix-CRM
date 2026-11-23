<?php
/**
 * ÚKOLY — předávání práce mezi lidmi (v4.4.0).
 *
 * Nahoře rychlé zadání (co, komu, do kdy), pod ním tři pohledy:
 *   Pro mě      — co mám udělat, seřazené podle termínu jako denní plán
 *   Zadal jsem  — co jsem dal ostatním a v jakém je to stavu
 *   Tým         — jen vedení: kdo má co na stole
 * Pravidla a oprávnění drží ukoly/lib.php; stránka se překresluje z dat,
 * která vrací ukoly/api.php, takže se stav mění bez načítání.
 */
require_once 'includes/config.php';
require_once 'includes/functions.php';
require_once 'ukoly/lib.php';
$afxPageTitle = 'Úkoly';
require_once 'includes/header.php';

afxTaskEnsureSchema();
$ukBoot = afxTaskSnapshot(true);
?>

<div class="container-fluid px-3 px-md-4 py-4 uk" id="uk">
<div class="uk-wrap">

    <header class="uk-head">
        <h4 class="mb-1"><i class="fas fa-list-check me-2 text-info"></i>Úkoly</h4>
        <div class="uk-sub">Co si nestihnete říct při předání směny. Zadej úkol, kolega ho odškrtne a ty vidíš, jak je daleko.</div>
    </header>

    <!-- ── Zadání úkolu ──────────────────────────────────────────────────── -->
    <form class="uk-new" id="ukNew" autocomplete="off" novalidate>
        <div class="uk-new-top">
            <i class="fas fa-plus uk-new-plus" aria-hidden="true"></i>
            <input type="text" id="ukTitle" maxlength="200" placeholder="Co je potřeba udělat?" aria-label="Co je potřeba udělat?">
            <button type="button" class="uk-x" id="ukClose" aria-label="Zavřít zadání" hidden><i class="fas fa-xmark"></i></button>
        </div>
        <div class="uk-new-more" id="ukMore" hidden>
            <textarea id="ukDetail" rows="2" maxlength="4000" placeholder="Podrobnosti, pokud jsou potřeba" aria-label="Podrobnosti"></textarea>

            <div class="uk-field">
                <div class="uk-label" id="ukPeopleLabel">Pro koho</div>
                <div class="uk-people" id="ukPeople" role="group" aria-labelledby="ukPeopleLabel"></div>
            </div>

            <div class="uk-field">
                <div class="uk-label" id="ukDueLabel">Do kdy</div>
                <div class="uk-duepick" id="ukDue" role="group" aria-labelledby="ukDueLabel">
                    <button type="button" class="uk-chip" data-due="today">Dnes</button>
                    <button type="button" class="uk-chip" data-due="tomorrow">Zítra</button>
                    <button type="button" class="uk-chip" data-due="week" id="ukDueWeek">Do neděle</button>
                    <button type="button" class="uk-chip" data-due="date">Jiný den</button>
                    <button type="button" class="uk-chip" data-due="none">Bez termínu</button>
                    <input type="date" id="ukDate" class="uk-in" aria-label="Datum termínu" hidden>
                    <label class="uk-time" id="ukTimeWrap">v&nbsp;<input type="time" id="ukTime" class="uk-in" aria-label="Čas termínu (nepovinné)"></label>
                </div>
            </div>

            <div class="uk-new-foot">
                <button type="button" class="uk-chip uk-urgent" id="ukUrgent" aria-pressed="false"><i class="fas fa-bolt"></i>Spěchá</button>
                <div class="uk-new-hint" id="ukHint" aria-live="polite"></div>
                <button type="button" class="uk-btn" id="ukCancel">Zrušit</button>
                <button type="submit" class="uk-btn uk-btn-main" id="ukSubmit">Zadat úkol</button>
            </div>
        </div>
    </form>

    <!-- ── Pohledy ───────────────────────────────────────────────────────── -->
    <div class="uk-tabs" id="ukTabs" role="tablist" aria-label="Pohled na úkoly"></div>

    <div id="ukList" aria-live="polite"></div>
</div>
</div>

<div class="uk-toast" id="ukToast" role="status" hidden></div>

<style>
/* fix-crm-v2.css nastavuje span, label, .btn… na 18px !important pro celé CRM,
   proto si drobné texty na téhle stránce drží velikost samy (taky !important). */
.uk {
    --uk-ink: var(--fg1, #fff);
    --uk-mute: var(--fg2, rgba(255,255,255,.55));
    --uk-faint: var(--fg3, rgba(255,255,255,.42));
    --uk-line: rgba(255,255,255,.075);
    --uk-hover: rgba(255,255,255,.045);
    --uk-field: rgba(0,0,0,.24);
    --uk-accent: #0a84ff;
    --uk-ok: #30d158;
    --uk-warn: #ff9f0a;
    --uk-bad: #ff5a52;
}
html[data-lg-theme="light"] .uk {
    --uk-line: rgba(0,0,0,.075);
    --uk-hover: rgba(0,0,0,.03);
    --uk-field: #f4f5f7;
    --uk-accent: #0a6cdf;
    --uk-ok: #1c8f3f;
    --uk-warn: #b25e00;
    --uk-bad: #d3261a;
}
:where(.uk) :is(span, label, small, p) { font-size: inherit !important; }
.uk-wrap { max-width: 1180px; margin: 0 auto; }
.uk-wrap.is-wide { max-width: 1640px; }   /* tým: víc sloupců vedle sebe */
.uk-head { margin-bottom: 18px; }
.uk .uk-sub { font-size: 15px !important; color: var(--uk-mute); max-width: 84ch; }

/* společný podklad „okna" — stejné úrovně jako zbytek CRM (panels-modern.css) */
.uk-new, .uk-group, .uk-col, .uk-empty {
    background: var(--pm-l1-bg); border: 1px solid var(--pm-l1-border);
    border-radius: 20px; box-shadow: var(--pm-l1-shadow);
}

/* ── zadání ── */
.uk-new { padding: 6px 8px; margin-bottom: 22px; transition: border-color .18s ease; }
.uk-new.is-open { padding-bottom: 14px; }
.uk-new.is-edit { border-color: color-mix(in srgb, var(--uk-accent) 60%, transparent); }
.uk-new-top { display: flex; align-items: center; gap: 12px; padding: 0 10px; }
.uk-new-plus { width: 30px; height: 30px; flex: none; display: grid; place-items: center; border-radius: 999px;
    font-size: 14px !important; color: #fff; background: var(--uk-accent); }
.uk-new.is-edit .uk-new-plus::before { content: "\f303"; }
.uk #ukTitle { flex: 1; min-width: 0; height: 56px; border: 0; outline: 0; background: transparent;
    color: var(--uk-ink); font-size: 19px !important; font-weight: 600; letter-spacing: -.01em; }
.uk #ukTitle::placeholder { color: var(--uk-faint); font-weight: 500; }
.uk-x { width: 34px; height: 34px; flex: none; border: 0; border-radius: 999px; background: transparent;
    color: var(--uk-mute); font-size: 16px !important; cursor: pointer; }
.uk-x:hover { background: var(--uk-hover); color: var(--uk-ink); }
.uk-new-more { padding: 0 10px; }
.uk #ukDetail, .uk .uk-in, .uk .uk-say input {
    border: 1px solid var(--uk-line); border-radius: 12px; background: var(--uk-field);
    color: var(--uk-ink); font-size: 16px !important; outline: 0; }
.uk #ukDetail { display: block; width: 100%; padding: 11px 14px; resize: vertical; min-height: 54px; line-height: 1.45; }
.uk #ukDetail::placeholder, .uk .uk-say input::placeholder { color: var(--uk-faint); }
.uk #ukDetail:focus, .uk .uk-in:focus, .uk .uk-say input:focus { border-color: var(--uk-accent); }
.uk-field { display: grid; grid-template-columns: 96px minmax(0, 1fr); gap: 6px 14px; margin-top: 16px; align-items: start; }
.uk .uk-label { font-size: 14px !important; font-weight: 600; color: var(--uk-mute); padding-top: 9px; }
.uk-people { display: flex; flex-direction: column; gap: 8px; }
.uk-pgroup { display: grid; grid-template-columns: 92px minmax(0, 1fr); gap: 4px 0; align-items: start; }
.uk .uk-pgroup-name { font-size: 13px !important; color: var(--uk-faint); padding-top: 10px; }
.uk-pgroup-chips { display: flex; flex-wrap: wrap; gap: 7px; }
.uk-duepick { display: flex; flex-wrap: wrap; align-items: center; gap: 7px; }

.uk .uk-chip { display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 14px;
    border: 1px solid var(--uk-line); border-radius: 999px; background: transparent;
    color: var(--uk-ink); font-size: 15px !important; font-weight: 500; cursor: pointer; white-space: nowrap;
    transition: background .15s ease, border-color .15s ease; }
.uk .uk-chip:hover { background: var(--uk-hover); border-color: color-mix(in srgb, var(--uk-ink) 28%, transparent); }
.uk .uk-chip.is-on { background: color-mix(in srgb, var(--uk-accent) 20%, transparent);
    border-color: var(--uk-accent); font-weight: 600; }
.uk .uk-chip.uk-person { padding-left: 5px; }
.uk .uk-chip.uk-person.is-on { background: color-mix(in srgb, var(--c) 22%, transparent); border-color: var(--c); }
.uk .uk-chip.uk-urgent i { color: var(--uk-faint); }
.uk .uk-chip.uk-urgent.is-on { background: color-mix(in srgb, var(--uk-bad) 18%, transparent); border-color: var(--uk-bad); }
.uk .uk-chip.uk-urgent.is-on i { color: var(--uk-bad); }
.uk .uk-in { height: 38px; padding: 0 12px; }
.uk .uk-time { display: inline-flex; align-items: center; gap: 6px; margin: 0; font-size: 15px !important; color: var(--uk-mute); }
.uk-new-foot { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-top: 18px;
    padding-top: 14px; border-top: 1px solid var(--uk-line); }
.uk .uk-new-hint { flex: 1; min-width: 120px; font-size: 14px !important; color: var(--uk-mute); }
.uk .uk-new-hint.is-bad { color: var(--uk-bad); }
.uk .uk-btn { height: 40px; padding: 0 18px; border-radius: 12px; border: 1px solid var(--uk-line);
    background: transparent; color: var(--uk-ink); font-size: 15px !important; font-weight: 600; cursor: pointer; }
.uk .uk-btn:hover { background: var(--uk-hover); }
.uk .uk-btn-main { background: var(--uk-accent); border-color: var(--uk-accent); color: #fff; }
.uk .uk-btn-main:hover { background: color-mix(in srgb, var(--uk-accent) 86%, #fff); }
.uk .uk-btn-ok { border-color: color-mix(in srgb, var(--uk-ok) 55%, transparent); color: var(--uk-ok); }
.uk .uk-btn-bad:hover { border-color: var(--uk-bad); color: var(--uk-bad); }
.uk .uk-btn[disabled] { opacity: .55; cursor: default; }
.uk .uk-btn-s { height: 34px; padding: 0 13px; font-size: 14px !important; border-radius: 10px; }

/* avatar člověka; kroužek kolem říká, jak je úkol daleko */
.uk-av { --c: #888; position: relative; width: 28px; height: 28px; flex: none; display: grid; place-items: center;
    border-radius: 999px; font-size: 11px !important; font-weight: 700; letter-spacing: .01em; color: #fff;
    background: color-mix(in srgb, var(--c) 78%, #000); }
.uk-av.is-lg { width: 36px; height: 36px; font-size: 13px !important; }
.uk-av.is-ring { margin: 3px; }
.uk-av.is-ring::after { content: ""; position: absolute; inset: -5px; border-radius: 999px; border: 2px dashed var(--uk-faint); }
.uk-av.is-ring.is-seen::after { border-style: solid; border-color: var(--uk-accent); }
.uk-av.is-ring.is-done::after { border-style: solid; border-color: var(--uk-ok); }
.uk-av.is-done::before { content: "\f00c"; font-family: "Font Awesome 6 Free"; font-weight: 900; position: absolute;
    right: -7px; bottom: -6px; z-index: 1; width: 17px; height: 17px; display: grid; place-items: center;
    border-radius: 999px; font-size: 9px !important; color: #fff; background: var(--uk-ok); }

/* ── přepínač pohledů ── */
.uk-tabs { display: inline-flex; gap: 2px; padding: 4px; margin-bottom: 20px; max-width: 100%; overflow-x: auto;
    border-radius: 14px; background: var(--pm-seg-track); border: 1px solid var(--pm-seg-track-border); }
.uk .uk-tab { display: inline-flex; align-items: center; gap: 8px; height: 40px; padding: 0 16px; border: 0;
    border-radius: 10px; background: transparent; color: var(--pm-seg-text); white-space: nowrap;
    font-size: 16px !important; font-weight: 600; cursor: pointer; }
.uk .uk-tab:hover { color: var(--pm-seg-text-hover); }
.uk .uk-tab.is-on { background: var(--pm-seg-thumb); box-shadow: var(--pm-seg-thumb-shadow); color: var(--uk-ink); }
.uk .uk-tab b { min-width: 22px; height: 22px; padding: 0 6px; display: inline-grid; place-items: center;
    border-radius: 999px; font-size: 12.5px !important; font-weight: 700; font-variant-numeric: tabular-nums;
    background: color-mix(in srgb, var(--uk-ink) 12%, transparent); }
.uk .uk-tab b.is-bad { background: var(--uk-bad); color: #fff; }

/* ── denní plán: vlevo kdy, vpravo co ── */
.uk-sec { display: grid; grid-template-columns: 150px minmax(0, 1fr); gap: 4px 22px; margin-bottom: 22px; }
.uk-sec-head { padding-top: 14px; }
.uk-sec-head h5 { margin: 0; font-weight: 700 !important; letter-spacing: -.02em !important; }
.uk .uk-sec-sub { font-size: 14px !important; color: var(--uk-mute); margin-top: 2px; }
.uk-sec.is-over .uk-sec-head h5 { color: var(--uk-bad) !important; }
.uk-sec.is-done .uk-sec-head h5 { color: var(--uk-mute) !important; }
.uk-group { overflow: hidden; }
.uk-sec.is-over .uk-group { border-color: color-mix(in srgb, var(--uk-bad) 38%, transparent); }

/* ── řádek úkolu ── */
.uk-task { position: relative; border-top: 1px solid var(--uk-line); }
.uk-task:first-child { border-top: 0; }
.uk-row { display: flex; align-items: flex-start; gap: 14px; padding: 13px 16px; }
.uk-task.is-urgent::before { content: ""; position: absolute; left: 0; top: 10px; bottom: 10px; width: 3px;
    border-radius: 0 3px 3px 0; background: var(--uk-bad); }
.uk-lead { flex: none; width: 34px; display: grid; place-items: center; padding-top: 1px; }
.uk .uk-check { width: 28px; height: 28px; border-radius: 999px; border: 2px solid var(--uk-faint); background: transparent;
    display: grid; place-items: center; color: transparent; font-size: 13px !important; cursor: pointer; padding: 0;
    transition: border-color .15s ease, background .15s ease, color .15s ease, transform .2s ease; }
.uk .uk-check:hover { border-color: var(--uk-ok); color: var(--uk-ok); }
.uk .uk-check.is-on { border-color: var(--uk-ok); background: var(--uk-ok); color: #fff; }
.uk .uk-check.is-pop { transform: scale(1.18); }
.uk .uk-check[disabled] { cursor: default; opacity: .6; }
.uk-body { flex: 1; min-width: 0; border: 0; background: transparent; padding: 0; text-align: left; color: inherit; cursor: pointer; }
.uk .uk-title { display: block; font-size: 17px !important; font-weight: 600; line-height: 1.35; color: var(--uk-ink); letter-spacing: -.01em;
    overflow-wrap: anywhere; }
.uk-task.is-done .uk-title { color: var(--uk-mute); text-decoration: line-through; text-decoration-color: var(--uk-faint); }
.uk .uk-flag { display: inline-flex; align-items: center; gap: 5px; margin-right: 8px; padding: 1px 8px; border-radius: 999px;
    font-size: 12.5px !important; font-weight: 700; font-style: normal; vertical-align: 2px;
    color: var(--uk-bad); background: color-mix(in srgb, var(--uk-bad) 15%, transparent); }
.uk .uk-newdot { display: inline-block; margin-right: 8px; padding: 1px 8px; border-radius: 999px; vertical-align: 2px;
    font-size: 12.5px !important; font-weight: 700; font-style: normal; color: #fff; background: var(--uk-accent); }
.uk .uk-meta { display: block; margin-top: 3px; font-size: 14px !important; line-height: 1.4; color: var(--uk-mute); }
.uk .uk-meta b { font-weight: 600; color: color-mix(in srgb, var(--uk-ink) 82%, transparent); }
.uk .uk-meta i { font-size: 12px !important; margin-right: 3px; }
.uk .uk-meta .uk-sep { margin: 0 7px; opacity: .5; }
.uk .uk-st-new { color: var(--uk-faint); }
.uk .uk-st-seen { color: var(--uk-accent); }
.uk .uk-st-done { color: var(--uk-ok); }
.uk-side { flex: none; display: flex; align-items: center; gap: 8px; padding-top: 2px; }
.uk .uk-due { display: inline-flex; align-items: center; gap: 6px; height: 28px; padding: 0 11px; border-radius: 999px;
    font-size: 13.5px !important; font-weight: 600; white-space: nowrap; font-variant-numeric: tabular-nums;
    color: var(--uk-mute); background: color-mix(in srgb, var(--uk-ink) 8%, transparent); }
.uk .uk-due.is-today { color: var(--uk-warn); background: color-mix(in srgb, var(--uk-warn) 15%, transparent); }
.uk .uk-due.is-over { color: var(--uk-bad); background: color-mix(in srgb, var(--uk-bad) 15%, transparent); }
.uk .uk-due.is-done { color: var(--uk-ok); background: color-mix(in srgb, var(--uk-ok) 14%, transparent); }
.uk-caret { width: 26px; height: 26px; display: grid; place-items: center; color: var(--uk-faint); font-size: 12px !important;
    transition: transform .2s ease; }
.uk-task.is-open .uk-caret { transform: rotate(180deg); }
.uk-task.is-open > .uk-row { background: var(--uk-hover); }

/* ── rozbalený úkol ── */
.uk-detail { padding: 4px 16px 16px 64px; background: var(--uk-hover); }
.uk .uk-text { font-size: 16px !important; line-height: 1.5; color: var(--uk-ink); white-space: pre-wrap; overflow-wrap: anywhere;
    max-width: 70ch; margin-bottom: 12px; }
.uk-facts { display: flex; flex-wrap: wrap; gap: 6px 26px; margin: 0 0 12px; }
.uk .uk-facts div { font-size: 14px !important; color: var(--uk-ink); }
.uk .uk-facts dt { display: inline; font-weight: 500; color: var(--uk-mute); margin-right: 6px; }
.uk .uk-facts dd { display: inline; margin: 0; font-weight: 600; }
.uk-log { list-style: none; margin: 0 0 12px; padding: 0; display: flex; flex-direction: column; gap: 8px; }
.uk-log li { display: flex; gap: 10px; align-items: flex-start; }
.uk .uk-log-ico { flex: none; width: 28px; height: 28px; display: grid; place-items: center; border-radius: 999px;
    font-size: 12px !important; color: var(--uk-mute); background: color-mix(in srgb, var(--uk-ink) 9%, transparent); }
.uk .uk-log-ico.is-ok { color: #fff; background: var(--uk-ok); }
.uk .uk-log-ico.is-back { color: #fff; background: var(--uk-warn); }
.uk .uk-log-body { font-size: 15px !important; line-height: 1.45; color: var(--uk-ink); overflow-wrap: anywhere; min-width: 0; padding-top: 3px; }
.uk .uk-log-body b { font-weight: 600; }
.uk .uk-log-when { font-size: 13px !important; color: var(--uk-faint); margin-left: 8px; white-space: nowrap; }
.uk .uk-log-note { display: block; white-space: pre-wrap; }
.uk-say { display: flex; gap: 8px; margin-bottom: 12px; }
.uk .uk-say input { flex: 1; min-width: 0; height: 40px; padding: 0 14px; }
.uk-acts { display: flex; flex-wrap: wrap; gap: 8px; }

/* ── tým: kdo má co na stole ── */
.uk-teambar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 18px; margin-bottom: 16px; }
.uk .uk-teambar-sum { font-size: 15px !important; color: var(--uk-mute); margin-right: auto; }
.uk .uk-teambar-sum b { color: var(--uk-ink); font-weight: 700; }
.uk .uk-teambar-sum b.is-bad { color: var(--uk-bad); }
.uk-board { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px; align-items: start; }
.uk-col { overflow: hidden; }
.uk-col-head { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-bottom: 1px solid var(--uk-line); }
.uk .uk-col-name { font-size: 16.5px !important; font-weight: 700; color: var(--uk-ink); line-height: 1.25; }
.uk .uk-col-role { font-size: 13px !important; color: var(--uk-mute); }
.uk .uk-col-n { margin-left: auto; display: flex; gap: 6px; }
.uk .uk-pill { height: 24px; min-width: 24px; padding: 0 8px; display: inline-grid; place-items: center; border-radius: 999px;
    font-size: 12.5px !important; font-weight: 700; font-variant-numeric: tabular-nums;
    color: var(--uk-ink); background: color-mix(in srgb, var(--uk-ink) 11%, transparent); }
.uk .uk-pill.is-bad { color: #fff; background: var(--uk-bad); }
.uk-col .uk-row { padding: 11px 14px; gap: 10px; flex-wrap: wrap; }
.uk-col .uk-lead { width: 14px; padding-top: 8px; }
.uk-col .uk-detail { padding-left: 38px; }
.uk-col .uk-side { width: 100%; padding-left: 24px; padding-top: 0; }
.uk-col .uk-side:empty, .uk-col .uk-caret { display: none; }
.uk-dot { width: 10px; height: 10px; border-radius: 999px; border: 2px dashed var(--uk-faint); }
.uk-dot.is-seen { border: 0; background: var(--uk-accent); }
.uk-dot.is-done { border: 0; background: var(--uk-ok); }
.uk .uk-idle { margin-top: 18px; font-size: 14.5px !important; color: var(--uk-mute); }

/* ── prázdno, hlášky ── */
.uk-empty { padding: 44px 24px; text-align: center; }
.uk-empty i { font-size: 30px !important; color: var(--uk-faint); margin-bottom: 14px; display: block; }
.uk .uk-empty-h { font-size: 19px !important; font-weight: 700; color: var(--uk-ink); }
.uk .uk-empty-p { font-size: 15px !important; color: var(--uk-mute); margin-top: 4px; }
.uk .uk-more { display: block; width: 100%; padding: 12px; border: 0; border-top: 1px solid var(--uk-line);
    background: transparent; color: var(--uk-accent); font-size: 15px !important; font-weight: 600; cursor: pointer; }
.uk .uk-more:hover { background: var(--uk-hover); }

.uk-toast { position: fixed; left: 50%; bottom: 28px; transform: translateX(-50%); z-index: 1080;
    display: flex; align-items: center; gap: 14px; max-width: calc(100vw - 32px); padding: 12px 14px 12px 18px;
    border-radius: 14px; background: #1f2126; border: 1px solid rgba(255,255,255,.12);
    box-shadow: 0 18px 40px -12px rgba(0,0,0,.6); color: #fff; font-size: 15px !important; }
.uk-toast[hidden] { display: none; }
.uk-toast button { border: 0; border-radius: 9px; padding: 6px 12px; background: rgba(255,255,255,.14); color: #fff;
    font-size: 14px !important; font-weight: 600; cursor: pointer; }
.uk-toast button:hover { background: rgba(255,255,255,.24); }

.uk :is(button, input, textarea, [tabindex]):focus-visible { outline: 2px solid var(--uk-accent); outline-offset: 2px; }

@media (max-width: 860px) {
    .uk-sec { grid-template-columns: minmax(0, 1fr); gap: 8px; }
    .uk-sec-head { display: flex; align-items: baseline; gap: 10px; padding-top: 0; }
    .uk-field { grid-template-columns: minmax(0, 1fr); gap: 4px; }
    .uk .uk-label { padding-top: 0; }
    .uk-row { flex-wrap: wrap; padding: 12px 12px; gap: 10px; }
    .uk-body { flex-basis: calc(100% - 48px); }
    .uk-side { padding-left: 44px; flex: 1 1 100%; }
    .uk-side .uk-caret { margin-left: auto; }
    .uk-detail { padding-left: 16px; }
    .uk-toast { bottom: 92px; }   /* nad spodní lištou */
    .uk-tabs { display: flex; }
    .uk .uk-tab { flex: 1 1 auto; justify-content: center; gap: 6px; padding: 0 8px; font-size: 15px !important; }
    .uk-teambar .uk-teambar-sum { flex-basis: 100%; }
    .uk-pgroup { grid-template-columns: minmax(0, 1fr); }
    .uk-pgroup-chips { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .uk .uk-chip.uk-person { min-width: 0; padding-right: 10px; font-size: 14px !important; }
    .uk .uk-chip-t { min-width: 0; overflow: hidden; text-overflow: ellipsis; }
    .uk .uk-pgroup-name { padding-top: 2px; }
}
@media (prefers-reduced-motion: reduce) {
    .uk .uk-check, .uk-caret, .uk .uk-chip, .uk-new { transition: none; }
}
</style>

<script>
(function () {
    'use strict';
    var D = <?php echo json_encode($ukBoot, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var $ = function (id) { return document.getElementById(id); };
    var esc = function (v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };

    var S = {
        tab: 'mine', open: {}, fresh: {}, doneAll: {}, teamDone: false, busy: false,
        edit: 0, sel: [], due: 'today', urgent: false, draft: {}
    };
    try { var t0 = localStorage.getItem('afx_uk_tab'); if (t0) { S.tab = t0; } } catch (e) {}

    /* ── čas ─────────────────────────────────────────────────────────────── */
    var DOW = ['neděle', 'pondělí', 'úterý', 'středa', 'čtvrtek', 'pátek', 'sobota'];
    var DOW_S = ['ne', 'po', 'út', 'st', 'čt', 'pá', 'so'];
    var ymd = function (s) { var p = String(s).slice(0, 10).split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); };
    var iso = function (d) { var z = function (n) { return (n < 10 ? '0' : '') + n; };
        return d.getFullYear() + '-' + z(d.getMonth() + 1) + '-' + z(d.getDate()); };
    var today = function () { return String(D.now).slice(0, 10); };
    var nowHM = function () { return String(D.now).slice(11, 16); };
    var plus = function (n) { var d = ymd(today()); d.setDate(d.getDate() + n); return iso(d); };
    var diff = function (date) { return Math.round((ymd(date) - ymd(today())) / 86400000); };
    var dm = function (date) { var d = ymd(date); return d.getDate() + '. ' + (d.getMonth() + 1) + '.'; };
    var toSunday = function () { var w = ymd(today()).getDay(); return w === 0 ? 0 : 7 - w; };
    var hm = function (ts) { var t = String(ts).slice(11, 16); return t.charAt(0) === '0' ? t.slice(1) : t; };
    /** „dnes 14:32", „včera 9:05", „18. 11. 14:32" */
    var when = function (ts) {
        if (!ts) { return ''; }
        var n = diff(ts);
        return (n === 0 ? 'dnes' : n === -1 ? 'včera' : dm(ts)) + ' ' + hm(ts);
    };
    /** „před 5 min", „před 2 h", jinak jako when() */
    var ago = function (ts) {
        var a = new Date(String(ts).replace(' ', 'T')), b = new Date(String(D.now).replace(' ', 'T'));
        var m = Math.round((b - a) / 60000);
        if (m < 1) { return 'právě teď'; }
        if (m < 60) { return 'před ' + m + ' min'; }
        if (m < 360 && diff(ts) === 0) { return 'před ' + Math.round(m / 60) + ' h'; }
        return when(ts);
    };
    var cnt = function (n, one, few, many) { return n + ' ' + (n === 1 ? one : (n >= 2 && n <= 4 ? few : many)); };

    /** Kam úkol v denním plánu patří a jak se termín napíše. */
    var due = function (t) {
        if (!t.due_date) { return { bucket: 'none', label: '', cls: '' }; }
        var n = diff(t.due_date), tm = t.due_time ? ' ' + t.due_time.replace(/^0/, '') : '';
        if (n < 0 || (n === 0 && t.due_time && t.due_time < nowHM())) {
            return { bucket: 'over', cls: 'is-over',
                     label: (n === 0 ? 'mělo být dnes' : n === -1 ? 'mělo být včera' : 'mělo být ' + dm(t.due_date)) + tm };
        }
        if (n === 0) { return { bucket: 'today', cls: 'is-today', label: 'dnes' + (tm ? ' do' + tm : '') }; }
        if (n === 1) { return { bucket: 'tomorrow', cls: '', label: 'zítra' + (tm ? ' do' + tm : '') }; }
        return { bucket: n <= toSunday() ? 'week' : 'later', cls: '',
                 label: DOW_S[ymd(t.due_date).getDay()] + ' ' + dm(t.due_date) + tm };
    };
    var dueSort = function (a, b) {
        if (a.urgent !== b.urgent) { return a.urgent ? -1 : 1; }
        var x = (a.due_date || '9999') + ' ' + (a.due_time || '99:99'), y = (b.due_date || '9999') + ' ' + (b.due_time || '99:99');
        return x < y ? -1 : x > y ? 1 : a.id - b.id;
    };

    /* ── lidé ────────────────────────────────────────────────────────────── */
    var person = function (key) { for (var i = 0; i < D.people.length; i++) { if (D.people[i].key === key) { return D.people[i]; } } return null; };
    var branch = function (key) { for (var i = 0; i < D.branches.length; i++) { if (D.branches[i].key === key) { return D.branches[i]; } } return null; };
    var ROLE = { boss: 'vedení', admin: 'vedení', manager: 'manažer', engineer: 'technik', brigadnik: 'brigádník', accountant: 'účetní' };
    var avatar = function (key, name, extra) {
        var p = person(key);
        if (String(key).indexOf('branch:') === 0) {
            return '<span class="uk-av ' + (extra || '') + '" style="--c:#6b7686" title="' + esc(name) + '"><i class="fas fa-store"></i></span>';
        }
        var ini = p ? p.initials : String(name || '?').trim().slice(0, 2).toUpperCase();
        return '<span class="uk-av ' + (extra || '') + '" style="--c:' + esc(p ? p.color : '#6b7686') + '" title="' + esc(name) + '">' + esc(ini) + '</span>';
    };
    var stateOf = function (t) { return t.status === 'done' ? 'done' : (t.seen_at ? 'seen' : 'new'); };
    var stateText = function (t) {
        var st = stateOf(t), who = String(t.assignee_key).indexOf('branch:') === 0;
        if (st === 'done') { return '<span class="uk-st-done"><i class="fas fa-check"></i>hotovo ' + esc(when(t.done_at)) + (who && t.done_by ? ' · ' + esc(t.done_by) : '') + '</span>'; }
        if (st === 'seen') { return '<span class="uk-st-seen"><i class="fas fa-eye"></i>viděno ' + esc(when(t.seen_at)) + (who && t.seen_by ? ' · ' + esc(t.seen_by) : '') + '</span>'; }
        return '<span class="uk-st-new"><i class="far fa-eye-slash"></i>zatím neviděno</span>';
    };
    var comments = function (t) { return t.events.filter(function (e) { return e.kind === 'comment'; }).length; };

    /* ── řádek úkolu ─────────────────────────────────────────────────────── */
    var SEP = '<span class="uk-sep">·</span>';
    var row = function (t, mode) {
        var d = due(t), isOpen = !!S.open[t.id], done = t.status === 'done', lead, meta = [];
        if (mode === 'mine') {
            lead = '<button type="button" class="uk-check' + (done ? ' is-on' : '') + '" data-act="' + (done ? 'reopen' : 'done') + '"'
                 + (done ? (t.can.reopen ? '' : ' disabled') : (t.can.done ? '' : ' disabled'))
                 + ' aria-label="' + (done ? 'Vrátit mezi nedokončené' : 'Označit jako hotové') + '" title="' + (done ? 'Vrátit mezi nedokončené' : 'Hotovo') + '"><i class="fas fa-check"></i></button>';
            if (t.by_me && t.assignee_key === D.me) { meta.push('pro sebe'); }
            else { meta.push('od <b>' + esc(t.creator_name) + '</b>'); }
            if (String(t.assignee_key).indexOf('branch:') === 0) { meta.push('pro prodejnu'); }
            meta.push(esc(ago(t.created_at)));
            if (done) { meta.push('<span class="uk-st-done"><i class="fas fa-check"></i>' + esc(when(t.done_at)) + (t.done_by && t.done_by !== D.me_name ? ' · ' + esc(t.done_by) : '') + '</span>'); }
        } else if (mode === 'sent') {
            lead = avatar(t.assignee_key, t.assignee_name, 'is-ring is-' + stateOf(t));
            meta.push('<b>' + esc(t.assignee_name) + '</b>');
            meta.push(stateText(t));
        } else {
            lead = '<span class="uk-dot is-' + stateOf(t) + '"></span>';
            meta.push('od <b>' + esc(t.creator_name) + '</b>');
            meta.push(stateText(t));
        }
        var c = comments(t);
        if (c) { meta.push('<i class="far fa-comment"></i>' + c); }
        var pill = done ? (mode === 'mine' ? '' : '<span class="uk-due is-done">hotovo</span>')
                 : (d.label ? '<span class="uk-due ' + d.cls + '">' + (d.bucket === 'over' ? '<i class="fas fa-triangle-exclamation"></i>' : '') + esc(d.label) + '</span>' : '');
        return '<article class="uk-task' + (t.urgent && !done ? ' is-urgent' : '') + (done ? ' is-done' : '') + (isOpen ? ' is-open' : '') + '" data-id="' + t.id + '">'
            + '<div class="uk-row">'
            + '<div class="uk-lead">' + lead + '</div>'
            + '<button type="button" class="uk-body" data-act="toggle" aria-expanded="' + (isOpen ? 'true' : 'false') + '">'
            +   '<span class="uk-title">'
            +     (S.fresh[t.id] && !done ? '<em class="uk-newdot">nové</em>' : '')
            +     (t.urgent && !done ? '<em class="uk-flag"><i class="fas fa-bolt"></i>Spěchá</em>' : '')
            +     esc(t.title) + '</span>'
            +   '<span class="uk-meta">' + meta.join(SEP) + '</span>'
            + '</button>'
            + '<div class="uk-side">' + pill + (mode === 'team' ? '' : '<span class="uk-caret" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>') + '</div>'
            + '</div>'
            + (isOpen ? detail(t) : '')
            + '</article>';
    };

    var LOG = {
        done:    ['fa-check', 'is-ok', 'Hotovo'],
        reopen:  ['fa-rotate-left', 'is-back', 'Vráceno k dořešení'],
        edit:    ['fa-pen', '', 'Změna'],
        comment: ['fa-comment', '', '']
    };
    var detail = function (t) {
        var d = due(t), h = '<div class="uk-detail">';
        if (t.detail) { h += '<div class="uk-text">' + esc(t.detail) + '</div>'; }
        h += '<dl class="uk-facts">'
           + '<div><dt>Zadal</dt><dd>' + esc(t.creator_name) + ' · ' + esc(when(t.created_at)) + '</dd></div>'
           + '<div><dt>Pro</dt><dd>' + esc(t.assignee_name) + '</dd></div>'
           + '<div><dt>Termín</dt><dd>' + (t.due_date ? esc(DOW[ymd(t.due_date).getDay()] + ' ' + dm(t.due_date) + (t.due_time ? ' do ' + t.due_time.replace(/^0/, '') : '')) : 'bez termínu') + '</dd></div>'
           + '<div><dt>Stav</dt><dd>' + stateText(t) + '</dd></div>'
           + '</dl>';
        if (t.events.length) {
            h += '<ol class="uk-log">';
            t.events.forEach(function (e) {
                var L = LOG[e.kind] || LOG.comment;
                h += '<li><span class="uk-log-ico ' + L[1] + '"><i class="fas ' + L[0] + '"></i></span><div class="uk-log-body">'
                   + (L[2] ? esc(L[2]) + ' — ' : '') + '<b>' + esc(e.author) + '</b>'
                   + '<span class="uk-log-when">' + esc(when(e.at)) + '</span>'
                   + (e.body ? '<span class="uk-log-note">' + esc(e.body) + '</span>' : '') + '</div></li>';
            });
            h += '</ol>';
        }
        h += '<form class="uk-say" data-act="say"><input type="text" maxlength="1000" placeholder="'
           + (t.status === 'done' ? 'Poznámka, nebo důvod vrácení' : 'Napsat poznámku k úkolu') + '" aria-label="Poznámka k úkolu">'
           + '<button type="submit" class="uk-btn uk-btn-s">Poslat</button></form>';
        h += '<div class="uk-acts">';
        if (t.can.done) { h += '<button type="button" class="uk-btn uk-btn-s uk-btn-ok" data-act="done"><i class="fas fa-check me-1"></i>Hotovo</button>'; }
        if (t.can.reopen) { h += '<button type="button" class="uk-btn uk-btn-s" data-act="reopen"><i class="fas fa-rotate-left me-1"></i>Vrátit k dořešení</button>'; }
        if (t.can.edit && t.status === 'open') { h += '<button type="button" class="uk-btn uk-btn-s" data-act="edit"><i class="fas fa-pen me-1"></i>Upravit</button>'; }
        if (t.can.delete) { h += '<button type="button" class="uk-btn uk-btn-s uk-btn-bad" data-act="delete"><i class="fas fa-trash me-1"></i>Smazat</button>'; }
        return h + '</div></div>';
    };

    var section = function (cls, title, sub, rows, more) {
        return '<section class="uk-sec ' + cls + '"><header class="uk-sec-head"><h5>' + esc(title) + '</h5>'
            + '<div class="uk-sec-sub">' + esc(sub) + '</div></header>'
            + '<div class="uk-group">' + rows + (more || '') + '</div></section>';
    };
    var empty = function (icon, h, p) {
        return '<div class="uk-empty"><i class="fas ' + icon + '"></i><div class="uk-empty-h">' + esc(h) + '</div><div class="uk-empty-p">' + esc(p) + '</div></div>';
    };
    var doneBlock = function (list, mode, key) {
        if (!list.length) { return ''; }
        list.sort(function (a, b) { return a.done_at < b.done_at ? 1 : -1; });
        var all = !!S.doneAll[key], shown = all ? list : list.slice(0, 5);
        return section('is-done', 'Hotovo', 'za posledních 30 dní',
            shown.map(function (t) { return row(t, mode); }).join(''),
            list.length > shown.length ? '<button type="button" class="uk-more" data-more="' + key + '">Ukázat všech ' + list.length + '</button>' : '');
    };

    /* ── pohledy ─────────────────────────────────────────────────────────── */
    var viewMine = function () {
        var open = D.tasks.filter(function (t) { return t.for_me && t.status === 'open'; });
        var done = D.tasks.filter(function (t) { return t.for_me && t.status === 'done'; });
        if (!open.length && !done.length) {
            return empty('fa-mug-hot', 'Nic na tebe nečeká', 'Až ti někdo zadá úkol, objeví se tady.');
        }
        var B = { over: [], today: [], tomorrow: [], week: [], later: [], none: [] };
        open.forEach(function (t) { B[due(t).bucket].push(t); });
        var T = today(), h = '';
        var defs = [
            ['over', 'is-over', 'Po termínu', function (n) { return cnt(n, 'úkol čeká', 'úkoly čekají', 'úkolů čeká'); }],
            ['today', '', 'Dnes', function () { return DOW[ymd(T).getDay()] + ' ' + dm(T); }],
            ['tomorrow', '', 'Zítra', function () { return DOW[ymd(plus(1)).getDay()] + ' ' + dm(plus(1)); }],
            ['week', '', 'Tento týden', function () { return 'do neděle ' + dm(plus(toSunday())); }],
            ['later', '', 'Později', function (n) { return cnt(n, 'úkol', 'úkoly', 'úkolů'); }],
            ['none', '', 'Bez termínu', function () { return 'až bude čas'; }]
        ];
        defs.forEach(function (df) {
            var l = B[df[0]];
            if (!l.length) { return; }
            l.sort(dueSort);
            h += section(df[1], df[2], df[3](l.length), l.map(function (t) { return row(t, 'mine'); }).join(''));
        });
        if (!open.length) { h += empty('fa-circle-check', 'Všechno máš hotové', 'Nové úkoly se objeví tady.'); }
        return h + doneBlock(done, 'mine', 'mine');
    };

    var sentOnes = function () { return D.tasks.filter(function (t) { return t.by_me && t.assignee_key !== D.me; }); };
    var viewSent = function () {
        var all = sentOnes();
        if (!all.length) { return empty('fa-paper-plane', 'Zatím jsi nikomu nic nezadal', 'Nahoře napiš, co je potřeba, a vyber komu.'); }
        var open = all.filter(function (t) { return t.status === 'open'; }).sort(dueSort);
        var unseen = open.filter(function (t) { return !t.seen_at; }).length, h = '';
        if (open.length) {
            h += section('', 'Čeká na splnění', unseen ? cnt(unseen, 'ještě neviděný', 'ještě neviděné', 'ještě neviděných') : 'všechny už viděli',
                open.map(function (t) { return row(t, 'sent'); }).join(''));
        }
        return h + doneBlock(all.filter(function (t) { return t.status === 'done'; }), 'sent', 'sent');
    };

    var viewTeam = function () {
        var cols = {}, order = [], over = 0, openN = 0;
        D.tasks.forEach(function (t) {
            if (t.status === 'done' && !S.teamDone) { return; }
            if (!cols[t.assignee_key]) { cols[t.assignee_key] = { key: t.assignee_key, name: t.assignee_name, list: [], open: 0, over: 0 }; order.push(t.assignee_key); }
            var c = cols[t.assignee_key];
            c.list.push(t);
            if (t.status === 'open') { c.open++; openN++; if (due(t).bucket === 'over') { c.over++; over++; } }
        });
        var bar = '<div class="uk-teambar"><div class="uk-teambar-sum">'
            + (openN ? '<b>' + cnt(openN, 'otevřený úkol', 'otevřené úkoly', 'otevřených úkolů') + '</b>'
                + (over ? ', z toho <b class="is-bad">' + over + ' po termínu</b>' : ', žádný po termínu') : 'Nikdo teď nemá otevřený úkol.')
            + '</div><button type="button" class="uk-chip' + (S.teamDone ? ' is-on' : '') + '" id="ukTeamDone" aria-pressed="' + (S.teamDone ? 'true' : 'false') + '">'
            + '<i class="fas fa-check"></i>I hotové</button></div>';
        if (!order.length) { return bar + empty('fa-people-group', 'Tým má čistý stůl', 'Žádné otevřené úkoly.'); }
        order.sort(function (a, b) {
            var x = cols[a], y = cols[b];
            return (y.over - x.over) || (y.open - x.open) || String(x.name).localeCompare(String(y.name), 'cs');
        });
        var h = bar + '<div class="uk-board">';
        order.forEach(function (k) {
            var c = cols[k], p = person(k), b = branch(k);
            c.list.sort(function (a, b2) { return (a.status === b2.status) ? dueSort(a, b2) : (a.status === 'open' ? -1 : 1); });
            var role = b ? 'úkoly pro celou prodejnu' : (p ? (ROLE[p.role] || p.role) + (branch('branch:' + p.branch_id) ? ' · ' + branch('branch:' + p.branch_id).name : '') : 'už u nás není');
            h += '<section class="uk-col"><header class="uk-col-head">' + avatar(k, c.name, 'is-lg')
               + '<div><div class="uk-col-name">' + esc(b ? 'Kdokoli – ' + b.name : c.name) + '</div><div class="uk-col-role">' + esc(role) + '</div></div>'
               + '<div class="uk-col-n">' + (c.over ? '<span class="uk-pill is-bad" title="Po termínu">' + c.over + '</span>' : '')
               + '<span class="uk-pill" title="Otevřené úkoly">' + c.open + '</span></div></header>'
               + c.list.map(function (t) { return row(t, 'team'); }).join('') + '</section>';
        });
        h += '</div>';
        var idle = D.people.filter(function (p) {
            if (!D.lead && 'branch:' + p.branch_id !== D.my_branch) { return false; }   // manažer do druhé prodejny nevidí
            return !cols[p.key] || !cols[p.key].open;
        }).map(function (p) { return p.name; });
        if (idle.length) { h += '<div class="uk-idle">Bez otevřeného úkolu: ' + esc(idle.join(', ')) + '</div>'; }
        return h;
    };

    var render = function () {
        if (S.tab === 'team' && !D.team_view) { S.tab = 'mine'; }
        var mine = D.tasks.filter(function (t) { return t.for_me && t.status === 'open'; });
        var mineOver = mine.filter(function (t) { return due(t).bucket === 'over'; }).length;
        var sent = sentOnes().filter(function (t) { return t.status === 'open'; }).length;
        var tabs = [['mine', 'Pro mě', mine.length, mineOver], ['sent', 'Zadal jsem', sent, 0]];
        if (D.team_view) { tabs.push(['team', 'Tým', D.tasks.filter(function (t) { return t.status === 'open'; }).length, 0]); }
        $('ukTabs').innerHTML = tabs.map(function (t) {
            return '<button type="button" class="uk-tab' + (S.tab === t[0] ? ' is-on' : '') + '" role="tab" aria-selected="' + (S.tab === t[0] ? 'true' : 'false') + '" data-tab="' + t[0] + '">'
                + t[1] + (t[2] ? '<b' + (t[3] ? ' class="is-bad" title="' + t[3] + ' po termínu"' : '') + '>' + t[2] + '</b>' : '') + '</button>';
        }).join('');
        // rozepsaná poznámka nesmí zmizet při překreslení ani při přepnutí pohledu
        Array.prototype.forEach.call($('ukList').querySelectorAll('.uk-task'), function (a) {
            var i = a.querySelector('.uk-say input');
            if (!i) { return; }
            if (i.value) { S.draft[a.dataset.id] = i.value; } else { delete S.draft[a.dataset.id]; }
        });
        $('ukList').innerHTML = S.tab === 'sent' ? viewSent() : S.tab === 'team' ? viewTeam() : viewMine();
        Object.keys(S.draft).forEach(function (id) {
            var i = document.querySelector('.uk-task[data-id="' + id + '"] .uk-say input');
            if (i) { i.value = S.draft[id]; }
        });
        document.querySelector('.uk-wrap').classList.toggle('is-wide', S.tab === 'team');
        if (window.afxTaskBadge) { window.afxTaskBadge(D.badge); }
    };

    /* ── data ────────────────────────────────────────────────────────────── */
    var take = function (r) {
        if (!r || !r.tasks) { return; }
        D = r;
        D.tasks.forEach(function (t) { if (t.fresh) { S.fresh[t.id] = 1; } });
    };
    var api = function (action, data) {
        var b = new URLSearchParams();
        b.set('action', action);
        b.set('csrf_token', window.afxCsrf ? window.afxCsrf() : '');
        Object.keys(data || {}).forEach(function (k) {
            if (Array.isArray(data[k])) { data[k].forEach(function (v) { b.append(k + '[]', v); }); }
            else { b.set(k, data[k]); }
        });
        return fetch('ukoly/api.php', { method: 'POST', credentials: 'same-origin', body: b })
            .then(function (r) { return r.json(); })
            .catch(function () { return { success: false, message: 'Server neodpověděl — zkus to znovu.' }; });
    };
    var refresh = function () {
        if (S.busy || document.hidden) { return; }
        var a = document.activeElement;
        if (a && $('ukList').contains(a) && a.tagName === 'INPUT') { return; }   // nepřekreslovat pod rukama
        fetch('ukoly/api.php?action=list&seen=1', { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (r) { if (r && r.success && !S.busy) { take(r); render(); } })
            .catch(function () {});
    };

    var toastTimer = 0;
    var toast = function (text, undoLabel, undo) {
        var el = $('ukToast');
        el.innerHTML = '<span>' + esc(text) + '</span>' + (undo ? '<button type="button">' + esc(undoLabel) + '</button>' : '');
        el.hidden = false;
        if (undo) { el.querySelector('button').addEventListener('click', function () { el.hidden = true; undo(); }); }
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { el.hidden = true; }, undo ? 7000 : 3200);
    };
    var fail = function (r) { if (window.showAlert) { showAlert(esc(r.message || 'Nepovedlo se to.'), 'Úkoly'); } else { alert(r.message); } };

    var setDone = function (id, done, note, quiet) {
        if (S.busy) { return; }
        S.busy = true;
        api(done ? 'done' : 'reopen', { id: id, note: note || '' }).then(function (r) {
            S.busy = false;
            if (!r.success) { fail(r); render(); return; }
            take(r); render();
            if (quiet) { return; }
            if (done) { toast('Hotovo.', 'Vrátit', function () { setDone(id, false, '', true); }); }
            else { toast('Vráceno mezi nedokončené.'); }
        });
    };

    /* ── zadání a úprava ─────────────────────────────────────────────────── */
    var chipPerson = function (key, label, color, ini, icon) {
        return '<button type="button" class="uk-chip uk-person' + (S.sel.indexOf(key) >= 0 ? ' is-on' : '') + '" data-key="' + esc(key) + '" style="--c:' + esc(color) + '" aria-pressed="' + (S.sel.indexOf(key) >= 0 ? 'true' : 'false') + '">'
            + '<span class="uk-av" style="--c:' + esc(color) + '">' + (icon ? '<i class="fas ' + icon + '"></i>' : esc(ini)) + '</span><span class="uk-chip-t">' + esc(label) + '</span></button>';
    };
    var renderPeople = function () {
        var me = person(D.me), h = '', used = {};
        var groups = D.branches.slice().sort(function (a, b) { return (a.key === D.my_branch ? -1 : 0) - (b.key === D.my_branch ? -1 : 0); });
        groups.forEach(function (b) {
            var list = D.people.filter(function (p) { return 'branch:' + p.branch_id === b.key && p.key !== D.me; });
            list.forEach(function (p) { used[p.key] = 1; });
            h += '<div class="uk-pgroup"><span class="uk-pgroup-name">' + esc(b.name) + '</span><div class="uk-pgroup-chips">'
               + chipPerson(b.key, 'Kdokoli z prodejny', '#6b7686', '', 'fa-store')
               + list.map(function (p) { return chipPerson(p.key, p.name, p.color, p.initials); }).join('') + '</div></div>';
        });
        var rest = D.people.filter(function (p) { return !used[p.key] && p.key !== D.me; });
        // při úpravě může být příjemcem člověk, který už mezi aktivními není
        S.sel.forEach(function (k) {
            if (k !== D.me && !person(k) && !branch(k)) {
                var t = D.tasks.filter(function (x) { return x.assignee_key === k; })[0];
                rest.push({ key: k, name: t ? t.assignee_name : 'původní příjemce', color: '#6b7686', initials: '?' });
            }
        });
        h += '<div class="uk-pgroup"><span class="uk-pgroup-name">' + (rest.length ? 'Ostatní' : 'Já') + '</span><div class="uk-pgroup-chips">'
           + rest.map(function (p) { return chipPerson(p.key, p.name, p.color, p.initials); }).join('')
           + chipPerson(D.me, 'Já sám', me ? me.color : '#6b7686', me ? me.initials : 'JÁ') + '</div></div>';
        $('ukPeople').innerHTML = h;
    };
    var renderDue = function () {
        Array.prototype.forEach.call($('ukDue').querySelectorAll('[data-due]'), function (b) {
            b.classList.toggle('is-on', b.dataset.due === S.due);
            b.setAttribute('aria-pressed', b.dataset.due === S.due ? 'true' : 'false');
        });
        var ts = toSunday();
        $('ukDueWeek').hidden = ts < 2;                 // v sobotu a v neděli je „do neděle" totéž co dnes/zítra
        $('ukDate').hidden = S.due !== 'date';
        $('ukTimeWrap').hidden = S.due === 'none';
        if (S.due === 'date' && !$('ukDate').value) { $('ukDate').value = plus(2); }
        $('ukDate').min = today();
    };
    var hint = function (text, bad) {
        var el = $('ukHint');
        el.textContent = text || '';
        el.classList.toggle('is-bad', !!bad);
    };
    var renderHint = function () {
        if (S.edit) { hint(''); return; }
        if (S.sel.length > 1) { hint('Každý z ' + S.sel.length + ' dostane úkol zvlášť a odškrtne si ho sám.'); }
        else if (S.sel.length === 1 && branch(S.sel[0])) { hint('Odškrtne ho první, kdo ho na prodejně udělá.'); }
        else { hint(''); }
    };
    var openNew = function () {
        var f = $('ukNew');
        if (f.classList.contains('is-open')) { return; }
        f.classList.add('is-open');
        $('ukMore').hidden = false;
        $('ukClose').hidden = false;
        renderPeople(); renderDue(); renderHint();
    };
    var closeNew = function () {
        var f = $('ukNew');
        f.classList.remove('is-open', 'is-edit');
        $('ukMore').hidden = true;
        $('ukClose').hidden = true;
        f.reset();
        S.edit = 0; S.sel = []; S.due = 'today'; S.urgent = false;
        $('ukUrgent').classList.remove('is-on');
        $('ukUrgent').setAttribute('aria-pressed', 'false');
        $('ukSubmit').textContent = 'Zadat úkol';
        $('ukTitle').placeholder = 'Co je potřeba udělat?';
        hint('');
    };
    var startEdit = function (t) {
        closeNew();
        S.edit = t.id; S.sel = [t.assignee_key]; S.urgent = !!t.urgent;
        S.due = !t.due_date ? 'none' : (t.due_date === today() ? 'today' : (t.due_date === plus(1) ? 'tomorrow' : 'date'));
        $('ukTitle').value = t.title;
        $('ukDetail').value = t.detail;
        $('ukDate').value = t.due_date || '';
        $('ukTime').value = t.due_time || '';
        $('ukUrgent').classList.toggle('is-on', S.urgent);
        $('ukUrgent').setAttribute('aria-pressed', S.urgent ? 'true' : 'false');
        $('ukSubmit').textContent = 'Uložit změny';
        $('ukNew').classList.add('is-edit');
        openNew();
        $('ukNew').scrollIntoView({ behavior: 'smooth', block: 'start' });
        $('ukTitle').focus();
    };
    var dueValue = function () {
        if (S.due === 'none') { return ['', '']; }
        var d = S.due === 'today' ? today() : S.due === 'tomorrow' ? plus(1) : S.due === 'week' ? plus(toSunday()) : $('ukDate').value;
        return [d, $('ukTime').value];
    };

    $('ukTitle').addEventListener('focus', openNew);
    $('ukTitle').addEventListener('input', openNew);
    $('ukClose').addEventListener('click', closeNew);
    $('ukCancel').addEventListener('click', closeNew);
    $('ukPeople').addEventListener('click', function (e) {
        var b = e.target.closest('[data-key]');
        if (!b) { return; }
        var k = b.dataset.key, i = S.sel.indexOf(k);
        if (S.edit) { S.sel = [k]; }                    // úkol má jednoho příjemce
        else if (i >= 0) { S.sel.splice(i, 1); }
        else { S.sel.push(k); }
        renderPeople(); renderHint();
    });
    $('ukDue').addEventListener('click', function (e) {
        var b = e.target.closest('[data-due]');
        if (!b) { return; }
        S.due = b.dataset.due;
        renderDue();
        if (S.due === 'date') { $('ukDate').focus(); }
    });
    $('ukUrgent').addEventListener('click', function () {
        S.urgent = !S.urgent;
        this.classList.toggle('is-on', S.urgent);
        this.setAttribute('aria-pressed', S.urgent ? 'true' : 'false');
    });
    $('ukDetail').addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { e.preventDefault(); $('ukNew').requestSubmit(); }
    });
    $('ukNew').addEventListener('submit', function (e) {
        e.preventDefault();
        if (S.busy) { return; }
        var title = $('ukTitle').value.trim(), dv = dueValue();
        if (!title) { hint('Napiš, co je potřeba udělat.', true); $('ukTitle').focus(); return; }
        if (!S.sel.length) { hint('Vyber, pro koho úkol je.', true); return; }
        if (S.due === 'date' && !dv[0]) { hint('Vyber den, do kdy to má být.', true); $('ukDate').focus(); return; }
        var btn = $('ukSubmit'), label = btn.textContent, editing = S.edit, sel = S.sel.slice();
        var data = { title: title, detail: $('ukDetail').value, due_date: dv[0], due_time: dv[1], urgent: S.urgent ? 1 : '' };
        if (editing) { data.id = editing; data.assignee = sel[0]; } else { data.assignees = sel; }
        S.busy = true; btn.disabled = true; btn.textContent = 'Ukládám…';
        api(editing ? 'update' : 'create', data).then(function (r) {
            S.busy = false; btn.disabled = false; btn.textContent = label;
            if (!r.success) { hint(r.message || 'Nepovedlo se to.', true); return; }
            take(r);
            if (!editing) {
                // ať je hned vidět, kam úkol zapadl
                S.tab = (sel.length === 1 && sel[0] === D.me) ? 'mine' : 'sent';
                try { localStorage.setItem('afx_uk_tab', S.tab); } catch (e2) {}
            }
            closeNew(); render();
            toast(r.message || 'Uloženo.');
        });
    });

    /* ── seznam: klikání ─────────────────────────────────────────────────── */
    var taskOf = function (el) {
        var a = el.closest('.uk-task');
        if (!a) { return null; }
        var id = +a.dataset.id;
        return D.tasks.filter(function (t) { return t.id === id; })[0] || null;
    };
    $('ukTabs').addEventListener('click', function (e) {
        var b = e.target.closest('[data-tab]');
        if (!b) { return; }
        S.tab = b.dataset.tab;
        try { localStorage.setItem('afx_uk_tab', S.tab); } catch (e2) {}
        render();
    });
    $('ukList').addEventListener('click', function (e) {
        var more = e.target.closest('[data-more]');
        if (more) { S.doneAll[more.dataset.more] = 1; render(); return; }
        if (e.target.closest('#ukTeamDone')) { S.teamDone = !S.teamDone; render(); return; }
        var b = e.target.closest('[data-act]');
        if (!b || b.tagName === 'FORM') { return; }
        var t = taskOf(b);
        if (!t) { return; }
        var act = b.dataset.act;
        if (act === 'toggle') {
            S.open[t.id] = !S.open[t.id];
            delete S.fresh[t.id];
            render();
        } else if (act === 'done' || act === 'reopen') {
            var art = b.closest('.uk-task'), inp = art.querySelector('.uk-say input');
            var note = inp ? inp.value.trim() : '';
            if (inp) { inp.value = ''; }                 // poznámka odchází s akcí
            if (b.classList.contains('uk-check') && act === 'done') {
                b.classList.add('is-on', 'is-pop');      // odškrtnutí je vidět hned, řádek odjede až po uložení
                setTimeout(function () { setDone(t.id, true, note); }, 260);
            } else { setDone(t.id, act === 'done', note); }
        } else if (act === 'edit') {
            startEdit(t);
        } else if (act === 'delete') {
            var go = function () {
                S.busy = true;
                api('delete', { id: t.id }).then(function (r) {
                    S.busy = false;
                    if (!r.success) { fail(r); return; }
                    take(r); render(); toast('Úkol smazán.');
                });
            };
            if (window.showConfirm) { showConfirm('Smazat úkol „' + esc(t.title) + '“? Zmizí i tomu, komu byl zadaný.', go, 'Smazat úkol'); }
            else if (confirm('Smazat úkol?')) { go(); }
        }
    });
    $('ukList').addEventListener('submit', function (e) {
        var f = e.target.closest('.uk-say');
        if (!f) { return; }
        e.preventDefault();
        var t = taskOf(f), inp = f.querySelector('input'), body = inp.value.trim();
        if (!t || !body || S.busy) { return; }
        S.busy = true;
        api('comment', { id: t.id, body: body }).then(function (r) {
            S.busy = false;
            if (!r.success) { fail(r); return; }
            inp.value = '';
            take(r); render();
        });
    });

    /* ── start ───────────────────────────────────────────────────────────── */
    take(D);
    var want = +(new URLSearchParams(location.search).get('u') || 0);
    if (want) {
        var wt = D.tasks.filter(function (t) { return t.id === want; })[0];
        if (wt) { S.tab = wt.for_me ? 'mine' : (wt.by_me ? 'sent' : 'team'); S.open[want] = true; }
    }
    render();
    if (want && S.open[want]) {
        var node = document.querySelector('.uk-task[data-id="' + want + '"]');
        if (node) { node.scrollIntoView({ block: 'center' }); }
    }
    setInterval(refresh, 25000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { refresh(); } });
}());
</script>

<?php require_once 'includes/footer.php'; ?>
