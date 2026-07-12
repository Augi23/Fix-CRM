-- Třetí priorita zakázky: Low = „Klidná" (zákazník na webu zvolil „nespěchám").
-- DB hodnoty zůstávají anglicky (Low/Normal/High), popisky se překládají v UI.
ALTER TABLE `orders` MODIFY `priority` ENUM('Normal','High','Low') DEFAULT 'Normal';
