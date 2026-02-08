GRANT SELECT, INSERT, UPDATE, DELETE ON `{{db_name}}`.* TO `{{app_user}}`@'%';
FLUSH PRIVILEGES;
