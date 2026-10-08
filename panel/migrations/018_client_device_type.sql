-- Тип устройства для иконки/оформления в списке: router|phone|tablet|computer|NULL.
-- Заполняется мастером добавления устройства; на маршрутизацию не влияет.
ALTER TABLE clients ADD COLUMN device_type TEXT;
