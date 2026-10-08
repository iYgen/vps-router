-- Язык интерфейса на пользователя (RU/EN и далее). NULL = брать из cookie/дефолт.
ALTER TABLE users ADD COLUMN lang TEXT;
