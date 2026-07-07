-- Přítomnost: rozlišení typu účtu (users vs technicians) — technici se přihlašují
-- z tabulky technicians a jejich session id je 't<ID>', takže potřebují vlastní typ.
ALTER TABLE `staff_presence_daily`
    ADD COLUMN IF NOT EXISTS `staff_type` VARCHAR(8) NOT NULL DEFAULT 'user' AFTER `user_id`;
ALTER TABLE `staff_presence_daily` DROP PRIMARY KEY, ADD PRIMARY KEY (`staff_type`, `user_id`, `work_date`);
