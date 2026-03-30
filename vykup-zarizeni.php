<?php
require_once 'includes/config.php';
?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Formulář výkupu zařízení</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --bg: #f4f6f8;
            --card: #ffffff;
            --text: #172033;
            --muted: #64748b;
            --border: #d7dee8;
            --accent: #2f6fed;
        }

        body {
            background: var(--bg);
            color: var(--text);
            font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif;
        }

        .page-shell {
            max-width: 1100px;
            margin: 24px auto;
            padding: 0 16px 32px;
        }

        .paper {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(15, 23, 42, 0.08);
            overflow: hidden;
        }

        .paper-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 24px 28px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .brand img {
            width: 160px;
            height: auto;
            object-fit: contain;
        }

        .title h1 {
            font-size: 1.6rem;
            margin: 0;
            font-weight: 800;
        }

        .title p {
            margin: 4px 0 0;
            color: var(--muted);
        }

        .paper-body {
            padding: 28px;
        }

        .section {
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 18px;
            margin-bottom: 18px;
        }

        .section h2 {
            font-size: 0.98rem;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--accent);
            margin-bottom: 14px;
            font-weight: 800;
        }

        .field {
            margin-bottom: 14px;
        }

        .field label {
            display: block;
            font-size: 0.88rem;
            font-weight: 600;
            margin-bottom: 6px;
            color: var(--text);
        }

        .line {
            min-height: 42px;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: #fff;
            padding: 10px 12px;
        }

        .line.tall {
            min-height: 88px;
        }

        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }

        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 14px;
        }

        .muted-note {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            padding: 0 28px 28px;
        }

        @media (max-width: 768px) {
            .paper-head,
            .brand {
                flex-direction: column;
                align-items: flex-start;
            }

            .grid-2,
            .grid-3 {
                grid-template-columns: 1fr;
            }

            .actions {
                justify-content: stretch;
                flex-direction: column;
            }
        }

        @media print {
            body {
                background: #fff;
            }

            .page-shell {
                max-width: none;
                margin: 0;
                padding: 0;
            }

            .paper {
                border: none;
                border-radius: 0;
                box-shadow: none;
            }

            .actions {
                display: none;
            }

            .paper-head {
                border-bottom: 1px solid #bbb;
            }

            .section {
                break-inside: avoid;
            }
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
                        <h1>Formulář výkupu zařízení</h1>
                        <p>Čistý tisknutelný formulář pro okamžité vyplnění v servisu.</p>
                    </div>
                </div>
                <div class="text-end">
                    <div class="muted-note">Datum: ____________________</div>
                    <div class="muted-note">Zakázka: __________________</div>
                </div>
            </div>

            <div class="paper-body">
                <div class="section">
                    <h2>Zákazník</h2>
                    <div class="grid-2">
                        <div class="field"><label>Jméno a příjmení</label><div class="line"></div></div>
                        <div class="field"><label>Telefon</label><div class="line"></div></div>
                    </div>
                    <div class="grid-2">
                        <div class="field"><label>E-mail</label><div class="line"></div></div>
                        <div class="field"><label>Adresa</label><div class="line"></div></div>
                    </div>
                </div>

                <div class="section">
                    <h2>Zařízení</h2>
                    <div class="grid-3">
                        <div class="field"><label>Značka</label><div class="line"></div></div>
                        <div class="field"><label>Model</label><div class="line"></div></div>
                        <div class="field"><label>IMEI / S/N</label><div class="line"></div></div>
                    </div>
                    <div class="grid-2">
                        <div class="field"><label>Stav zařízení</label><div class="line tall"></div></div>
                        <div class="field"><label>Příslušenství</label><div class="line tall"></div></div>
                    </div>
                </div>

                <div class="section">
                    <h2>Výkup</h2>
                    <div class="grid-3">
                        <div class="field"><label>Nabízená částka</label><div class="line"></div></div>
                        <div class="field"><label>Vyplaceno</label><div class="line"></div></div>
                        <div class="field"><label>Forma výplaty</label><div class="line"></div></div>
                    </div>
                    <div class="field">
                        <label>Poznámka</label>
                        <div class="line tall"></div>
                    </div>
                </div>

                <div class="section mb-0">
                    <h2>Podpisy</h2>
                    <div class="grid-2">
                        <div class="field"><label>Podpis zákazníka</label><div class="line"></div></div>
                        <div class="field"><label>Podpis technika</label><div class="line"></div></div>
                    </div>
                </div>
            </div>

            <div class="actions">
                <a href="index.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-2"></i>Zpět do CRM</a>
                <button class="btn btn-primary" type="button" onclick="window.print()"><i class="fas fa-print me-2"></i>Tisknout</button>
            </div>
        </div>
    </div>
</body>
</html>
