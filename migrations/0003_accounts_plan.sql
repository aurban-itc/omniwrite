-- Eigene Datei: genau ein Statement, damit ein Abbruch keinen halben Zustand hinterlässt.
ALTER TABLE accounts
    ADD COLUMN plan_id BIGINT UNSIGNED NULL,
    ADD CONSTRAINT fk_accounts_plan FOREIGN KEY (plan_id) REFERENCES plans (id);
