# Production Deployment Guide

This document outlines the standard procedure for deploying the **Alliance Tax** plugin to a production SeAT environment.

## ⚠️ Pre-Deployment Checklist
1.  **Backup your Database**: Ensure you have a recent snapshot of your SeAT database.
2.  **Maintenance Window**: The installation requires a brief service interruption (maintenance mode).

---

## Environment Selection

Choose the installation method matching your SeAT host:

- [**Option A: Bare Metal / VPS**](#option-a-bare-metal--vps) (Standard Installation)
- [**Option B: Docker**](#option-b-docker-containers) (Containerized Installation)

---

## Option A: Bare Metal / VPS

Perform these steps as the `root` user or a user with `sudo` privileges.

### 1. Enable Maintenance Mode
Prevent user activity during the update to ensure data integrity.
```bash
cd /var/www/seat
sudo php artisan down
```

### 2. Install Package
Install the package directly from Packagist.
```bash
# Fix permissions first to ensure www-data can write to composer.json
sudo chown -R www-data:www-data /var/www/seat

# Run as the web user (www-data)
sudo -u www-data composer require rejected/seat-alliance-tax
```

### 3. Database & Assets
Update the database schema and publish frontend assets.
```bash
# Run migrations
sudo php artisan migrate --force
```

### 4. Restart Services & Optimize
Clear old caches and restart the background workers.
```bash
sudo php artisan cache:clear
sudo php artisan config:clear
sudo php artisan route:clear
sudo supervisorctl restart seat:seat 
### You may have a different supervisor name, use `sudo supervisorctl status` to find it 
```

### 5. Go Live
Disable maintenance mode.
```bash
sudo php artisan up
```

### Verification
**Permissions**: The migration automatically registers permissions (`alliancetax.view`, etc.) and assigns them to the **Superuser** role. Use the Access Control settings in SeAT to grant them to other roles.

---

## Option B: Docker Containers

For Docker environments (e.g., `traefik_seat`), commands are executed inside the application container.

### 1. Enter Application Container
```bash
docker-compose exec -u www-data seat-app bash
```

### 2. Install Package (Inside Container)
Execute the following block inside the container shell:
```bash
# Install from Packagist
composer require rejected/seat-alliance-tax

# Migrate (This also installs permissions)
php artisan migrate --force


# Clear Cache
php artisan cache:clear
php artisan config:clear
```

### 3. Exit & Restart Stack
Exit the container and restart the stack to ensure all workers pick up the new code.
```bash
exit
docker-compose restart
```

> **Persistent Install Note**: To make this installation survive a `docker-compose down`, you must add `rejected/seat-alliance-tax` to your custom `Dockerfile` or startup script.

---

## Verification

To verify a successful installation:

1.  **Check Routes**:
    ```bash
    php artisan route:list | grep alliance-tax
    ```
    *Should output approximately 18 routes.*

2.  **Check Database**:
    ```bash
    php artisan model:show "Rejected\SeatAllianceTax\Models\AllianceTaxSetting"
    ```
    *Should display model attributes without errors.*

## Support
This is experimental and I take no responsibility for any damage it may cause.
 If you encounter issues during deployment:

*   **Logs**: Check `/var/www/seat/storage/logs/laravel.log`
*   **Missing Permissions**: If the menu icon is missing, run this fallback command:
    ```bash
    sudo mysql seat -e "INSERT IGNORE INTO permissions (title, name, description, division, created_at, updated_at) VALUES ('View Alliance Tax', 'alliancetax.view', 'View alliance mining tax information', 'financial', NOW(), NOW()), ('Manage Alliance Tax', 'alliancetax.manage', 'Manage alliance mining tax settings', 'financial', NOW(), NOW()), ('Alliance Tax Reports', 'alliancetax.reports', 'Access alliance tax reports', 'financial', NOW(), NOW()), ('Alliance Tax Administrator', 'alliancetax.admin', 'Full administrative access', 'financial', NOW(), NOW()); INSERT IGNORE INTO permission_role (permission_id, role_id) SELECT p.id, r.id FROM permissions p CROSS JOIN roles r WHERE p.name LIKE 'alliancetax.%' AND r.title = 'Superuser';"
    ```
