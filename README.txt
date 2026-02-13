# CRM Europe

Lightweight PHP CRM system with professional PDF generation.

Supports:

- Rechnungen (Invoices)
- Angebote (Quotes)
- Lieferscheine (Delivery Notes)
- Mahnungen (Reminders)

Built with:

- PHP 8+
- MySQL / MariaDB
- FPDF (UTF-8 with DejaVu)
- QR Code integration
- Environment-based configuration

---

## 📦 Requirements

- PHP 8.1+
- MySQL / MariaDB
- Composer
- Git
- Apache or Nginx

---

# 🚀 Local Development Setup

## 1. Clone Repository

```bash
git clone git@github.com:Mergo13/CRM-Europe.git
cd CRM-Europe
2. Install Dependencies
composer install
3. Create Environment File
Create .env in project root:
APP_ENV=development

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=crm_app
DB_USER=root
DB_PASS=
DB_CHARSET=utf8mb4
4. Create Database
CREATE DATABASE crm_app
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;
Import your schema if available.
5. Start Development Server
php -S 127.0.0.1:8000
Open:
http://127.0.0.1:8000
🌍 Production Deployment (Live Server)
1. SSH Into Server
ssh user@your-server-ip
Go to web directory:
cd /var/www/
2. Clone Project
git clone git@github.com:Mergo13/CRM-Europe.git
cd CRM-Europe
3. Install Dependencies (Production Mode)
composer install --no-dev --optimize-autoloader
4. Create Production .env
APP_ENV=production

DB_HOST=localhost
DB_PORT=3306
DB_NAME=crm_live
DB_USER=crm_user
DB_PASS=secure_password
DB_CHARSET=utf8mb4
5. Create Production Database
CREATE DATABASE crm_live
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

CREATE USER 'crm_user'@'localhost' IDENTIFIED BY 'secure_password';
GRANT ALL PRIVILEGES ON crm_live.* TO 'crm_user'@'localhost';
FLUSH PRIVILEGES;
6. Set Permissions
chmod -R 755 CRM-Europe
chmod -R 775 pdf/
7. Configure Web Server Root
Recommended:
/var/www/CRM-Europe
Or if using a public folder:
/var/www/CRM-Europe/public
Restart server:
sudo systemctl restart apache2
# or
sudo systemctl restart nginx
🔄 Updating Production
After pushing changes to GitHub:
ssh user@server
cd /var/www/CRM-Europe
git pull
composer install --no-dev
