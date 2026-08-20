<?php
/**
 * Ovládání zákaznického displeje (druhý monitor na recepci).
 * Otevírá se tlačítkem 🖥️ v horní liště; logika v assets/js/main.js
 * (blok „Zákaznický displej"), server: api/customer_display.php.
 */
?>
<div class="modal fade" id="customerDisplayModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content glass-card">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-desktop me-2 text-info"></i>Zákaznický displej</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zavřít"></button>
            </div>
            <div class="modal-body">
                <p class="text-secondary small mb-2">
                    Otevři tenhle odkaz v prohlížeči na druhém monitoru (celá obrazovka –
                    <kbd>⌃⌘F</kbd>). Když displej nic neukazuje, běží na něm reklama
                    s produkty z e-shopu.
                </p>
                <div class="input-group mb-3">
                    <input type="text" class="form-control" id="cdUrlInput" readonly value="Načítám…">
                    <button class="btn btn-outline-info" type="button" id="cdCopyBtn" title="Zkopírovat odkaz">
                        <i class="fas fa-copy"></i>
                    </button>
                    <a class="btn btn-outline-info" id="cdOpenBtn" href="#" target="_blank" rel="noopener" title="Otevřít v novém okně">
                        <i class="fas fa-arrow-up-right-from-square"></i>
                    </a>
                </div>
                <div class="d-flex gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-light" id="cdIdleBtn">
                        <i class="fas fa-image me-1"></i>Uvolnit displej (reklama)
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="cdFormBtn">
                        <i class="fas fa-user-pen me-1"></i>Klient vyplní údaje
                    </button>
                </div>
                <div class="small text-secondary mt-3" id="cdStatus"></div>
            </div>
        </div>
    </div>
</div>
