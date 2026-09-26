# Deployment — No Docker

## 1. Supported Deployment Model
Traditional server/VM deployment.

### Backend server requirements
- Linux server recommended (Ubuntu LTS or equivalent) or Windows Server if required.
- Nginx or Apache.
- PHP version compatible with selected Laravel release.
- PHP-FPM when using Nginx.
- Composer.
- MySQL 8+.
- Node.js only where frontend build occurs on server; preferred CI/local build can deploy static assets.

## 2. Process Components
1. Web server -> Laravel API/public.
2. MySQL service.
3. Queue worker processes using `php artisan queue:work database`.
4. Scheduler cron executing `php artisan schedule:run` each minute.
5. React static frontend served by Nginx/Apache or separately under same domain.

## 3. Supervisor Example Concept
Processes:
- `erp-worker-default`
- `erp-worker-reports`
- `erp-worker-notifications`

Configure auto-restart, logs and graceful restart during deployment using `php artisan queue:restart`.

## 4. Deployment Sequence
- enable maintenance mode when needed;
- backup DB;
- deploy code;
- composer install --no-dev;
- migrate with reviewed migrations;
- build/deploy React assets;
- clear/cache Laravel config/routes as appropriate;
- restart queue workers;
- health checks;
- disable maintenance mode.

## 5. Environment
Production `.env` must not be committed. Use dedicated MySQL credentials with least privilege. Configure `QUEUE_CONNECTION=database` explicitly.

## 6. SSL
HTTPS mandatory. Renew certificates automatically.

## 7. Monitoring
At minimum monitor:
- HTTP availability;
- disk space;
- MySQL health/storage;
- failed jobs;
- queue backlog age;
- backup success;
- application errors;
- worker process state.
