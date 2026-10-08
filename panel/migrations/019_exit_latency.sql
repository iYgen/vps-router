-- Задержка (мс) до exit-сервера по последней успешной health-проверке (ping через
-- туннельный интерфейс). NULL — ещё не измерялась/недоступен.
ALTER TABLE exit_servers ADD COLUMN latency_ms INTEGER;
