<?php
require_once 'includes/config.php';
require_once 'includes/functions.php';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulář zástavy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f2f5f8;
            --card: #ffffff;
            --text: #132033;
            --muted: #64748b;
            --border: #d6dde6;
            --accent: #d14b3a;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .page-shell { max-width: 1120px; margin: 24px auto; padding: 0 16px 36px; }
        .paper { background: var(--card); border: 1px solid var(--border); border-radius: 20px; box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08); overflow: hidden; }
        .paper-head { display: flex; justify-content: space-between; gap: 24px; align-items: center; padding: 24px 28px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #ffffff 0%, #fff7f6 100%); }
        .brand { display: flex; align-items: center; gap: 16px; }
        .brand img { width: 170px; height: auto; object-fit: contain; }
        .title h1 { margin: 0; font-size: 1.6rem; font-weight: 800; }
        .title p { margin: 4px 0 0; color: var(--muted); }
        .paper-body { padding: 28px; }
        .section { border: 1px solid var(--border); border-radius: 16px; padding: 18px; margin-bottom: 18px; }
        .section h2 { margin: 0 0 16px; font-size: 0.95rem; letter-spacing: 0.08em; text-transform: uppercase; color: var(--accent); font-weight: 800; }
        .field { margin-bottom: 14px; }
        .field label { display: block; margin-bottom: 6px; font-size: 0.88rem; font-weight: 700; color: var(--text); }
        .form-control, .form-select { border-radius: 12px; border-color: var(--border); min-height: 44px; }
        .form-control:focus, .form-select:focus { border-color: rgba(209, 75, 58, 0.65); box-shadow: 0 0 0 0.2rem rgba(209, 75, 58, 0.12); }
        textarea.form-control { min-height: 92px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
        .meta-line { color: var(--muted); font-size: 0.9rem; }
        .print-only { display: none; }
        .legal-block { border-top: 1px solid var(--border); margin-top: 18px; padding-top: 16px; color: #1f2937; font-size: 0.9rem; line-height: 1.55; }
        .legal-block p { margin-bottom: 10px; }
        .legal-block ol { margin-bottom: 0; }
        .actions { display: flex; gap: 12px; justify-content: flex-end; padding: 0 28px 28px; }

        @media (max-width: 768px) {
            .paper-head, .brand { flex-direction: column; align-items: flex-start; }
            .grid-2, .grid-3 { grid-template-columns: 1fr; }
            .actions { flex-direction: column; justify-content: stretch; }
        }

        @media print {
            body { background: #fff; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .page-shell { max-width: none; margin: 0; padding: 0; }
            .paper { border: none; border-radius: 0; box-shadow: none; }
            .paper-head { border-bottom: 1px solid #bbb; background: #fff; padding: 18px 22px; }
            .paper-body { padding: 20px 22px 0; }
            .section { break-inside: avoid; page-break-inside: avoid; }
            .actions { display: none; }
            .print-only { display: block !important; }
            .legal-helper { display: none !important; }
            .form-control, .form-select, textarea.form-control { border: none !important; border-bottom: 1px solid #111827 !important; border-radius: 0 !important; box-shadow: none !important; padding: 2px 0 4px !important; background: transparent !important; min-height: 28px; color: #111827 !important; }
            textarea.form-control { min-height: 64px; }
            .form-control::placeholder { color: transparent; }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="paper">
            <div class="paper-head">
                <div class="brand">
                    <img src="assets/img/applefix-logo.png" alt="AppleFix logo">
                    <div class="title">
                        <h1>Formulář zástavy</h1>
                        <p>Vyplň formulář, zkontroluj údaje a vytiskni hotový dokument.</p>
                    </div>
                </div>
                <div class="text-end meta-line">
                    <div class="field mb-2">
                        <label class="mb-1">Datum</label>
                        <input type="text" class="form-control form-control-sm" name="doc_date">
                    </div>
                    <div class="field mb-0">
                        <label class="mb-1">Číslo dokumentu</label>
                        <input type="text" class="form-control form-control-sm" name="doc_number">
                    </div>
                </div>
            </div>

            <form class="paper-body" id="pawnForm">
                <div class="section">
                    <h2>Zákazník</h2>
                    <div class="grid-2">
                        <div class="field"><label>Jméno a příjmení</label><input type="text" class="form-control" name="customer_name"></div>
                        <div class="field"><label>Telefon</label><input type="text" class="form-control" name="customer_phone"></div>
                        <div class="field"><label>Adresa</label><input type="text" class="form-control" name="customer_address"></div>
                        <div class="field"><label>Číslo OP / pasu</label><input type="text" class="form-control" name="customer_id_doc"></div>
                    </div>
                </div>

                <div class="section">
                    <h2>Předmět zástavy</h2>
                    <div class="grid-2">
                        <div class="field"><label>Přesný popis předmětu</label><input type="text" class="form-control" name="item_description"></div>
                        <div class="field"><label>Značka / model</label><input type="text" class="form-control" name="item_model"></div>
                        <div class="field"><label>IMEI / S/N</label><input type="text" class="form-control" name="item_serial"></div>
                        <div class="field"><label>Stav zařízení</label><textarea class="form-control" name="item_state"></textarea></div>
                        <div class="field"><label>Příslušenství</label><textarea class="form-control" name="item_accessories"></textarea></div>
                        <div class="field"><label>Odhadní hodnota</label><input type="text" class="form-control" name="item_estimate"></div>
                    </div>
                </div>

                <div class="section">
                    <h2>Zástavní podmínky</h2>
                    <div class="grid-3">
                        <div class="field"><label>Poskytnutá částka</label><input type="text" class="form-control" name="loan_amount"></div>
                        <div class="field"><label>Splatnost do</label><input type="text" class="form-control" name="due_date"></div>
                        <div class="field"><label>Poplatek / úrok</label><input type="text" class="form-control" name="fee_rate"></div>
                    </div>
                    <div class="field"><label>Poznámka</label><textarea class="form-control" name="note"></textarea></div>
                </div>

                <div class="section">
                    <h2>Právní ujednání</h2>
                    <div class="legal-helper text-muted small mb-0">Text pod tímto nadpisem se zobrazí až ve vytištěné verzi.</div>
                    <div class="print-only legal-block">
                        <ol>
                            <li>Zástavce předává výše uvedený předmět do zástavy za účelem zajištění poskytnuté částky.</li>
                            <li>Zástavní věřitel předmět přijímá a zavazuje se postupovat dle sjednaných podmínek.</li>
                            <li>Po splnění podmínek a úhradě závazku bude předmět vydán zpět zástavci.</li>
                        </ol>
                    </div>
                </div>

                <div class="section mb-0">
                    <h2>Podpisy</h2>
                    <div class="grid-2">
                        <div class="field"><label>Místo a datum podpisu</label><input type="text" class="form-control" name="sign_place_date"></div>
                        <div class="field"><label>Podpis zástavce</label><input type="text" class="form-control" name="pledger_signature"></div>
                        <div class="field"><label>Podpis zástavního věřitele</label><input type="text" class="form-control" name="creditor_signature"></div>
                        <div class="field"><label>Vyplaceno / způsob výplaty</label><input type="text" class="form-control" name="payment_method"></div>
                    </div>
                </div>
            </form>

            <div class="actions">
                <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Zpět do CRM</a>
                <button class="btn btn-primary" type="button" onclick="window.print()"><i class="fas fa-print me-2"></i>Tisknout</button>
            </div>
        </div>
    </div>
</body>
</html>
