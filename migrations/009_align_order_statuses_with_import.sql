-- Align order statuses with statuses used by the CSV order import.
-- The first ALTER keeps legacy enum values temporarily so existing rows can be converted safely.
ALTER TABLE `orders`
    MODIFY `status` ENUM('New','In Progress','Waiting for Parts','Pending Approval','Completed','Uncollected','Collected','Cancelled','Přijato','Zakládá se','V opravě','V opravě zák. desky','V externím servisu','V aut. servisu','Čeká na díl','Čeká na zákazníka','Čeká na platbu','Připraveno k převzetí','Nevyzvednuto','Vydáno','Vydáno - ČR','Stornováno','Černá růže') DEFAULT 'Přijato';

UPDATE `orders` SET `status` = 'Přijato' WHERE `status` IN ('New');
UPDATE `orders` SET `status` = 'Čeká na zákazníka' WHERE `status` IN ('Pending Approval');
UPDATE `orders` SET `status` = 'V opravě' WHERE `status` IN ('In Progress');
UPDATE `orders` SET `status` = 'Čeká na díl' WHERE `status` IN ('Waiting for Parts');
UPDATE `orders` SET `status` = 'Připraveno k převzetí' WHERE `status` IN ('Completed');
UPDATE `orders` SET `status` = 'Nevyzvednuto' WHERE `status` IN ('Uncollected');
UPDATE `orders` SET `status` = 'Vydáno' WHERE `status` IN ('Collected');
UPDATE `orders` SET `status` = 'Stornováno' WHERE `status` IN ('Cancelled');

ALTER TABLE `orders`
    MODIFY `status` ENUM('Přijato','Zakládá se','V opravě','V opravě zák. desky','V externím servisu','V aut. servisu','Čeká na díl','Čeká na zákazníka','Čeká na platbu','Připraveno k převzetí','Nevyzvednuto','Vydáno','Vydáno - ČR','Stornováno','Černá růže') DEFAULT 'Přijato';
