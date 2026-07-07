-- Nový stavový model (7/2026): nové zakázky používají jen 10 stavů;
-- staré/importované si ponechávají původní (legacy) stavy — ŽÁDNÁ konverze dat.
-- Nové hodnoty: 'V opravě - v externím servisu', 'V opravě - v autorizovaném servisu',
--               'Vydáno - čeká na platbu'.
ALTER TABLE `orders` MODIFY `status` ENUM(
    'New','In Progress','Waiting for Parts','Pending Approval','Completed','Uncollected','Collected','Cancelled',
    'Přijato','Zakládá se','V opravě','V opravě zák. desky','V externím servisu','V aut. servisu',
    'V opravě - v externím servisu','V opravě - v autorizovaném servisu',
    'Čeká na díl','Čeká na zákazníka','Čeká na platbu','Připraveno k převzetí',
    'Vydáno - čeká na platbu','Nevyzvednuto','Vydáno','Vydáno - ČR','Stornováno'
) DEFAULT 'Přijato';
-- Snímek technika při změně stavu (pro zobrazení jména v historii pohybu)
ALTER TABLE `order_status_log` ADD COLUMN IF NOT EXISTS `technician_id` INT NULL AFTER `changed_role`;
