<?php
return ['backup_directory'=>env('ERP_BACKUP_DIRECTORY',storage_path('app/backups')),'mysqldump_binary'=>env('ERP_MYSQLDUMP_BINARY','mysqldump'),
    // Shown on the login page, before anyone (and so any company) is known.
    'platform_name'=>env('ERP_PLATFORM_NAME','دفتر'),
    // Name given to company 1 when the installation has no store name yet.
    'default_company_name'=>env('ERP_DEFAULT_COMPANY_NAME','الشركة الأولى')];
