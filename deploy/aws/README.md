# نشر «دفتر» على AWS EC2 (Amazon Linux 2023) بدون Docker

الطريقة: Nginx + PHP 8.3-FPM + MySQL 8.4 + Supervisor. العمّال والمجدول تحت Supervisor.

| الملف | الوظيفة |
|---|---|
| `install.sh` | يجهّز السيرفر مرة واحدة: الحزم، MySQL، Composer، Nginx، Supervisor، الصلاحيات |
| `deploy.sh` | يسحب من GitHub ويبني ويشغّل. يُستخدم أول مرة ومع كل تحديث |
| `nginx-daftar.conf` | إعداد Nginx (يُنسخ إلى `/etc/nginx/conf.d/daftar.conf`) |
| `supervisor-daftar.conf` | العمّال الثلاثة: queue وmaintenance وscheduler |
| `php-daftar.ini` | إعدادات PHP: حجم الرفع، المنطقة الزمنية، OPcache |
| `export-data.ps1` | يصدّر بياناتك الحالية من جهازك لنقلها للسيرفر (قراءة فقط) |

## 0) قبل البدء: الشبكة في AWS
في **Security Group** الخاصة بالسيرفر افتح:
- **22**: من عنوانك فقط.
- **80** و**443**: من أي مكان.

## 1) رفع المشروع إلى GitHub (على جهازك، مرة واحدة)
أنشئ على GitHub مستودعاً **خاصاً (Private)** فارغاً، ثم من مجلد المشروع:
```bash
git init
git add .
git commit -m "Initial commit"
git branch -M main
git remote add origin git@github.com:USERNAME/daftar.git
git push -u origin main
```
الملف `.gitignore` يمنع رفع أي مما يلي:
- `backend/.env` وملفات `.env` الأخرى.
- `.runtime`: قاعدة البيانات المحلية وكلمات المرور.
- `vendor` و`node_modules`.

قبل `git push` تأكد بالأمر `git status` أنه لا يظهر أي ملف `.env`.

## 2) سحب المشروع على السيرفر
ادخل إلى السيرفر بـ SSH كمستخدم `ec2-user`:
```bash
sudo dnf install -y git
ssh-keygen -t ed25519 -f ~/.ssh/github_daftar -N ""
cat ~/.ssh/github_daftar.pub
```
انسخ المفتاح الظاهر وأضفه في GitHub: المستودع ← Settings ← Deploy keys ← Add key (قراءة فقط). ثم:
```bash
printf 'Host github.com\n  IdentityFile ~/.ssh/github_daftar\n  IdentitiesOnly yes\n' >> ~/.ssh/config && chmod 600 ~/.ssh/config
sudo mkdir -p /var/www && sudo chown ec2-user:ec2-user /var/www
git clone git@github.com:USERNAME/daftar.git /var/www/daftar
cd /var/www/daftar
```
> المشروع يجب أن يكون تحت `/var/www`، لأن Nginx لا يستطيع القراءة من مجلد `/home`.

## 3) تجهيز السيرفر (مرة واحدة)
```bash
sudo bash deploy/aws/install.sh daftar.example.com
```
- ضع دومينك مكان `daftar.example.com`. وإن لم يكن لديك دومين بعد، ضع عنوان IP العام للسيرفر.
- إذا كانت ذاكرة السيرفر صغيرة (مثل t2.micro أو t3.micro بـ 1 GB)، يضيف السكربت swap بحجم 2 GB ويخفّف إعدادات MySQL.
- بعد انتهائه **اخرج من SSH وادخل مرة أخرى**، لتُطبَّق عضوية مجموعة `apache`.

## 4) قاعدة البيانات
كلمة مرور root المؤقتة التي أنشأها MySQL:
```bash
sudo grep 'temporary password' /var/log/mysqld.log
mysql -uroot -p
```
داخل MySQL نفّذ ما يلي. كلمات المرور يجب أن تحوي حروفاً كبيرة وصغيرة ورقماً ورمزاً:
```sql
ALTER USER 'root'@'localhost' IDENTIFIED BY 'كلمة-مرور-root-قوية';
CREATE DATABASE erp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'erp'@'localhost' IDENTIFIED BY 'كلمة-مرور-التطبيق-قوية';
GRANT ALL PRIVILEGES ON erp.* TO 'erp'@'localhost';
EXIT;
```

