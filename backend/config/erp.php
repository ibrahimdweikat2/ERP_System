<?php
return ['backup_directory'=>env('ERP_BACKUP_DIRECTORY',storage_path('app/backups')),'mysqldump_binary'=>env('ERP_MYSQLDUMP_BINARY','mysqldump'),
    // Shown on the login page, before anyone (and so any company) is known.
    'platform_name'=>env('ERP_PLATFORM_NAME','دفتر'),
    // Name given to company 1 when the installation has no store name yet.
    'default_company_name'=>env('ERP_DEFAULT_COMPANY_NAME','الشركة الأولى'),
    // Company licenses, activated against a LicenseBox server (see App\Domains\Licensing).
    // Without a valid license a company's users see only the dashboard.
    'license'=>[
        'enforce'=>(bool) env('ERP_LICENSE_ENFORCE',true),
        'server_url'=>env('LICENSEBOX_SERVER_URL'),
        'product_id'=>env('LICENSEBOX_PRODUCT_ID'),
        // An external API key of that product; never an internal one.
        'api_key'=>env('LICENSEBOX_API_KEY'),
        // The server's RSA public key (LicenseBox: storage/app/private/keys/public.pem). Every response is checked against it.
        'public_key_path'=>env('LICENSEBOX_PUBLIC_KEY_PATH') ?: storage_path('app/private/licensebox-public.pem'),
        // Or let the key be downloaded once from the server's public URL into public_key_path;
        // it is accepted only when its fingerprint equals the Key ID shown on LicenseBox's Settings page.
        'public_key_url'=>env('LICENSEBOX_PUBLIC_KEY_URL'),
        'key_id'=>env('LICENSEBOX_KEY_ID'),
        // Domain reported on activation; defaults to the host of FRONTEND_URL.
        'domain'=>env('ERP_LICENSE_DOMAIN'),
        'timeout'=>(int) env('LICENSEBOX_TIMEOUT',15),
    ]];
