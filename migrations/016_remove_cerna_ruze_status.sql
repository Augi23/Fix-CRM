-- Stav "Černá růže" historicky značil zakázky druhé pobočky (pasáž Černá růže,
-- Na Příkopě). Pobočky jsou dnes samostatné (migrace 010) → stav se ruší.
-- Staré zakázky: stav -> 'Přijato', pobočka -> Na Příkopě (zachování významu).
SET @prikope_id := (SELECT id FROM branches WHERE code = 'prikope' LIMIT 1);
UPDATE `orders`
   SET `branch_id` = COALESCE(@prikope_id, `branch_id`),
       `status` = 'Přijato'
 WHERE `status` = 'Černá růže';
ALTER TABLE `orders`
    MODIFY `status` ENUM('New','In Progress','Waiting for Parts','Pending Approval','Completed','Uncollected','Collected','Cancelled','Přijato','Zakládá se','V opravě','V opravě zák. desky','V externím servisu','V aut. servisu','Čeká na díl','Čeká na zákazníka','Čeká na platbu','Připraveno k převzetí','Nevyzvednuto','Vydáno','Vydáno - ČR','Stornováno') DEFAULT 'Přijato';