## 5) ملف الإعدادات `backend/.env`
```bash
cp backend/.env.example backend/.env
vi backend/.env
```
عدّل هذه القيم:
```env
APP_ENV=production
APP_DEBUG=false
APP_KEY=           # انقلت بياناتك؟ ضع المفتاح القديم (يطبعه export-data.ps1). نظام جديد؟ ولّد مفتاحاً بالأمر أدناه
APP_URL=https://daftar.example.com
FRONTEND_URL=https://daftar.example.com
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=erp
DB_USERNAME=erp
DB_PASSWORD=كلمة-مرور-التطبيق
SANCTUM_STATEFUL_DOMAINS=daftar.example.com
SESSION_SECURE_COOKIE=true    # اجعلها false مؤقتاً إن كنت تستعمل http وعنوان IP بدون شهادة
LOG_LEVEL=warning
```
توليد مفتاح لنظام جديد:
```bash
php -r 'echo "base64:".base64_encode(random_bytes(32)).PHP_EOL;'
```

## 6) أول تشغيل: اختر واحداً

### (أ) نظام جديد فارغ
```bash
bash deploy/aws/deploy.sh --first-install
sudo -u apache php backend/artisan erp:create-owner your@email.com
```

### (ب) نقل بياناتك الحالية من جهازك
على جهازك (Windows)، من مجلد المشروع. يقرأ من نسختك المحلية فقط:
```powershell
powershell -ExecutionPolicy Bypass -File deploy\aws\export-data.ps1
```
ارفع الملفين إلى السيرفر:
```powershell
scp -i path\to\key.pem $env:USERPROFILE\Desktop\daftar-export\erp.sql $env:USERPROFILE\Desktop\daftar-export\storage.tgz ec2-user@SERVER_IP:/tmp/
```
على السيرفر. قاعدة `erp` جديدة وفارغة، والاستيراد يملؤها:
```bash
mysql -u erp -p erp < /tmp/erp.sql
sudo tar -xzf /tmp/storage.tgz -C /tmp
sudo cp -a /tmp/storage-app/. /var/www/daftar/backend/storage/app/
sudo chown -R apache:apache /var/www/daftar/backend/storage
bash deploy/aws/deploy.sh --no-pull
```
عندما تتأكد أن كل شيء ظهر، احذف النسخ المؤقتة لأنها تحوي بيانات عملاء، من السيرفر ومن سطح المكتب:
```bash
sudo rm -rf /tmp/erp.sql /tmp/storage.tgz /tmp/storage-app
```

## 7) شهادة HTTPS (عندما يشير الدومين إلى السيرفر)
```bash
sudo certbot --nginx -d daftar.example.com --redirect -m you@email.com --agree-tos
sudo systemctl enable --now certbot-renew.timer
```

## التحديث لاحقاً (بعد كل `git push`)
```bash
cd /var/www/daftar && bash deploy/aws/deploy.sh
```
يسحب التغييرات، ويبني الخادم والواجهة، ويطبّق migrations الجديدة، ويعيد تشغيل العمّال. **لا يحذف أي بيانات.**

## أوامر مفيدة
| المهمة | الأمر |
|---|---|
| حالة العمّال | `sudo supervisorctl status` |
| إعادة تشغيل العمّال | `sudo supervisorctl restart 'daftar:*'` |
| سجل أخطاء Laravel | `sudo tail -50 /var/www/daftar/backend/storage/logs/laravel.log` |
| سجل Nginx | `sudo tail -50 /var/log/nginx/error.log` |
| سجلات العمّال | `sudo tail -50 /var/log/supervisor/daftar-queue.log` |
| نسخة احتياطية فورية | `sudo -u apache php /var/www/daftar/backend/artisan erp:backup` |
| مكان النسخ الاحتياطية | `/var/www/daftar/backend/storage/app/backups` (انسخها دورياً خارج السيرفر) |

## الصلاحيات (ما يضبطه `install.sh`)
- **الكود:** ملك `ec2-user`، ويُسحب ويُبنى به.
- **`backend/storage` و`backend/bootstrap/cache`:** ملك `apache`، وهو مستخدم PHP-FPM والعمّال. هذان المجلدان فقط قابلان للكتابة من التطبيق.
- **`backend/.env`:** صلاحية `640`، مالكه `ec2-user` ومجموعته `apache`. التطبيق يقرأه ولا يعدّله.
- **أوامر artisan:** تُشغَّل كـ `apache` (`sudo -u apache php artisan …`) حتى تبقى الملفات بمالك واحد.
