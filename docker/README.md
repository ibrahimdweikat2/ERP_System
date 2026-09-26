# تشغيل «دفتر» على Docker

يشغّل `docker-compose.yml` ستّ حاويات:

| الحاوية | الدور |
|---|---|
| `web` | Nginx: يعرض الواجهة ويمرّر `/api` و`/sanctum` إلى Laravel، ويعرض صور المنتجات من `/storage` |
| `app` | Laravel (php-fpm)، ويطبّق migrations عند التشغيل |
| `queue` | عامل المهام: التقارير والتصدير والإشعارات |
| `maintenance` | عامل الصيانة: النسخ الاحتياطي الليلي |
| `scheduler` | المجدول: يطلق المهام الدورية |
| `db` | MySQL 8.4 |

المنفذ الوحيد المفتوح هو منفذ `web`. قاعدة البيانات لا تُفتح خارج Docker.

البيانات محفوظة في مجلدين دائمين (volumes) لا يحذفهما `docker compose down`:
- `erp-mysql`: قاعدة البيانات.
- `erp-storage`: الصور والمرفقات والنسخ الاحتياطية.

## أول تشغيل

1. انسخ ملف الإعدادات:
   ```powershell
   Copy-Item .env.docker.example .env
   ```
2. عدّل `.env`:
   - **`APP_KEY`: انسخه كما هو من `backend/.env`.** بدونه لا تُفك البيانات المشفّرة المنقولة، مثل أرقام الحسابات البنكية.
   - `DB_PASSWORD` و`DB_ROOT_PASSWORD`: كلمتا مرور قويتان من اختيارك.
   - `APP_URL` و`FRONTEND_URL` و`SANCTUM_STATEFUL_DOMAINS`: العنوان الذي ستفتح منه المنصة. انظر «الفتح من أجهزة الشبكة».
3. شغّل:
   ```powershell
   docker compose up -d --build
   ```
   أول بناء يأخذ بضع دقائق. بعدها افتح `http://localhost`.

## نقل بياناتك الحالية إلى Docker

بعد أول تشغيل، وقاعدة MySQL المحلية (المنفذ 3307) تعمل:
```powershell
powershell -ExecutionPolicy Bypass -File scripts\docker-import-local-data.ps1
```
ينقل السكربت قاعدة البيانات، وصور المنتجات، والمرفقات. قاعدة Docker تُستبدل بالكامل، والنسخة المحلية لا تتغيّر. بعدها ادخل بحسابك المعتاد.

## بدء نظام جديد فارغ (بدل النقل)

عند أول تشغيل على قاعدة فارغة تُنشأ تلقائياً الأدوار والصلاحيات ودليل الحسابات والسياسات. أنشئ حساب المالك:
```powershell
docker compose exec --user www-data app php artisan erp:create-owner your@email.com
```

## الفتح من أجهزة الشبكة

1. اعرف عنوان الجهاز من `ipconfig`، مثلاً `192.168.1.20`.
2. في `.env`:
   ```env
   APP_URL=http://192.168.1.20
   FRONTEND_URL=http://192.168.1.20
   SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1,192.168.1.20
   ```
   إذا غيّرت `ERP_HTTP_PORT` عن 80، أضف المنفذ للعناوين، مثل `192.168.1.20:8080`.
3. طبّق التغيير:
   ```powershell
   docker compose up -d
   ```
4. اسمح بالمنفذ في جدار حماية Windows، مرة واحدة ومن PowerShell كمسؤول:
   ```powershell
   netsh advfirewall firewall add rule name="Daftar HTTP" dir=in action=allow protocol=TCP localport=80
   ```

## أوامر يومية

| المهمة | الأمر |
|---|---|
| الحالة | `docker compose ps` |
| سجل الأخطاء | `docker compose logs -f app` |
| إيقاف (البيانات تبقى) | `docker compose down` |
| تحديث بعد تعديل الكود | `docker compose up -d --build` |
| نسخة احتياطية فورية | `docker compose exec --user www-data maintenance php artisan erp:backup` |
| نسخ النسخ الاحتياطية إلى الجهاز | `docker compose cp app:/var/www/html/storage/app/backups ./backups` |

## تنبيهات

- **لا تستخدم `docker compose down -v` أبداً:** الخيار `-v` يحذف قاعدة البيانات والصور نهائياً.
- **الاتصال بدون تشفير:** الإعداد الحالي HTTP، وهو مناسب داخل شبكة المحل. للوصول من الإنترنت استخدم Tailscale أو Cloudflare Tunnel، ولا تفتح المنفذ في الراوتر. مع HTTPS اجعل `SESSION_SECURE_COOKIE=true`.
- **ابقِ نسخة احتياطية خارج الجهاز:** النسخ الليلية تُحفظ داخل `erp-storage`، فانسخها دورياً إلى قرص خارجي.
- **لا تشغّل النسختين على نفس البيانات:** بيئة التطوير (`scripts/start-development.ps1`) وDocker منفصلتان تماماً. ما تُدخله في إحداهما لا يظهر في الأخرى.
