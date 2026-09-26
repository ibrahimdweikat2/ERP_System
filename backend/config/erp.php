<?php
return ['backup_directory'=>env('ERP_BACKUP_DIRECTORY',storage_path('app/backups')),'mysqldump_binary'=>env('ERP_MYSQLDUMP_BINARY','mysqldump')];
