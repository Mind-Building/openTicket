# Datenbank-Migrationen

Bitte führen Sie die folgenden SQL-Migrationen in dieser Reihenfolge aus:

## 1. show_start_time zu event_dates hinzufügen
```sql
-- Datei: database/migrations/add_show_start_time_to_event_dates.sql
ALTER TABLE `event_dates`
ADD COLUMN `show_start_time` time DEFAULT NULL AFTER `date`;
```

## 2. voucher_id zu reservations hinzufügen
```sql
-- Datei: database/migrations/add_voucher_id_to_reservations.sql
ALTER TABLE `reservations`
ADD COLUMN `voucher_id` INT(11) DEFAULT NULL AFTER `is_honor_card`;

ALTER TABLE `reservations`
ADD CONSTRAINT `fk_reservations_voucher`
FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`)
ON DELETE SET NULL ON UPDATE CASCADE;
```

## 3. is_general_rehearsal zu event_dates hinzufügen
```sql
-- Datei: database/migrations/add_is_general_rehearsal_to_event_dates.sql
ALTER TABLE `event_dates`
ADD COLUMN `is_general_rehearsal` tinyint(1) NOT NULL DEFAULT 0 AFTER `show_start_time`;
```

## 4. general_rehearsal_price zu seat_categories hinzufügen
```sql
-- Datei: database/migrations/add_general_rehearsal_price_to_categories.sql
ALTER TABLE `seat_categories`
ADD COLUMN `general_rehearsal_price` decimal(10,2) DEFAULT NULL AFTER `reduced_price`;
```

## 5. Zahlungsmethoden-Konfigurationen hinzufügen
```sql
-- Datei: database/migrations/add_payment_method_configs.sql
-- Siehe Datei für vollständige INSERT-Statements
```

## Ausführung

### Via MySQL-Client:
```bash
mysql -u root -p your_database < database/migrations/add_show_start_time_to_event_dates.sql
mysql -u root -p your_database < database/migrations/add_voucher_id_to_reservations.sql
mysql -u root -p your_database < database/migrations/add_is_general_rehearsal_to_event_dates.sql
mysql -u root -p your_database < database/migrations/add_general_rehearsal_price_to_categories.sql
mysql -u root -p your_database < database/migrations/add_payment_method_configs.sql
```

### Via phpMyAdmin:
1. Öffnen Sie phpMyAdmin
2. Wählen Sie Ihre Datenbank aus
3. Klicken Sie auf "SQL"
4. Kopieren Sie den Inhalt jeder Migration und führen Sie sie nacheinander aus

