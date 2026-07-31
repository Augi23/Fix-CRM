-- 043: pojistka proti neplatnému DEFAULTu technicians.role po migraci 042
--
-- PROČ: 042 skládá ALTER ENUMu dynamicky z information_schema.COLUMNS a počítá
-- se dvěma podobami COLUMN_DEFAULT (s apostrofy / bez). MariaDB 10.2+ ale u
-- sloupce s výchozí hodnotou NULL vrací doslovný ŘETĚZEC 'NULL' (bez apostrofů,
-- ne SQL NULL) — ten neprojde větví `COLUMN_DEFAULT IS NULL` ani `LIKE '''%'''`
-- a QUOTE() z něj vyrobí `DEFAULT 'NULL'`. U ENUMu, kde 'NULL' není členem
-- výčtu, je to neplatná výchozí hodnota:
--   * server se STRICT módem ALTER odmítne (1067) a běh migrací se u 042
--     zastaví — to TAHLE migrace zachránit neumí (nikdy se k ní nedojde;
--     řeší se ručně / PHP pojistkou afxEnsureAccountantRoleValue(), která
--     čte SHOW COLUMNS, dostává skutečné NULL a klauzuli DEFAULT vynechá);
--   * server BEZ strict módu ale neplatný default může TIŠE uložit — a každý
--     nově založený zaměstnanec by pak dostal neplatnou/prázdnou roli, přesně
--     to, čemu se 042 snažila zabránit. TENHLE stav tady detekujeme a opravíme.
--
-- Na této instalaci je technicians.role VARCHAR(50) (001_bootstrap.sql), takže
-- 042 ALTER přeskočila a níže se nic nenajde — migrace je no-op pojistka pro
-- budoucí/jinou instalaci, kde je role ENUM.
--
-- Idempotentní: detekce přes information_schema, oprava jen když je co opravit.

-- Detekce uloženého neplatného defaultu. Podoby v COLUMN_DEFAULT:
--   * MySQL:   uložený řetězec NULL      → NULL      (4 znaky, bez apostrofů;
--              skutečné „bez defaultu" je tam SQL NULL, takže rovnost nesepne)
--   * MariaDB: uložený řetězec 'NULL'    → 'NULL'    (S apostrofy);
--              skutečný DEFAULT NULL     → NULL      (4 znaky BEZ apostrofů) —
--              ten by rovnost s 'NULL' (bez apostrofů) chytila taky, ale oprava
--              níže je i pro něj neškodná: DROP DEFAULT u nullable sloupce
--              znamená zase implicitní DEFAULT NULL.
-- Jen ENUM (jinde 042 nic nesahala) a jen když 'NULL' NENÍ platným členem výčtu.
SET @afx_bad_role_def := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'technicians' AND COLUMN_NAME = 'role'
      AND COLUMN_TYPE LIKE 'enum(%'
      AND COLUMN_TYPE NOT LIKE '%''NULL''%'
      AND (COLUMN_DEFAULT = 'NULL' OR COLUMN_DEFAULT = '''NULL''')
);

-- DROP DEFAULT je metadatová operace (žádné přepisování řádků): u nullable
-- sloupce tím výchozí hodnota spadne zpět na NULL, u NOT NULL sloupce zmizí
-- (INSERT bez role pak korektně selže, místo aby tiše uložil nesmysl).
SET @afx_fix_sql := IF(@afx_bad_role_def > 0,
    'ALTER TABLE technicians ALTER COLUMN role DROP DEFAULT',
    -- není co opravovat → bezpečný „prázdný" příkaz (stejný vzor jako v 042)
    'SET @afx_role_default_ok = 1');
PREPARE afx_fix_stmt FROM @afx_fix_sql;
EXECUTE afx_fix_stmt;
DEALLOCATE PREPARE afx_fix_stmt;
