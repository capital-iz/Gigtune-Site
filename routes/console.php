<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('gigtune:wp-serve {--host=127.0.0.1} {--port=8010}', function () {
    $root = (string) config('gigtune.wordpress.root');
    if ($root === '' || !is_dir($root)) {
        $this->error('WordPress root does not exist: ' . $root);
        return 1;
    }

    $host = (string) $this->option('host');
    $port = (int) $this->option('port');
    if ($port <= 0) {
        $this->error('Invalid port.');
        return 1;
    }

    $command = sprintf(
        '%s -S %s -t %s',
        escapeshellarg(PHP_BINARY),
        escapeshellarg($host . ':' . $port),
        escapeshellarg($root)
    );

    $this->info("Serving legacy WordPress at http://{$host}:{$port}");
    $this->line('Press Ctrl+C to stop.');

    passthru($command, $exitCode);
    return (int) $exitCode;
})->purpose('Run the legacy WordPress backend used by the Laravel bridge');

Artisan::command('gigtune:wp-ping', function () {
    $baseUrl = rtrim((string) config('gigtune.wordpress.base_url', ''), '/');
    if ($baseUrl === '') {
        $this->error('GIGTUNE_WORDPRESS_BASE_URL is not configured.');
        return 1;
    }

    try {
        $response = Http::timeout(10)->get($baseUrl . '/');
    } catch (\Throwable $throwable) {
        $this->error('WordPress backend is unreachable: ' . $throwable->getMessage());
        return 1;
    }

    $this->info('WordPress backend status: ' . $response->status());
    return $response->successful() ? 0 : 1;
})->purpose('Check if the legacy WordPress backend is reachable');

Artisan::command('gigtune:db-import {sql_path} {--database=} {--drop-existing}', function () {
    $sqlPath = (string) $this->argument('sql_path');
    if (!is_file($sqlPath)) {
        $this->error('SQL file not found: ' . $sqlPath);
        return 1;
    }

    $host = (string) env('WP_DB_HOST', '127.0.0.1');
    $port = (int) env('WP_DB_PORT', 3306);
    $username = (string) env('WP_DB_USERNAME', 'root');
    $password = (string) env('WP_DB_PASSWORD', '');
    $charset = (string) env('WP_DB_CHARSET', 'utf8mb4');
    $database = trim((string) ($this->option('database') ?: env('WP_DB_DATABASE', '')));

    if ($database === '') {
        $this->error('Target database is empty. Set WP_DB_DATABASE or pass --database=');
        return 1;
    }

    $this->info("Connecting to {$host}:{$port} ...");
    $server = @new mysqli($host, $username, $password, '', $port);
    if ($server->connect_errno) {
        $this->error('MySQL connect failed: ' . $server->connect_error);
        return 1;
    }
    $server->set_charset($charset);

    if (!$server->query('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $database) . '` CHARACTER SET ' . $charset)) {
        $this->error('Failed to create database: ' . $server->error);
        return 1;
    }
    $server->close();

    $mysqli = @new mysqli($host, $username, $password, $database, $port);
    if ($mysqli->connect_errno) {
        $this->error('MySQL db connect failed: ' . $mysqli->connect_error);
        return 1;
    }
    $mysqli->set_charset($charset);

    if ((bool) $this->option('drop-existing')) {
        $this->warn('Dropping existing tables from target DB before import ...');
        $tablesResult = $mysqli->query('SHOW TABLES');
        if ($tablesResult instanceof mysqli_result) {
            $tables = [];
            while ($row = $tablesResult->fetch_row()) {
                if (isset($row[0]) && is_string($row[0])) {
                    $tables[] = $row[0];
                }
            }
            $tablesResult->free();

            if (!empty($tables)) {
                $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
                foreach ($tables as $table) {
                    $mysqli->query('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
                }
                $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
            }
        }
    }

    $this->info('Importing SQL file ...');
    $handle = fopen($sqlPath, 'rb');
    if ($handle === false) {
        $this->error('Could not open SQL file for reading.');
        return 1;
    }

    $delimiter = ';';
    $buffer = '';
    $lineNumber = 0;
    $statementCount = 0;

    while (($line = fgets($handle)) !== false) {
        $lineNumber++;
        $trimmed = trim((string) $line);

        if ($lineNumber === 1) {
            $line = (string) Str::of($line)->ltrim("\xEF\xBB\xBF");
            $trimmed = trim($line);
        }

        if ($trimmed === '' || str_starts_with($trimmed, '-- ') || str_starts_with($trimmed, '#')) {
            continue;
        }

        if (str_starts_with(strtoupper($trimmed), 'DELIMITER ')) {
            $delimiter = trim(substr($trimmed, 10));
            if ($delimiter === '') {
                $delimiter = ';';
            }
            continue;
        }

        $buffer .= $line;
        if (!str_ends_with(rtrim($trimmed), $delimiter)) {
            continue;
        }

        $statement = rtrim($buffer);
        $statement = substr($statement, 0, -strlen($delimiter));
        $statement = trim($statement);
        $buffer = '';

        if ($statement === '') {
            continue;
        }

        if (!$mysqli->query($statement)) {
            fclose($handle);
            $this->error('SQL import failed at line ' . $lineNumber . ': ' . $mysqli->error);
            return 1;
        }

        $statementCount++;
    }

    fclose($handle);
    $mysqli->close();

    $this->info('SQL import complete. Statements executed: ' . $statementCount);
    return 0;
})->purpose('Import a WordPress SQL dump into the configured MySQL database');

Artisan::command('gigtune:db-integrity-check {--json=}', function () {
    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $db = DB::connection($connection);

    try {
        $db->getPdo();
    } catch (\Throwable $throwable) {
        $this->error('Database connection failed: ' . $throwable->getMessage());
        return 1;
    }

    $usersTable = $prefix . 'users';
    $usermetaTable = $prefix . 'usermeta';
    $postsTable = $prefix . 'posts';
    $postmetaTable = $prefix . 'postmeta';
    $optionsTable = $prefix . 'options';

    $schema = $db->getSchemaBuilder();
    $requiredTables = [
        $usersTable,
        $usermetaTable,
        $postsTable,
        $postmetaTable,
        $prefix . 'terms',
        $prefix . 'term_taxonomy',
        $prefix . 'term_relationships',
        $optionsTable,
    ];

    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!$schema->hasTable($table)) {
            $missingTables[] = $table;
        }
    }

    $errors = [];
    $warnings = [];
    if (!empty($missingTables)) {
        $errors[] = 'Missing required tables: ' . implode(', ', $missingTables);
    }

    $tableCounts = [];
    foreach ([$usersTable, $usermetaTable, $postsTable, $postmetaTable, $optionsTable] as $table) {
        if ($schema->hasTable($table)) {
            $tableCounts[$table] = (int) $db->table($table)->count();
        }
    }

    $requiredOptions = ['siteurl', 'home', 'blogname'];
    $missingOptions = [];
    if ($schema->hasTable($optionsTable)) {
        foreach ($requiredOptions as $optionName) {
            $exists = $db->table($optionsTable)
                ->where('option_name', $optionName)
                ->exists();
            if (!$exists) {
                $missingOptions[] = $optionName;
            }
        }
    }
    if (!empty($missingOptions)) {
        $errors[] = 'Missing required options: ' . implode(', ', $missingOptions);
    }

    $postTypeCounts = [];
    if ($schema->hasTable($postsTable)) {
        $trackedPostTypes = [
            'page',
            'artist_profile',
            'gt_client_profile',
            'gigtune_booking',
            'gigtune_dispute',
            'gigtune_payment',
            'gigtune_payout',
            'gigtune_refund',
            'gigtune_kyc_submission',
            'gigtune_kyc_submissi',
            'gigtune_message',
            'gigtune_notification',
        ];

        foreach ($trackedPostTypes as $postType) {
            $postTypeCounts[$postType] = (int) $db->table($postsTable)
                ->where('post_type', $postType)
                ->count();
        }

        foreach (['page', 'artist_profile', 'gt_client_profile', 'gigtune_booking'] as $criticalPostType) {
            if (($postTypeCounts[$criticalPostType] ?? 0) <= 0) {
                $errors[] = 'Missing critical post type records: ' . $criticalPostType;
            }
        }

        foreach (['gigtune_dispute', 'gigtune_payment', 'gigtune_payout', 'gigtune_refund', 'gigtune_kyc_submission', 'gigtune_kyc_submissi'] as $optionalPostType) {
            if (($postTypeCounts[$optionalPostType] ?? 0) <= 0) {
                $warnings[] = 'No records found for optional post type: ' . $optionalPostType;
            }
        }
    }

    $adminExists = false;
    if ($schema->hasTable($usermetaTable)) {
        $adminExists = $db->table($usermetaTable)
            ->whereIn('meta_key', [$prefix . 'capabilities', 'capabilities'])
            ->where('meta_value', 'like', '%administrator%')
            ->exists();
    }
    if (!$adminExists) {
        $errors[] = 'No administrator capability mapping found in usermeta.';
    }

    $bookingIntegrity = [
        'bookings_scanned' => 0,
        'active_bookings_scanned' => 0,
        'archived_bookings_skipped' => 0,
        'missing_linkage_count' => 0,
        'orphan_client_count' => 0,
        'orphan_artist_profile_count' => 0,
        'missing_linkage_sample' => [],
        'orphan_client_sample' => [],
        'orphan_artist_profile_sample' => [],
    ];

    if ($schema->hasTable($postsTable) && $schema->hasTable($postmetaTable) && $schema->hasTable($usersTable)) {
        $bookingIntegrity['bookings_scanned'] = (int) $db->table($postsTable)
            ->where('post_type', 'gigtune_booking')
            ->count();
        $bookingIntegrity['archived_bookings_skipped'] = (int) $db->table($postsTable)
            ->where('post_type', 'gigtune_booking')
            ->whereIn('post_status', ['trash', 'auto-draft'])
            ->count();

        $db->table($postsTable)
            ->select('ID')
            ->where('post_type', 'gigtune_booking')
            ->whereNotIn('post_status', ['trash', 'auto-draft'])
            ->orderBy('ID')
            ->chunk(500, function ($rows) use ($db, $postsTable, $postmetaTable, $usersTable, &$bookingIntegrity): void {
                $bookingIds = [];
                foreach ($rows as $row) {
                    $bookingIds[] = (int) ($row->ID ?? 0);
                }
                $bookingIds = array_values(array_filter($bookingIds, static fn (int $id): bool => $id > 0));
                if (empty($bookingIds)) {
                    return;
                }

                $metaRows = $db->table($postmetaTable)
                    ->select(['post_id', 'meta_key', 'meta_value', 'meta_id'])
                    ->whereIn('post_id', $bookingIds)
                    ->whereIn('meta_key', ['gigtune_booking_client_user_id', 'gigtune_booking_artist_profile_id'])
                    ->orderBy('post_id')
                    ->orderByDesc('meta_id')
                    ->get();

                $metaByBooking = [];
                foreach ($metaRows as $metaRow) {
                    $postId = (int) ($metaRow->post_id ?? 0);
                    if ($postId <= 0) {
                        continue;
                    }
                    $metaKey = (string) ($metaRow->meta_key ?? '');
                    if ($metaKey === '') {
                        continue;
                    }
                    if (!isset($metaByBooking[$postId][$metaKey])) {
                        $metaByBooking[$postId][$metaKey] = (string) ($metaRow->meta_value ?? '');
                    }
                }

                foreach ($bookingIds as $bookingId) {
                    $bookingIntegrity['active_bookings_scanned']++;
                    $meta = $metaByBooking[$bookingId] ?? [];
                    $clientUserId = (int) ($meta['gigtune_booking_client_user_id'] ?? 0);
                    $artistProfileId = (int) ($meta['gigtune_booking_artist_profile_id'] ?? 0);

                    if ($clientUserId <= 0 || $artistProfileId <= 0) {
                        $bookingIntegrity['missing_linkage_count']++;
                        if (count($bookingIntegrity['missing_linkage_sample']) < 20) {
                            $bookingIntegrity['missing_linkage_sample'][] = $bookingId;
                        }
                        continue;
                    }

                    $clientExists = $db->table($usersTable)
                        ->where('ID', $clientUserId)
                        ->exists();
                    if (!$clientExists) {
                        $bookingIntegrity['orphan_client_count']++;
                        if (count($bookingIntegrity['orphan_client_sample']) < 20) {
                            $bookingIntegrity['orphan_client_sample'][] = [
                                'booking_id' => $bookingId,
                                'client_user_id' => $clientUserId,
                            ];
                        }
                    }

                    $artistExists = $db->table($postsTable)
                        ->where('ID', $artistProfileId)
                        ->whereIn('post_type', ['artist_profile', 'gt_artist_profile'])
                        ->exists();
                    if (!$artistExists) {
                        $bookingIntegrity['orphan_artist_profile_count']++;
                        if (count($bookingIntegrity['orphan_artist_profile_sample']) < 20) {
                            $bookingIntegrity['orphan_artist_profile_sample'][] = [
                                'booking_id' => $bookingId,
                                'artist_profile_id' => $artistProfileId,
                            ];
                        }
                    }
                }
            });
    }

    if (($bookingIntegrity['active_bookings_scanned'] ?? 0) <= 0) {
        $warnings[] = 'No active booking rows were found; booking integrity check used archived data counts only.';
    }
    if (($bookingIntegrity['missing_linkage_count'] ?? 0) > 0) {
        $errors[] = 'Booking rows with missing linkage meta: ' . $bookingIntegrity['missing_linkage_count'];
    }
    if (($bookingIntegrity['orphan_client_count'] ?? 0) > 0) {
        $errors[] = 'Booking rows with orphan client references: ' . $bookingIntegrity['orphan_client_count'];
    }
    if (($bookingIntegrity['orphan_artist_profile_count'] ?? 0) > 0) {
        $errors[] = 'Booking rows with orphan artist profile references: ' . $bookingIntegrity['orphan_artist_profile_count'];
    }

    $summary = [
        'passed' => empty($errors),
        'connection' => $connection,
        'database' => (string) $db->getDatabaseName(),
        'required_tables' => [
            'count' => count($requiredTables),
            'missing' => $missingTables,
        ],
        'table_counts' => $tableCounts,
        'required_options' => [
            'checked' => $requiredOptions,
            'missing' => $missingOptions,
        ],
        'post_type_counts' => $postTypeCounts,
        'admin_capability_found' => $adminExists,
        'booking_integrity' => $bookingIntegrity,
        'errors' => $errors,
        'warnings' => $warnings,
    ];

    $jsonPath = trim((string) $this->option('json'));
    if ($jsonPath !== '') {
        file_put_contents($jsonPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote DB integrity report: ' . $jsonPath);
    }

    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return empty($errors) ? 0 : 1;
})->purpose('Validate migrated WordPress data integrity for Laravel GigTune runtime');

Artisan::command('gigtune:admin-bootstrap {login} {email} {password} {--name=}', function () {
    $service = app(\App\Services\WordPressUserService::class);

    $login = (string) $this->argument('login');
    $email = (string) $this->argument('email');
    $password = (string) $this->argument('password');
    $displayName = (string) ($this->option('name') ?: $login);

    try {
        $user = $service->createUser([
            'login' => $login,
            'email' => $email,
            'password' => $password,
            'display_name' => $displayName,
            'roles' => ['administrator'],
        ]);
    } catch (\Throwable $throwable) {
        $this->error('Bootstrap admin failed: ' . $throwable->getMessage());
        return 1;
    }

    $this->info('Admin account created: ID=' . (int) $user['id'] . ', login=' . $user['login']);
    return 0;
})->purpose('Create a first Laravel-managed GigTune admin account without WordPress admin');

Artisan::command('gigtune:admin-ensure {login} {email} {password} {--name=}', function () {
    $service = app(\App\Services\WordPressUserService::class);
    $hasher = app(\App\Support\WordPressPasswordHasher::class);

    $login = strtolower(trim((string) $this->argument('login')));
    $email = strtolower(trim((string) $this->argument('email')));
    $password = (string) $this->argument('password');
    $displayName = (string) ($this->option('name') ?: $login);

    try {
        $user = $service->createUser([
            'login' => $login,
            'email' => $email,
            'password' => $password,
            'display_name' => $displayName,
            'roles' => ['administrator'],
        ]);
        $this->info('Admin account created: ID=' . (int) $user['id'] . ', login=' . $user['login']);
        return 0;
    } catch (\Throwable $throwable) {
        $message = (string) $throwable->getMessage();
        if (!str_contains(strtolower($message), 'already exists')) {
            $this->error('Ensure admin failed: ' . $message);
            return 1;
        }
    }

    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $usersTable = $prefix . 'users';

    $id = DB::connection($connection)
        ->table($usersTable)
        ->where('user_login', $login)
        ->orWhere('user_email', $email)
        ->value('ID');

    $userId = (int) $id;
    if ($userId <= 0) {
        $this->error('Ensure admin failed: existing user was not found after conflict.');
        return 1;
    }

    DB::connection($connection)
        ->table($usersTable)
        ->where('ID', $userId)
        ->update([
            'user_pass' => $hasher->hash($password),
            'display_name' => $displayName,
            'user_email' => $email,
        ]);

    try {
        $service->updateUserRoles($userId, ['administrator']);
    } catch (\Throwable $throwable) {
        $this->error('Ensure admin failed while applying role: ' . $throwable->getMessage());
        return 1;
    }

    $this->info('Admin account updated: ID=' . $userId . ', login=' . $login);
    return 0;
})->purpose('Create admin if missing, otherwise update password and enforce administrator role');

Artisan::command('gigtune:parity-audit', function () {
    $root = rtrim((string) config('gigtune.wordpress.root', ''), '\\/');
    $pluginDir = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'gigtune-core';
    if ($root === '' || !is_dir($pluginDir)) {
        $this->error('Source plugin directory not found: ' . $pluginDir);
        return 1;
    }

    $pluginFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($pluginDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        if (strtolower((string) $fileInfo->getExtension()) !== 'php') {
            continue;
        }
        $pluginFiles[] = $fileInfo->getPathname();
    }
    $pluginFiles = array_values(array_unique($pluginFiles));
    sort($pluginFiles);
    if (empty($pluginFiles)) {
        $this->error('No plugin PHP files found in source plugin directory.');
        return 1;
    }

    $normalizeRoute = static function (string $route): string {
        $route = '/' . ltrim(trim($route), '/');
        $route = preg_replace('/\\(\\?P<([a-zA-Z0-9_]+)>\\\\d\\+\\)/', '{$1}', $route) ?? $route;
        return rtrim($route, '/') ?: '/';
    };

    $wpRoutes = [];
    $wpShortcodes = [];
    foreach ($pluginFiles as $pluginFile) {
        $source = file_get_contents($pluginFile);
        if (!is_string($source) || $source === '') {
            continue;
        }

        preg_match_all("/register_rest_route\\(\\s*'gigtune\\/v1'\\s*,\\s*'([^']+)'/m", $source, $routeMatches);
        foreach (($routeMatches[1] ?? []) as $route) {
            $wpRoutes[] = $normalizeRoute('/wp-json/gigtune/v1' . trim((string) $route));
        }

        preg_match_all("/add_shortcode\\(\\s*'([^']+)'/m", $source, $shortcodeMatches);
        foreach (($shortcodeMatches[1] ?? []) as $shortcode) {
            $wpShortcodes[] = trim((string) $shortcode);
        }
    }
    $wpRoutes = array_values(array_unique($wpRoutes));
    sort($wpRoutes);

    $wpShortcodes = array_values(array_unique(array_filter($wpShortcodes, static fn ($value): bool => $value !== '')));
    sort($wpShortcodes);

    $laravelRoutes = [];
    foreach (app('router')->getRoutes() as $route) {
        $uri = $normalizeRoute((string) $route->uri());
        if (str_starts_with($uri, '/wp-json/gigtune/v1')) {
            $laravelRoutes[] = $uri;
        }
    }
    $laravelRoutes = array_values(array_unique($laravelRoutes));
    sort($laravelRoutes);

    $laravelShortcodes = [];
    $shortcodeServicePath = app_path('Services/GigTuneShortcodeService.php');
    $shortcodeServiceSource = is_file($shortcodeServicePath) ? file_get_contents($shortcodeServicePath) : false;
    if (is_string($shortcodeServiceSource) && $shortcodeServiceSource !== '') {
        preg_match_all("/'(?<code>gigtune_[^']+)'\\s*=>/m", $shortcodeServiceSource, $laravelShortcodeMatches);
        $laravelShortcodes = array_values(array_unique(array_map(
            static fn ($value): string => trim((string) $value),
            $laravelShortcodeMatches['code'] ?? []
        )));
    }
    sort($laravelShortcodes);

    $missingRoutes = array_values(array_diff($wpRoutes, $laravelRoutes));
    $extraRoutes = array_values(array_diff($laravelRoutes, $wpRoutes));
    $missingShortcodes = array_values(array_diff($wpShortcodes, $laravelShortcodes));
    $extraShortcodes = array_values(array_diff($laravelShortcodes, $wpShortcodes));

    $this->line('Source REST routes: ' . count($wpRoutes));
    $this->line('Laravel REST routes: ' . count($laravelRoutes));
    $this->line('Source shortcodes: ' . count($wpShortcodes));
    $this->line('Laravel shortcodes: ' . count($laravelShortcodes));
    $this->newLine();

    if (!empty($missingRoutes)) {
        $this->warn('Missing REST routes in Laravel: ' . count($missingRoutes));
        foreach ($missingRoutes as $route) {
            $this->line('  - ' . $route);
        }
    } else {
        $this->info('No missing REST routes detected for gigtune/v1.');
    }

    if (!empty($extraRoutes)) {
        $this->warn('Extra Laravel routes (not found in source register_rest_route): ' . count($extraRoutes));
        foreach ($extraRoutes as $route) {
            $this->line('  - ' . $route);
        }
    }

    if (!empty($missingShortcodes)) {
        $this->warn('Missing shortcodes in Laravel handlers: ' . count($missingShortcodes));
        foreach ($missingShortcodes as $shortcode) {
            $this->line('  - ' . $shortcode);
        }
    } else {
        $this->info('No missing shortcodes detected in Laravel handlers.');
    }

    if (!empty($extraShortcodes)) {
        $this->warn('Extra Laravel shortcode handlers (not found in source): ' . count($extraShortcodes));
        foreach ($extraShortcodes as $shortcode) {
            $this->line('  - ' . $shortcode);
        }
    }

    return empty($missingRoutes) && empty($missingShortcodes) ? 0 : 2;
})->purpose('Audit gigtune-core REST and shortcode parity against Laravel routes');

Artisan::command('gigtune:full-scope-audit {--json=} {--sample=40}', function () {
    $root = rtrim((string) config('gigtune.wordpress.root', ''), '\\/');
    $pluginDir = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'gigtune-core';
    $sourceThemeDir = $root . DIRECTORY_SEPARATOR . 'wp-content' . DIRECTORY_SEPARATOR . 'themes' . DIRECTORY_SEPARATOR . 'gigtune-canon';
    $publicThemeDir = public_path('wp-content/themes/gigtune-canon');
    $resourceThemeDir = resource_path('wp-theme/gigtune-canon');
    $sampleSize = max(10, (int) $this->option('sample'));

    if ($root === '' || !is_dir($pluginDir) || !is_dir($sourceThemeDir)) {
        $this->error('Source-of-truth plugin/theme directories are not available under GIGTUNE_WORDPRESS_ROOT.');
        return 1;
    }

    $collectFiles = static function (array $dirs, array $extensions): array {
        $files = [];
        $extMap = array_fill_keys(array_map(static fn ($ext): string => strtolower(ltrim($ext, '.')), $extensions), true);
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if (!$info instanceof SplFileInfo || !$info->isFile()) {
                    continue;
                }
                $ext = strtolower((string) $info->getExtension());
                if (!isset($extMap[$ext])) {
                    continue;
                }
                $files[] = $info->getPathname();
            }
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    };

    $extractMatches = static function (array $files, string $pattern, int $group = 1): array {
        $values = [];
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            if (preg_match_all($pattern, $text, $m) !== 1 && empty($m[$group])) {
                continue;
            }
            foreach (($m[$group] ?? []) as $value) {
                $value = trim((string) $value);
                if ($value !== '') {
                    $values[] = $value;
                }
            }
        }
        $values = array_values(array_unique($values));
        sort($values);
        return $values;
    };

    $normalizeRoute = static function (string $route): string {
        $route = '/' . ltrim(trim($route), '/');
        $route = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', $route) ?? $route;
        return rtrim($route, '/') ?: '/';
    };

    $extractWpRouteSet = static function (array $files) use ($normalizeRoute): array {
        $routes = [];
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            preg_match_all('/register_rest_route\(\s*[\'"]gigtune\/v1[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]/m', $text, $matches);
            foreach (($matches[1] ?? []) as $route) {
                $routes[] = $normalizeRoute('/wp-json/gigtune/v1' . trim((string) $route));
            }
        }
        $routes = array_values(array_unique($routes));
        sort($routes);
        return $routes;
    };

    $extractWpPostTypes = static function (array $files): array {
        $postTypes = [];
        $constMap = [];
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            preg_match_all('/const\s+([A-Z0-9_]+)\s*=\s*[\'"]([^\'"]+)[\'"]/m', $text, $constMatches);
            foreach (($constMatches[1] ?? []) as $idx => $constName) {
                $constMap[(string) $constName] = (string) ($constMatches[2][$idx] ?? '');
            }
            preg_match_all('/define\(\s*[\'"]([A-Z0-9_]+)[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/m', $text, $defineMatches);
            foreach (($defineMatches[1] ?? []) as $idx => $constName) {
                $constMap[(string) $constName] = (string) ($defineMatches[2][$idx] ?? '');
            }
        }

        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            preg_match_all('/register_post_type\(\s*[\'"]([^\'"]+)[\'"]/m', $text, $directMatches);
            foreach (($directMatches[1] ?? []) as $value) {
                $postTypes[] = trim((string) $value);
            }

            preg_match_all('/register_post_type\(\s*([A-Z0-9_]+)\s*,/m', $text, $constMatches);
            foreach (($constMatches[1] ?? []) as $constName) {
                $mapped = trim((string) ($constMap[(string) $constName] ?? ''));
                if ($mapped !== '') {
                    $postTypes[] = $mapped;
                }
            }

            preg_match_all('/[\'"]post_type[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/m', $text, $arrayMatches);
            foreach (($arrayMatches[1] ?? []) as $value) {
                $postTypes[] = trim((string) $value);
            }
        }

        $postTypes = array_filter(array_values(array_unique($postTypes)), static function (string $value): bool {
            return str_starts_with($value, 'gigtune_') || str_starts_with($value, 'gt_') || $value === 'artist_profile';
        });
        sort($postTypes);
        return array_values($postTypes);
    };

    $extractLaravelPostTypes = static function (array $files): array {
        $postTypes = [];
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            preg_match_all('/[\'"]post_type[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/m', $text, $arrayMatches);
            foreach (($arrayMatches[1] ?? []) as $value) {
                $postTypes[] = trim((string) $value);
            }

            preg_match_all('/where\(\s*[\'"][^\'"]*post_type[^\'"]*[\'"]\s*,\s*[\'"]([^\'"]+)[\'"]\s*\)/m', $text, $whereMatches);
            foreach (($whereMatches[1] ?? []) as $value) {
                $postTypes[] = trim((string) $value);
            }

            preg_match_all('/whereIn\(\s*[\'"][^\'"]*post_type[^\'"]*[\'"]\s*,\s*\[([^\]]+)\]/m', $text, $whereInMatches);
            foreach (($whereInMatches[1] ?? []) as $rawList) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', (string) $rawList, $itemMatches);
                foreach (($itemMatches[1] ?? []) as $value) {
                    $postTypes[] = trim((string) $value);
                }
            }

            preg_match_all('/\$[A-Za-z0-9_]*postTypes?\s*=\s*\[([^\]]+)\];/ms', $text, $varListMatches);
            foreach (($varListMatches[1] ?? []) as $rawList) {
                preg_match_all('/[\'"]([^\'"]+)[\'"]/', (string) $rawList, $itemMatches);
                foreach (($itemMatches[1] ?? []) as $value) {
                    $postTypes[] = trim((string) $value);
                }
            }
        }

        $postTypes = array_filter(array_values(array_unique($postTypes)), static function (string $value): bool {
            return str_starts_with($value, 'gigtune_') || str_starts_with($value, 'gt_') || $value === 'artist_profile';
        });
        sort($postTypes);
        return array_values($postTypes);
    };

    $extractMetaKeysFromContext = static function (array $files): array {
        $keys = [];
        $patterns = [
            '/(?:get_post_meta|update_post_meta|add_post_meta|delete_post_meta|metadata_exists)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/m',
            '/(?:get_user_meta|update_user_meta|add_user_meta|delete_user_meta)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/m',
            '/[\'"]meta_key[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/m',
            '/[\'"]key[\'"]\s*=>\s*[\'"]([^\'"]+)[\'"]/m',
            '/(?:upsertPostMeta|upsertUserMeta|latestUserMeta|latestUserMetaInt|getLatestPostMeta|getLatestPostMetaValue|getLatestUserMetaValue)\s*\([^,]+,\s*[\'"]([^\'"]+)[\'"]/m',
        ];
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            foreach ($patterns as $pattern) {
                preg_match_all($pattern, $text, $matches);
                foreach (($matches[1] ?? []) as $metaKey) {
                    $metaKey = trim(strtolower((string) $metaKey));
                    if (str_starts_with($metaKey, 'gigtune_')) {
                        $keys[] = $metaKey;
                    }
                }
            }
        }
        $keys = array_values(array_unique($keys));
        sort($keys);
        return $keys;
    };

    $extractCriticalStatuses = static function (array $files, array $metaKeys): array {
        $map = [];
        $ignoreTokens = array_fill_keys([
            'compare',
            'value',
            'key',
            'meta_key',
            'meta_value',
            'post_id',
            'user_id',
            'default',
            'status',
        ], true);
        $allowSimpleTokens = array_fill_keys([
            'pending',
            'open',
            'paid',
            'failed',
            'unpaid',
            'requested',
            'rejected',
            'resolved',
            'completed',
            'succeeded',
            'success',
            'confirm',
            'confirmed',
        ], true);
        foreach ($metaKeys as $metaKey) {
            $map[$metaKey] = [];
        }
        foreach ($files as $file) {
            $text = @file_get_contents($file);
            if (!is_string($text) || $text === '') {
                continue;
            }
            foreach ($metaKeys as $metaKey) {
                $patterns = [
                    '/(?:update_post_meta|add_post_meta|update_user_meta|add_user_meta|upsertPostMeta|upsertUserMeta|upsert_post_meta|upsert_user_meta)\s*\([^,]+,\s*[\'"]'
                        . preg_quote($metaKey, '/')
                        . '[\'"]\s*,\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/m',
                    '/[\'"]key[\'"]\s*=>\s*[\'"]' . preg_quote($metaKey, '/')
                        . '[\'"][^\]]{0,220}?[\'"]value[\'"]\s*=>\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/ms',
                    '/[\'"]meta_key[\'"]\s*=>\s*[\'"]' . preg_quote($metaKey, '/')
                        . '[\'"][^\]]{0,220}?[\'"]meta_value[\'"]\s*=>\s*[\'"]([A-Za-z0-9_\-]+)[\'"]/ms',
                ];
                foreach ($patterns as $pattern) {
                    preg_match_all($pattern, $text, $matches);
                    foreach (($matches[1] ?? []) as $token) {
                        $status = trim(strtolower((string) $token));
                        if ($status === '' || isset($ignoreTokens[$status]) || str_starts_with($status, 'gigtune_')) {
                            continue;
                        }
                        if (str_contains($status, '_') || isset($allowSimpleTokens[$status])) {
                            $map[$metaKey][] = $status;
                        }
                    }
                }
            }
        }

        foreach ($map as $metaKey => $statuses) {
            $statuses = array_values(array_unique($statuses));
            sort($statuses);
            $map[$metaKey] = $statuses;
        }
        return $map;
    };

    $relativeFileSet = static function (string $baseDir): array {
        if (!is_dir($baseDir)) {
            return [];
        }
        $files = [];
        $base = rtrim(str_replace('\\', '/', $baseDir), '/');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $info) {
            if (!$info instanceof SplFileInfo || !$info->isFile()) {
                continue;
            }
            $full = str_replace('\\', '/', $info->getPathname());
            $rel = ltrim(substr($full, strlen($base)), '/');
            if ($rel === '' || str_starts_with($rel, 'node_modules/') || str_contains($rel, '/node_modules/')) {
                continue;
            }
            if (
                str_ends_with($rel, '.zip')
                || $rel === 'package.json'
                || $rel === 'package-lock.json'
                || $rel === 'tailwind.config.js'
                || $rel === 'input.css'
                || $rel === 'READ ME.txt'
            ) {
                continue;
            }
            $files[] = $rel;
        }
        $files = array_values(array_unique($files));
        sort($files);
        return $files;
    };

    $hashMismatches = static function (string $sourceBase, string $targetBase, array $commonFiles): array {
        $mismatch = [];
        foreach ($commonFiles as $rel) {
            $srcPath = $sourceBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $dstPath = $targetBase . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (!is_file($srcPath) || !is_file($dstPath)) {
                continue;
            }
            $srcHash = @hash_file('sha256', $srcPath) ?: '';
            $dstHash = @hash_file('sha256', $dstPath) ?: '';
            if ($srcHash !== '' && $dstHash !== '' && $srcHash !== $dstHash) {
                $mismatch[] = $rel;
            }
        }
        sort($mismatch);
        return $mismatch;
    };

    $pluginPhpFiles = $collectFiles([$pluginDir], ['php']);
    $laravelLogicFiles = $collectFiles([app_path(), base_path('routes'), resource_path('wp-theme/gigtune-canon')], ['php']);

    $wpShortcodes = $extractMatches($pluginPhpFiles, '/add_shortcode\(\s*[\'"]([^\'"]+)[\'"]/m');
    $wpRoutes = $extractWpRouteSet($pluginPhpFiles);
    $wpAjaxActions = $extractMatches($pluginPhpFiles, '/add_action\(\s*[\'"]wp_ajax(?:_nopriv)?_([^\'"]+)[\'"]/m');
    $wpMetaKeys = $extractMetaKeysFromContext($pluginPhpFiles);
    $wpPostTypes = $extractWpPostTypes($pluginPhpFiles);

    $laravelRoutes = [];
    foreach (app('router')->getRoutes() as $route) {
        $uri = $normalizeRoute((string) $route->uri());
        if (str_starts_with($uri, '/wp-json/gigtune/v1')) {
            $laravelRoutes[] = $uri;
        }
    }
    $laravelRoutes = array_values(array_unique($laravelRoutes));
    sort($laravelRoutes);

    $shortcodeServicePath = app_path('Services/GigTuneShortcodeService.php');
    $shortcodeServiceSource = is_file($shortcodeServicePath) ? file_get_contents($shortcodeServicePath) : '';
    $laravelShortcodes = [];
    if (is_string($shortcodeServiceSource) && $shortcodeServiceSource !== '') {
        preg_match_all('/[\'"](gigtune_[^\'"]+)[\'"]\s*=>/m', $shortcodeServiceSource, $shortcodeMatches);
        $laravelShortcodes = array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), $shortcodeMatches[1] ?? [])));
    }
    sort($laravelShortcodes);

    $legacyAjaxPath = app_path('Http/Controllers/LegacyWordPressPathController.php');
    $legacyAjaxSource = is_file($legacyAjaxPath) ? file_get_contents($legacyAjaxPath) : '';
    $laravelAjaxActions = [];
    if (is_string($legacyAjaxSource) && $legacyAjaxSource !== '') {
        preg_match_all('/\$action\s*===\s*[\'"]([^\'"]+)[\'"]/m', $legacyAjaxSource, $ajaxMatches);
        $laravelAjaxActions = array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), $ajaxMatches[1] ?? [])));
    }
    sort($laravelAjaxActions);

    $laravelMetaKeys = array_values(array_unique(array_merge(
        $extractMetaKeysFromContext($laravelLogicFiles),
        $extractMatches($laravelLogicFiles, '/[\'"](gigtune_[a-z0-9_]+)[\'"]/')
    )));
    sort($laravelMetaKeys);
    $laravelPostTypes = $extractLaravelPostTypes($laravelLogicFiles);

    $criticalMetaKeys = [
        'gigtune_payment_status',
        'gigtune_payout_status',
        'gigtune_refund_status',
        'gigtune_dispute_status',
        'gigtune_booking_status',
        'gigtune_kyc_status',
        'gigtune_kyc_decision',
    ];
    $wpCriticalStatuses = $extractCriticalStatuses($pluginPhpFiles, $criticalMetaKeys);
    $laravelCriticalStatuses = $extractCriticalStatuses($laravelLogicFiles, $criticalMetaKeys);

    $criticalStatusParity = [];
    foreach ($criticalMetaKeys as $metaKey) {
        $sourceStatuses = $wpCriticalStatuses[$metaKey] ?? [];
        $laravelStatuses = $laravelCriticalStatuses[$metaKey] ?? [];
        $criticalStatusParity[$metaKey] = [
            'source' => $sourceStatuses,
            'laravel' => $laravelStatuses,
            'missing_in_laravel' => array_values(array_diff($sourceStatuses, $laravelStatuses)),
            'extra_in_laravel' => array_values(array_diff($laravelStatuses, $sourceStatuses)),
        ];
    }

    $sourceThemeFiles = $relativeFileSet($sourceThemeDir);
    $publicThemeFiles = $relativeFileSet($publicThemeDir);
    $resourceThemeFiles = $relativeFileSet($resourceThemeDir);
    $commonWithPublic = array_values(array_intersect($sourceThemeFiles, $publicThemeFiles));
    $commonWithResources = array_values(array_intersect($sourceThemeFiles, $resourceThemeFiles));

    $themePublicMissing = array_values(array_diff($sourceThemeFiles, $publicThemeFiles));
    $themePublicExtra = array_values(array_diff($publicThemeFiles, $sourceThemeFiles));
    $themeHashCompatAllowlist = [
        'page-artist-profile.php',
        'page-artists.php',
    ];
    $themePublicMismatchRaw = $hashMismatches($sourceThemeDir, $publicThemeDir, $commonWithPublic);
    $themePublicMismatch = array_values(array_diff($themePublicMismatchRaw, $themeHashCompatAllowlist));
    $themePublicMismatchCompatExcluded = array_values(array_intersect($themePublicMismatchRaw, $themeHashCompatAllowlist));

    $themeResourcesMissing = array_values(array_diff($sourceThemeFiles, $resourceThemeFiles));
    $themeResourcesExtra = array_values(array_diff($resourceThemeFiles, $sourceThemeFiles));
    $themeResourcesMismatchRaw = $hashMismatches($sourceThemeDir, $resourceThemeDir, $commonWithResources);
    $themeResourcesMismatch = array_values(array_diff($themeResourcesMismatchRaw, $themeHashCompatAllowlist));
    $themeResourcesMismatchCompatExcluded = array_values(array_intersect($themeResourcesMismatchRaw, $themeHashCompatAllowlist));

    $missingRoutes = array_values(array_diff($wpRoutes, $laravelRoutes));
    $missingShortcodes = array_values(array_diff($wpShortcodes, $laravelShortcodes));
    $missingAjaxActions = array_values(array_diff($wpAjaxActions, $laravelAjaxActions));
    $missingPostTypes = array_values(array_diff($wpPostTypes, $laravelPostTypes));
    $missingMetaKeys = array_values(array_diff($wpMetaKeys, $laravelMetaKeys));

    $statusMissing = [];
    foreach ($criticalStatusParity as $metaKey => $row) {
        if (!empty($row['missing_in_laravel'])) {
            $statusMissing[$metaKey] = $row['missing_in_laravel'];
        }
    }

    $report = [
        'passed' => empty($missingRoutes)
            && empty($missingShortcodes)
            && empty($missingAjaxActions)
            && empty($missingPostTypes)
            && empty($statusMissing)
            && empty($themePublicMissing)
            && empty($themePublicMismatch),
        'source_counts' => [
            'plugin_php_files' => count($pluginPhpFiles),
            'shortcodes' => count($wpShortcodes),
            'rest_routes' => count($wpRoutes),
            'ajax_actions' => count($wpAjaxActions),
            'post_types' => count($wpPostTypes),
            'meta_keys' => count($wpMetaKeys),
            'theme_files' => count($sourceThemeFiles),
        ],
        'laravel_counts' => [
            'logic_php_files' => count($laravelLogicFiles),
            'shortcodes' => count($laravelShortcodes),
            'rest_routes' => count($laravelRoutes),
            'ajax_actions' => count($laravelAjaxActions),
            'post_types' => count($laravelPostTypes),
            'meta_keys' => count($laravelMetaKeys),
            'public_theme_files' => count($publicThemeFiles),
            'resource_theme_files' => count($resourceThemeFiles),
        ],
        'diff' => [
            'missing_rest_routes' => $missingRoutes,
            'missing_shortcodes' => $missingShortcodes,
            'missing_ajax_actions' => $missingAjaxActions,
            'missing_post_types' => $missingPostTypes,
            'missing_meta_keys_count' => count($missingMetaKeys),
            'missing_meta_keys_sample' => array_slice($missingMetaKeys, 0, $sampleSize),
            'critical_statuses' => $criticalStatusParity,
        ],
        'theme_parity' => [
            'public' => [
                'missing_files' => $themePublicMissing,
                'extra_files' => $themePublicExtra,
                'hash_mismatch_files' => $themePublicMismatch,
                'hash_mismatch_compat_excluded' => $themePublicMismatchCompatExcluded,
            ],
            'resources' => [
                'missing_files_count' => count($themeResourcesMissing),
                'missing_files_sample' => array_slice($themeResourcesMissing, 0, $sampleSize),
                'extra_files' => $themeResourcesExtra,
                'hash_mismatch_files' => $themeResourcesMismatch,
                'hash_mismatch_compat_excluded' => $themeResourcesMismatchCompatExcluded,
            ],
        ],
    ];

    $jsonPath = trim((string) $this->option('json'));
    if ($jsonPath !== '') {
        $targetPath = str_starts_with($jsonPath, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $jsonPath) === 1
            ? $jsonPath
            : base_path($jsonPath);
        @file_put_contents($targetPath, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote full-scope audit report: ' . $targetPath);
    }

    $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return $report['passed'] ? 0 : 2;
})->purpose('Run full-scope WordPress-to-Laravel parity audit across logic and theme surfaces');

Artisan::command('gigtune:flow-dry-run {--cycles=1}', function () {
    $cycles = max(1, (int) $this->option('cycles'));

    if ($cycles > 1) {
        $this->info("Running {$cycles} dry-run flow cycle(s) in isolated subprocesses...");
        $matrix = [];
        $passed = 0;

        for ($i = 1; $i <= $cycles; $i++) {
            $cmd = escapeshellarg(PHP_BINARY)
                . ' '
                . escapeshellarg(base_path('artisan'))
                . ' gigtune:flow-dry-run --cycles=1';
            $output = [];
            $exitCode = 0;
            @exec($cmd . ' 2>&1', $output, $exitCode);
            $pass = $exitCode === 0;
            if ($pass) {
                $passed++;
            }
            $matrix[] = [
                'cycle' => $i,
                'pass' => $pass,
                'exit_code' => $exitCode,
            ];
            $this->line('Cycle ' . $i . ': ' . ($pass ? 'PASS' : 'FAIL'));
        }

        $summary = [
            'cycles' => $cycles,
            'passed_cycles' => $passed,
            'failed_cycles' => $cycles - $passed,
            'matrix' => $matrix,
        ];

        $this->newLine();
        $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return $summary['failed_cycles'] === 0 ? 0 : 1;
    }

    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $postsTable = $prefix . 'posts';
    $postmetaTable = $prefix . 'postmeta';
    $usersTable = $prefix . 'users';
    $usermetaTable = $prefix . 'usermeta';

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $results = [];

    $this->info("Running {$cycles} dry-run flow cycle(s)...");

    for ($cycle = 1; $cycle <= $cycles; $cycle++) {
        $db = DB::connection($connection);
        $checks = [];
        $cyclePass = true;

        $check = function (string $name, bool $pass, string $detail = '') use (&$checks, &$cyclePass): void {
            $checks[] = ['name' => $name, 'pass' => $pass, 'detail' => $detail];
            if (!$pass) {
                $cyclePass = false;
            }
        };

        $insertPostMeta = function (int $postId, string $metaKey, string $metaValue) use ($db, $postmetaTable): void {
            $db->table($postmetaTable)->insert([
                'post_id' => $postId,
                'meta_key' => $metaKey,
                'meta_value' => $metaValue,
            ]);
        };

        $getPostMeta = function (int $postId, string $metaKey) use ($db, $postmetaTable): string {
            $value = $db->table($postmetaTable)
                ->where('post_id', $postId)
                ->where('meta_key', $metaKey)
                ->orderByDesc('meta_id')
                ->value('meta_value');
            return is_string($value) ? $value : '';
        };

        $getUserMeta = function (int $userId, string $metaKey) use ($db, $usermetaTable): string {
            $value = $db->table($usermetaTable)
                ->where('user_id', $userId)
                ->where('meta_key', $metaKey)
                ->orderByDesc('umeta_id')
                ->value('meta_value');
            return is_string($value) ? $value : '';
        };

        $requestWithSession = function (string $method, string $uri, array $params, \Illuminate\Contracts\Session\Session $session) use ($kernel): array {
            $method = strtoupper($method);
            $server = [
                'HTTP_HOST' => '127.0.0.1:8002',
                'SERVER_PORT' => 8002,
                'REQUEST_SCHEME' => 'http',
                'HTTPS' => 'off',
            ];
            $request = \Illuminate\Http\Request::create($uri, $method, $params, [], [], $server);
            $request->setLaravelSession($session);

            $response = $kernel->handle($request);
            $status = (int) $response->getStatusCode();
            $location = (string) $response->headers->get('Location', '');
            $content = (string) $response->getContent();
            $kernel->terminate($request, $response);

            return [
                'status' => $status,
                'location' => $location,
                'content' => $content,
            ];
        };

        $db->beginTransaction();
        try {
            $adminId = (int) $db->table($usersTable . ' as u')
                ->select('u.ID')
                ->whereExists(function ($query) use ($usermetaTable, $prefix): void {
                    $query->selectRaw('1')
                        ->from($usermetaTable . ' as um')
                        ->whereColumn('um.user_id', 'u.ID')
                        ->whereIn('um.meta_key', [$prefix . 'capabilities', 'capabilities'])
                        ->where('um.meta_value', 'like', '%administrator%');
                })
                ->orderByDesc('u.ID')
                ->value('u.ID');

            if ($adminId <= 0) {
                throw new RuntimeException('No administrator user found.');
            }
            $check('admin_user_present', true, 'admin_id=' . $adminId);

            $dryRunUserId = (int) $db->table($usersTable)->insertGetId([
                'user_login' => 'dryrun_user_' . $cycle,
                'user_pass' => 'dryrun',
                'user_nicename' => 'dryrun-user-' . $cycle,
                'user_email' => 'dryrun+' . $cycle . '@local.test',
                'user_url' => '',
                'user_registered' => now()->format('Y-m-d H:i:s'),
                'user_activation_key' => '',
                'user_status' => 0,
                'display_name' => 'Dry Run User ' . $cycle,
            ]);
            $check('fixture_user_created', $dryRunUserId > 0, 'user_id=' . $dryRunUserId);

            $artistProfileId = (int) $db->table($postsTable)
                ->where('post_type', 'artist_profile')
                ->where('post_status', 'publish')
                ->orderByDesc('ID')
                ->value('ID');

            if ($artistProfileId <= 0) {
                $now = now();
                $artistProfileId = (int) $db->table($postsTable)->insertGetId([
                    'post_author' => 0,
                    'post_date' => $now->format('Y-m-d H:i:s'),
                    'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_content' => '',
                    'post_title' => 'Dry-run Artist',
                    'post_excerpt' => '',
                    'post_status' => 'publish',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                    'post_password' => '',
                    'post_name' => 'dry-run-artist-' . $cycle,
                    'to_ping' => '',
                    'pinged' => '',
                    'post_modified' => $now->format('Y-m-d H:i:s'),
                    'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                    'post_content_filtered' => '',
                    'post_parent' => 0,
                    'guid' => '',
                    'menu_order' => 0,
                    'post_type' => 'artist_profile',
                    'post_mime_type' => '',
                    'comment_count' => 0,
                ]);
            }
            $check('artist_profile_available', $artistProfileId > 0, 'artist_profile_id=' . $artistProfileId);

            $now = now();
            $bookingId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => $adminId,
                'post_date' => $now->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run Booking #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => 'dry-run-booking-' . $cycle,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $now->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gigtune_booking',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);

            $insertPostMeta($bookingId, 'gigtune_booking_status', 'ACCEPTED_PENDING_PAYMENT');
            $insertPostMeta($bookingId, 'gigtune_booking_client_user_id', (string) $adminId);
            $insertPostMeta($bookingId, 'gigtune_booking_artist_profile_id', (string) $artistProfileId);
            $insertPostMeta($bookingId, 'gigtune_payment_status', 'AWAITING_PAYMENT_CONFIRMATION');
            $insertPostMeta($bookingId, 'gigtune_payment_method', 'MANUAL');
            $insertPostMeta($bookingId, 'gigtune_payment_reference_human', 'DRY-RUN-' . $cycle);
            $insertPostMeta($bookingId, 'gigtune_payment_reported_at', (string) now()->timestamp);
            $insertPostMeta($bookingId, 'gigtune_payout_status', 'PENDING_MANUAL');
            $insertPostMeta($bookingId, 'gigtune_yoco_checkout_id', 'dry-checkout-' . $cycle);
            $check('fixture_booking_created', $bookingId > 0, 'booking_id=' . $bookingId);

            $paymentId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => $adminId,
                'post_date' => $now->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run Payment #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => 'dry-run-payment-' . $cycle,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $now->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gt_payment',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $insertPostMeta($paymentId, 'gigtune_payment_booking_id', (string) $bookingId);
            $insertPostMeta($paymentId, 'gigtune_payment_status', 'AWAITING_PAYMENT_CONFIRMATION');
            $insertPostMeta($paymentId, 'gigtune_amount_gross', '1200');
            $check('fixture_payment_created', $paymentId > 0, 'payment_id=' . $paymentId);

            $disputeId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => $adminId,
                'post_date' => $now->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run Dispute #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => 'dry-run-dispute-' . $cycle,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $now->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gigtune_dispute',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $insertPostMeta($disputeId, 'gigtune_dispute_booking_id', (string) $bookingId);
            $insertPostMeta($disputeId, 'gigtune_dispute_status', 'OPEN');
            $insertPostMeta($disputeId, 'gigtune_dispute_reason', 'Dry-run dispute');
            $check('fixture_dispute_created', $disputeId > 0, 'dispute_id=' . $disputeId);

            $session = app('session')->driver();
            $session->start();
            $session->put('gigtune_auth_user_id', $adminId);
            $session->put('gigtune_auth_logged_in_at', now()->toIso8601String());
            $session->put('gigtune_auth_remember', true);

            $guestSession = app('session')->driver('array');
            $guestSession->start();

            $post = function (string $path, array $payload) use ($requestWithSession, $session): array {
                $payload['_token'] = (string) $session->token();
                return $requestWithSession('POST', $path, $payload, $session);
            };

            $guestPaths = [
                '/',
                '/artists/',
                '/artist-profile/?artist_id=' . $artistProfileId,
                '/secret-admin-login-security',
                '/join/',
                '/sign-in/',
            ];
            $guestSmokeOk = true;
            $guestSmokeDetail = 'ok';
            foreach ($guestPaths as $guestPath) {
                $guestResp = $requestWithSession('GET', $guestPath, [], $guestSession);
                if ((int) $guestResp['status'] >= 500) {
                    $guestSmokeOk = false;
                    $guestSmokeDetail = 'path=' . $guestPath . ' status=' . (int) $guestResp['status'];
                    break;
                }
            }
            $check('guest_frontend_paths_no_500', $guestSmokeOk, $guestSmokeDetail);

            $authPaths = [
                '/my-account-page/',
                '/messages/',
                '/notifications/',
                '/artist-dashboard/',
                '/client-dashboard/',
                '/artist-profile-edit/',
                '/public-profile/',
                '/kyc/',
                '/kyc-status/',
                '/book-an-artist/?artist_id=' . $artistProfileId,
            ];
            $authSmokeOk = true;
            $authSmokeDetail = 'ok';
            foreach ($authPaths as $authPath) {
                $authResp = $requestWithSession('GET', $authPath, [], $session);
                if ((int) $authResp['status'] >= 500) {
                    $authSmokeOk = false;
                    $authSmokeDetail = 'path=' . $authPath . ' status=' . (int) $authResp['status'];
                    break;
                }
            }
            $check('auth_frontend_paths_no_500', $authSmokeOk, $authSmokeDetail);

            $guestUnreadResp = $requestWithSession('POST', '/admin-ajax.php', [
                'action' => 'gigtune_unread_notification_count',
            ], $guestSession);
            $guestUnreadJson = json_decode((string) $guestUnreadResp['content'], true);
            $guestUnreadOk = $guestUnreadResp['status'] === 200
                && is_array($guestUnreadJson)
                && array_key_exists('count', $guestUnreadJson)
                && (int) ($guestUnreadJson['count'] ?? -1) === 0;
            $check('ajax_unread_count_guest', $guestUnreadOk, 'status=' . $guestUnreadResp['status']);

            $authUnreadResp = $requestWithSession('POST', '/admin-ajax.php', [
                'action' => 'gigtune_unread_notification_count',
            ], $session);
            $authUnreadJson = json_decode((string) $authUnreadResp['content'], true);
            $authUnreadOk = $authUnreadResp['status'] === 200
                && is_array($authUnreadJson)
                && array_key_exists('count', $authUnreadJson);
            $check('ajax_unread_count_auth', $authUnreadOk, 'status=' . $authUnreadResp['status']);

            $requestWithSession('GET', '/logout', [], $session);

            $joinClientEmail = 'dryrun.client.' . $cycle . '.' . time() . '@local.test';
            $joinClientPassword = 'DryRun#Client' . $cycle . 'X9!';
            $joinArtistEmail = 'dryrun.artist.' . $cycle . '.' . time() . '@local.test';
            $joinArtistPassword = 'DryRun#Artist' . $cycle . 'Y9!';
            $joinApiSession = $session;

            $joinClientResp = $requestWithSession('POST', '/join/', [
                'gigtune_role' => 'gigtune_client',
                'gigtune_full_name' => 'Dry Client ' . $cycle,
                'gigtune_email' => $joinClientEmail,
                'gigtune_password' => $joinClientPassword,
                'gigtune_password_confirm' => $joinClientPassword,
                'gigtune_terms_acceptance' => '1',
            ], $joinApiSession);
            $clientJoinOk = (int) $joinClientResp['status'] === 302 && str_contains((string) $joinClientResp['location'], '/client-dashboard');
            $check('join_client_redirect', $clientJoinOk, 'status=' . (int) $joinClientResp['status'] . ' location=' . (string) $joinClientResp['location']);

            $joinedClientId = (int) $db->table($usersTable)
                ->where('user_email', $joinClientEmail)
                ->orderByDesc('ID')
                ->value('ID');
            $check('join_client_user_created', $joinedClientId > 0, 'user_id=' . $joinedClientId);

            $requestWithSession('GET', '/logout', [], $joinApiSession);

            $joinedClientProfileId = (int) $getUserMeta($joinedClientId, 'gigtune_client_profile_id');
            $joinedClientProfileExists = $joinedClientProfileId > 0
                && $db->table($postsTable)
                    ->where('ID', $joinedClientProfileId)
                    ->where('post_type', 'gt_client_profile')
                    ->exists();
            $check('join_client_profile_created', $joinedClientProfileExists, 'profile_id=' . $joinedClientProfileId);

            $joinArtistResp = $requestWithSession('POST', '/join/', [
                'gigtune_role' => 'gigtune_artist',
                'gigtune_full_name' => 'Dry Artist ' . $cycle,
                'gigtune_email' => $joinArtistEmail,
                'gigtune_password' => $joinArtistPassword,
                'gigtune_password_confirm' => $joinArtistPassword,
                'gigtune_terms_acceptance' => '1',
            ], $joinApiSession);
            $artistJoinOk = (int) $joinArtistResp['status'] === 302 && str_contains((string) $joinArtistResp['location'], '/artist-dashboard');
            $check('join_artist_redirect', $artistJoinOk, 'status=' . (int) $joinArtistResp['status'] . ' location=' . (string) $joinArtistResp['location']);

            $joinedArtistId = (int) $db->table($usersTable)
                ->where('user_email', $joinArtistEmail)
                ->orderByDesc('ID')
                ->value('ID');
            $check('join_artist_user_created', $joinedArtistId > 0, 'user_id=' . $joinedArtistId);

            $joinedArtistProfileId = (int) $getUserMeta($joinedArtistId, 'gigtune_artist_profile_id');
            $joinedArtistProfileExists = $joinedArtistProfileId > 0
                && $db->table($postsTable)
                    ->where('ID', $joinedArtistProfileId)
                    ->where('post_type', 'artist_profile')
                    ->exists();
            $check('join_artist_profile_created', $joinedArtistProfileExists, 'profile_id=' . $joinedArtistProfileId);

            $requestWithSession('GET', '/logout', [], $joinApiSession);

            $apiClientSession = $joinApiSession;
            $apiLoginResp = $requestWithSession('POST', '/wp-json/gigtune/v1/auth/login', [
                'identifier' => $joinClientEmail,
                'password' => $joinClientPassword,
                'remember' => true,
            ], $apiClientSession);
            $apiLoginPayload = json_decode((string) $apiLoginResp['content'], true);
            $apiLoginOk = (int) $apiLoginResp['status'] === 200
                && is_array($apiLoginPayload)
                && (bool) ($apiLoginPayload['ok'] ?? false);
            $check('api_auth_login_client', $apiLoginOk, 'status=' . (int) $apiLoginResp['status']);

            $apiMeResp = $requestWithSession('GET', '/wp-json/gigtune/v1/auth/me', [], $apiClientSession);
            $apiMePayload = json_decode((string) $apiMeResp['content'], true);
            $apiMeOk = (int) $apiMeResp['status'] === 200
                && is_array($apiMePayload)
                && (bool) ($apiMePayload['ok'] ?? false)
                && (int) ($apiMePayload['user']['id'] ?? 0) === $joinedClientId;
            $check('api_auth_me_client', $apiMeOk, 'status=' . (int) $apiMeResp['status']);

            $apiCreateBookingResp = $requestWithSession('POST', '/wp-json/gigtune/v1/bookings', [
                'artist_profile_id' => $joinedArtistProfileId,
                'event_date' => now()->addDays(7)->format('Y-m-d'),
                'budget' => '1800',
                'notes' => 'Dry-run API booking request',
            ], $apiClientSession);
            $apiCreateBookingPayload = json_decode((string) $apiCreateBookingResp['content'], true);
            $apiBookingId = (int) (is_array($apiCreateBookingPayload) ? ($apiCreateBookingPayload['id'] ?? 0) : 0);
            $apiCreateBookingOk = (int) $apiCreateBookingResp['status'] === 201 && $apiBookingId > 0;
            $check('api_booking_create', $apiCreateBookingOk, 'status=' . (int) $apiCreateBookingResp['status'] . ' booking_id=' . $apiBookingId);

            $check(
                'api_booking_meta_status_requested',
                $apiBookingId > 0 && strtoupper($getPostMeta($apiBookingId, 'gigtune_booking_status')) === 'REQUESTED'
            );
            $check(
                'api_booking_meta_client_linked',
                $apiBookingId > 0 && (int) $getPostMeta($apiBookingId, 'gigtune_booking_client_user_id') === $joinedClientId
            );
            $check(
                'api_booking_meta_artist_linked',
                $apiBookingId > 0 && (int) $getPostMeta($apiBookingId, 'gigtune_booking_artist_profile_id') === $joinedArtistProfileId
            );

            if ($apiBookingId > 0) {
                $apiBookingShowResp = $requestWithSession('GET', '/wp-json/gigtune/v1/bookings/' . $apiBookingId, [], $apiClientSession);
                $apiBookingShowPayload = json_decode((string) $apiBookingShowResp['content'], true);
                $showOk = (int) $apiBookingShowResp['status'] === 200
                    && is_array($apiBookingShowPayload)
                    && (int) ($apiBookingShowPayload['id'] ?? 0) === $apiBookingId;
                $check('api_booking_show', $showOk, 'status=' . (int) $apiBookingShowResp['status']);

                $apiBookingCostsResp = $requestWithSession('POST', '/wp-json/gigtune/v1/bookings/' . $apiBookingId . '/costs', [
                    'gross' => 1800,
                    'fee' => 180,
                    'net' => 1620,
                ], $apiClientSession);
                $apiBookingCostsPayload = json_decode((string) $apiBookingCostsResp['content'], true);
                $check(
                    'api_booking_costs_update',
                    (int) $apiBookingCostsResp['status'] === 200 && is_array($apiBookingCostsPayload) && (bool) ($apiBookingCostsPayload['ok'] ?? false),
                    'status=' . (int) $apiBookingCostsResp['status']
                );

                $apiBookingDistanceResp = $requestWithSession('POST', '/wp-json/gigtune/v1/bookings/' . $apiBookingId . '/distance', [
                    'distance_km' => '23.4',
                ], $apiClientSession);
                $apiBookingDistancePayload = json_decode((string) $apiBookingDistanceResp['content'], true);
                $check(
                    'api_booking_distance_update',
                    (int) $apiBookingDistanceResp['status'] === 200 && is_array($apiBookingDistancePayload) && (bool) ($apiBookingDistancePayload['ok'] ?? false),
                    'status=' . (int) $apiBookingDistanceResp['status']
                );

                $apiBookingAccommodationResp = $requestWithSession('POST', '/wp-json/gigtune/v1/bookings/' . $apiBookingId . '/accommodation', [
                    'accommodation_status' => 'required',
                ], $apiClientSession);
                $apiBookingAccommodationPayload = json_decode((string) $apiBookingAccommodationResp['content'], true);
                $check(
                    'api_booking_accommodation_update',
                    (int) $apiBookingAccommodationResp['status'] === 200 && is_array($apiBookingAccommodationPayload) && (bool) ($apiBookingAccommodationPayload['ok'] ?? false),
                    'status=' . (int) $apiBookingAccommodationResp['status']
                );

                $apiBookingPaymentResp = $requestWithSession('POST', '/wp-json/gigtune/v1/booking/payment-status', [
                    'booking_id' => $apiBookingId,
                    'payment_status' => 'AWAITING_PAYMENT_CONFIRMATION',
                ], $apiClientSession);
                $apiBookingPaymentPayload = json_decode((string) $apiBookingPaymentResp['content'], true);
                $check(
                    'api_booking_payment_status_update',
                    (int) $apiBookingPaymentResp['status'] === 200 && is_array($apiBookingPaymentPayload) && (bool) ($apiBookingPaymentPayload['ok'] ?? false),
                    'status=' . (int) $apiBookingPaymentResp['status']
                );
            }

            $apiBookingsResp = $requestWithSession('GET', '/wp-json/gigtune/v1/bookings', [], $apiClientSession);
            $apiBookingsPayload = json_decode((string) $apiBookingsResp['content'], true);
            $apiBookingsOk = (int) $apiBookingsResp['status'] === 200
                && is_array($apiBookingsPayload)
                && is_array($apiBookingsPayload['items'] ?? null);
            $check('api_bookings_list', $apiBookingsOk, 'status=' . (int) $apiBookingsResp['status']);

            $apiPolicyStatusResp = $requestWithSession('GET', '/wp-json/gigtune/v1/policy/status', [], $apiClientSession);
            $apiPolicyStatusPayload = json_decode((string) $apiPolicyStatusResp['content'], true);
            $check(
                'api_policy_status',
                (int) $apiPolicyStatusResp['status'] === 200 && is_array($apiPolicyStatusPayload),
                'status=' . (int) $apiPolicyStatusResp['status']
            );

            $apiPolicyAcceptResp = $requestWithSession('POST', '/wp-json/gigtune/v1/policy/accept', [
                'accept_all' => true,
            ], $apiClientSession);
            $apiPolicyAcceptPayload = json_decode((string) $apiPolicyAcceptResp['content'], true);
            $check(
                'api_policy_accept',
                (int) $apiPolicyAcceptResp['status'] === 200 && is_array($apiPolicyAcceptPayload) && (bool) ($apiPolicyAcceptPayload['ok'] ?? false),
                'status=' . (int) $apiPolicyAcceptResp['status']
            );

            $apiNotificationsResp = $requestWithSession('GET', '/wp-json/gigtune/v1/notifications', [], $apiClientSession);
            $apiNotificationsPayload = json_decode((string) $apiNotificationsResp['content'], true);
            $check(
                'api_notifications_list',
                (int) $apiNotificationsResp['status'] === 200 && is_array($apiNotificationsPayload),
                'status=' . (int) $apiNotificationsResp['status']
            );

            $notifNow = now();
            $notificationId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => 0,
                'post_date' => $notifNow->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run Notification #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => '',
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $notifNow->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gigtune_notification',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $insertPostMeta($notificationId, 'gigtune_notification_user_id', (string) $joinedClientId);
            $insertPostMeta($notificationId, 'gigtune_notification_recipient_user_id', (string) $joinedClientId);
            $insertPostMeta($notificationId, 'gigtune_notification_is_read', '0');
            $insertPostMeta($notificationId, 'gigtune_notification_is_archived', '0');
            $insertPostMeta($notificationId, 'gigtune_notification_is_deleted', '0');
            $insertPostMeta($notificationId, 'gigtune_notification_title', 'Dry-run notification');
            $insertPostMeta($notificationId, 'gigtune_notification_message', 'Dry-run notification message');

            $uiNotificationsResp = $requestWithSession('GET', '/notifications/', [], $apiClientSession);
            $uiNotificationsOk = (int) $uiNotificationsResp['status'] === 200
                && str_contains((string) $uiNotificationsResp['content'], 'Dry-run notification message');
            $check(
                'ui_notifications_page_lists_items',
                $uiNotificationsOk,
                'status=' . (int) $uiNotificationsResp['status']
            );

            $nonceSeedPrefix = (string) $apiClientSession->token() . '|';
            $nonceSeedSuffix = '|' . (string) config('app.key', '');

            $markAllNonce = hash('sha256', $nonceSeedPrefix . 'gigtune_mark_notifications_read' . $nonceSeedSuffix);
            $uiMarkAllResp = $requestWithSession('POST', '/notifications/', [
                'gigtune_mark_notifications_read' => '1',
                'gigtune_notifications_nonce' => $markAllNonce,
                'gigtune_notifications_redirect' => '/notifications/',
            ], $apiClientSession);
            $check(
                'ui_notifications_mark_all_redirect',
                (int) $uiMarkAllResp['status'] === 302,
                'status=' . (int) $uiMarkAllResp['status']
            );
            $check(
                'ui_notifications_mark_all_meta',
                $getPostMeta($notificationId, 'gigtune_notification_is_read') === '1'
                && $getPostMeta($notificationId, 'gigtune_notification_is_archived') === '1'
            );

            $uiArchiveResp = $requestWithSession('GET', '/notifications-archive/', [], $apiClientSession);
            $uiArchiveOk = (int) $uiArchiveResp['status'] === 200
                && str_contains((string) $uiArchiveResp['content'], 'Dry-run notification message');
            $check(
                'ui_notifications_archive_lists_items',
                $uiArchiveOk,
                'status=' . (int) $uiArchiveResp['status']
            );

            $restoreNonce = hash('sha256', $nonceSeedPrefix . 'gigtune_restore_notification' . $nonceSeedSuffix);
            $uiRestoreResp = $requestWithSession('POST', '/notifications-archive/', [
                'gigtune_restore_notification_submit' => '1',
                'gigtune_restore_notification_id' => (string) $notificationId,
                'gigtune_restore_notification_nonce' => $restoreNonce,
                'gigtune_notifications_redirect' => '/notifications-archive/',
            ], $apiClientSession);
            $check(
                'ui_notifications_restore_redirect',
                (int) $uiRestoreResp['status'] === 302,
                'status=' . (int) $uiRestoreResp['status']
            );
            $check('ui_notifications_restore_meta', $getPostMeta($notificationId, 'gigtune_notification_is_archived') === '0');

            $settingsNonce = hash('sha256', $nonceSeedPrefix . 'gigtune_notification_settings_action' . $nonceSeedSuffix);
            $uiSettingsResp = $requestWithSession('POST', '/notification-settings/', [
                'gigtune_notification_settings_submit' => '1',
                'gigtune_notification_settings_nonce' => $settingsNonce,
                'gigtune_notify_booking' => '1',
                'gigtune_notify_psa' => '1',
                'gigtune_notify_message' => '1',
                'gigtune_notify_payment' => '1',
                'gigtune_notify_dispute' => '1',
                'gigtune_notify_security' => '1',
            ], $apiClientSession);
            $settingsRedirectOk = (int) $uiSettingsResp['status'] === 302
                && str_contains((string) $uiSettingsResp['location'], 'notification_settings_saved=1');
            $check(
                'ui_notification_settings_save_redirect',
                $settingsRedirectOk,
                'status=' . (int) $uiSettingsResp['status'] . ' location=' . (string) $uiSettingsResp['location']
            );
            $prefsRaw = (string) $getUserMeta($joinedClientId, 'gigtune_notification_email_preferences');
            $prefs = @unserialize($prefsRaw);
            $prefsOk = is_array($prefs)
                && !empty($prefs['booking'])
                && !empty($prefs['psa'])
                && !empty($prefs['message'])
                && !empty($prefs['payment'])
                && !empty($prefs['dispute'])
                && !empty($prefs['security']);
            $check('ui_notification_settings_saved_meta', $prefsOk);

            $apiNotifReadResp = $requestWithSession('POST', '/wp-json/gigtune/v1/notifications/' . $notificationId . '/read', [], $apiClientSession);
            $apiNotifReadPayload = json_decode((string) $apiNotifReadResp['content'], true);
            $check(
                'api_notifications_read',
                (int) $apiNotifReadResp['status'] === 200 && is_array($apiNotifReadPayload),
                'status=' . (int) $apiNotifReadResp['status']
            );
            $check('api_notifications_read_meta', $getPostMeta($notificationId, 'gigtune_notification_is_read') === '1');

            $apiNotifArchiveResp = $requestWithSession('POST', '/wp-json/gigtune/v1/notifications/' . $notificationId . '/archive', [], $apiClientSession);
            $apiNotifArchivePayload = json_decode((string) $apiNotifArchiveResp['content'], true);
            $check(
                'api_notifications_archive',
                (int) $apiNotifArchiveResp['status'] === 200 && is_array($apiNotifArchivePayload),
                'status=' . (int) $apiNotifArchiveResp['status']
            );
            $check('api_notifications_archive_meta', $getPostMeta($notificationId, 'gigtune_notification_is_archived') === '1');

            $apiNotifUnarchiveResp = $requestWithSession('POST', '/wp-json/gigtune/v1/notifications/' . $notificationId . '/unarchive', [], $apiClientSession);
            $apiNotifUnarchivePayload = json_decode((string) $apiNotifUnarchiveResp['content'], true);
            $check(
                'api_notifications_unarchive',
                (int) $apiNotifUnarchiveResp['status'] === 200 && is_array($apiNotifUnarchivePayload),
                'status=' . (int) $apiNotifUnarchiveResp['status']
            );
            $check('api_notifications_unarchive_meta', $getPostMeta($notificationId, 'gigtune_notification_is_archived') === '0');

            $apiNotifDeleteResp = $requestWithSession('POST', '/wp-json/gigtune/v1/notifications/' . $notificationId . '/delete', [], $apiClientSession);
            $apiNotifDeletePayload = json_decode((string) $apiNotifDeleteResp['content'], true);
            $check(
                'api_notifications_delete',
                (int) $apiNotifDeleteResp['status'] === 200 && is_array($apiNotifDeletePayload),
                'status=' . (int) $apiNotifDeleteResp['status']
            );
            $check('api_notifications_delete_meta', $getPostMeta($notificationId, 'gigtune_notification_is_deleted') === '1');

            $session->put('gigtune_auth_user_id', $adminId);
            $session->put('gigtune_auth_logged_in_at', now()->toIso8601String());
            $session->put('gigtune_auth_remember', true);

            $gtsUserLogin = 'dryrun_gts_' . $cycle . '_' . random_int(1000, 9999);
            $gtsUserEmail = $gtsUserLogin . '.' . random_int(1000, 9999) . '@local.test';
            $gtsCreateResp = $post('/gts-admin-users/users', [
                'login' => $gtsUserLogin,
                'email' => $gtsUserEmail,
                'password' => 'DryRun#GTS' . $cycle . 'Z9!',
                'display_name' => 'Dry Run GTS ' . $cycle,
                'roles' => ['gigtune_client'],
            ]);
            $check('gts_user_create_redirect', $gtsCreateResp['status'] === 302, 'status=' . $gtsCreateResp['status']);

            $gtsCreatedUserId = (int) $db->table($usersTable)
                ->where('user_email', $gtsUserEmail)
                ->value('ID');
            $check('gts_user_create_effective', $gtsCreatedUserId > 0, 'user_id=' . $gtsCreatedUserId);

            if ($gtsCreatedUserId > 0) {
                $gtsRoleResp = $post('/gts-admin-users/users/' . $gtsCreatedUserId . '/roles', [
                    'roles' => ['gigtune_artist'],
                ]);
                $check('gts_user_role_update_redirect', $gtsRoleResp['status'] === 302, 'status=' . $gtsRoleResp['status']);

                $capabilities = $getUserMeta($gtsCreatedUserId, $prefix . 'capabilities');
                $capabilityOk = str_contains(strtolower($capabilities), 'gigtune_artist');
                $check('gts_user_role_update_effective', $capabilityOk);

                $gtsDeleteResp = $post('/gts-admin-users/users/' . $gtsCreatedUserId . '/delete', []);
                $check('gts_user_delete_redirect', $gtsDeleteResp['status'] === 302, 'status=' . $gtsDeleteResp['status']);
                $check(
                    'gts_user_delete_effective',
                    !$db->table($usersTable)->where('ID', $gtsCreatedUserId)->exists(),
                    'user_id=' . $gtsCreatedUserId
                );
            }

            $publishedPageSlugs = $db->table($postsTable)
                ->where('post_type', 'page')
                ->where('post_status', 'publish')
                ->orderByDesc('ID')
                ->limit(80)
                ->pluck('post_name')
                ->map(static fn ($slug): string => trim((string) $slug))
                ->filter(static fn (string $slug): bool => $slug !== '')
                ->unique()
                ->values()
                ->all();
            $pageSmokeOk = true;
            $pageSmokeDetail = 'ok';
            foreach ($publishedPageSlugs as $slug) {
                $path = '/' . trim((string) $slug, '/') . '/';
                $pageResp = $requestWithSession('GET', $path, [], $session);
                if ((int) $pageResp['status'] >= 500) {
                    $pageSmokeOk = false;
                    $pageSmokeDetail = 'path=' . $path . ' status=' . (int) $pageResp['status'];
                    break;
                }
            }
            $check('published_pages_no_500', $pageSmokeOk, $pageSmokeDetail);

            // Restore admin-authenticated session state before protected admin checks.
            $session->put('gigtune_auth_user_id', $adminId);
            $session->put('gigtune_auth_logged_in_at', now()->toIso8601String());
            $session->put('gigtune_auth_remember', true);

            foreach ([
                '/admin-dashboard',
                '/admin-dashboard/users',
                '/admin-dashboard/users?user_scope=artists',
                '/admin-dashboard/users?user_scope=clients',
                '/admin-dashboard/users?user_scope=artists&user_id=' . $adminId,
                '/admin-dashboard/payments',
                '/admin-dashboard/payouts',
                '/admin-dashboard/bookings',
                '/admin-dashboard/disputes',
                '/admin-dashboard/refunds',
                '/admin-dashboard/kyc',
            ] as $path) {
                $resp = $requestWithSession('GET', $path, [], $session);
                $check('get_' . ltrim(str_replace('/', '_', $path), '_'), $resp['status'] === 200, 'status=' . $resp['status']);
            }

            $respHome = $requestWithSession('GET', '/', [], $session);
            $homeAccessOk = $respHome['status'] === 200
                || ($respHome['status'] === 302 && str_contains($respHome['location'], '/admin-dashboard'));
            $check('admin_home_access', $homeAccessOk, 'status=' . $respHome['status'] . ' location=' . $respHome['location']);

            $respArtists = $requestWithSession('GET', '/artists/', [], $session);
            $hasArtistProfileLink = preg_match('/artist-profile\\/\\?artist_id=/', $respArtists['content']) === 1;
            $check('artists_has_profile_links', $respArtists['status'] === 200 && $hasArtistProfileLink, 'status=' . $respArtists['status']);

            $respProfile = $requestWithSession('GET', '/artist-profile/?artist_id=' . $artistProfileId, [], $session);
            $check('artist_profile_page_ok', $respProfile['status'] === 200, 'status=' . $respProfile['status']);

            $respProfileEmpty = $requestWithSession('GET', '/artist-profile/?artist_id=', [], $session);
            $profileEmptyOk = $respProfileEmpty['status'] === 200
                && !str_contains(strtolower((string) $respProfileEmpty['content']), 'error establishing a database connection');
            $check('artist_profile_empty_id_ok', $profileEmptyOk, 'status=' . $respProfileEmpty['status']);

            $artistIdsForStress = $db->table($postsTable)
                ->where('post_type', 'artist_profile')
                ->where('post_status', 'publish')
                ->orderByDesc('ID')
                ->limit(10)
                ->pluck('ID')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            if (empty($artistIdsForStress)) {
                $artistIdsForStress = [$artistProfileId];
            }

            $artistStressOk = true;
            $artistStressDetail = 'ok';
            for ($repeat = 1; $repeat <= 3; $repeat++) {
                $artistsLoopResp = $requestWithSession('GET', '/artists/', [], $session);
                if ((int) $artistsLoopResp['status'] >= 500) {
                    $artistStressOk = false;
                    $artistStressDetail = 'artists_status=' . (int) $artistsLoopResp['status'] . ' repeat=' . $repeat;
                    break;
                }

                foreach ($artistIdsForStress as $stressArtistId) {
                    $profileLoopResp = $requestWithSession('GET', '/artist-profile/?artist_id=' . (int) $stressArtistId, [], $session);
                    if ((int) $profileLoopResp['status'] >= 500) {
                        $artistStressOk = false;
                        $artistStressDetail = 'profile_status=' . (int) $profileLoopResp['status'] . ' artist_id=' . (int) $stressArtistId . ' repeat=' . $repeat;
                        break 2;
                    }
                }
            }
            $check('artist_profile_stress_paths', $artistStressOk, $artistStressDetail);

            $respPayConfirm = $post('/admin-dashboard/payments/review', [
                'booking_id' => $bookingId,
                'decision' => 'confirm',
                'note' => 'Dry-run confirm',
            ]);
            $check('payment_confirm_redirect', $respPayConfirm['status'] === 302, 'status=' . $respPayConfirm['status']);
            $check('payment_confirm_meta', $getPostMeta($bookingId, 'gigtune_payment_status') === 'CONFIRMED_HELD_PENDING_COMPLETION');

            $respPayReject = $post('/admin-dashboard/payments/review', [
                'booking_id' => $bookingId,
                'decision' => 'reject',
                'note' => 'Dry-run reject',
            ]);
            $check('payment_reject_redirect', $respPayReject['status'] === 302, 'status=' . $respPayReject['status']);
            $check('payment_reject_meta', $getPostMeta($bookingId, 'gigtune_payment_status') === 'REJECTED_PAYMENT');

            $respPayoutPaid = $post('/admin-dashboard/payouts/review', [
                'booking_id' => $bookingId,
                'decision' => 'paid',
                'reference' => 'PAYOUT-PAID-' . $cycle,
                'note' => 'Dry-run payout paid',
            ]);
            $check('payout_paid_redirect', $respPayoutPaid['status'] === 302, 'status=' . $respPayoutPaid['status']);
            $check('payout_paid_meta', $getPostMeta($bookingId, 'gigtune_payout_status') === 'PAID');

            $respPayoutFailed = $post('/admin-dashboard/payouts/review', [
                'booking_id' => $bookingId,
                'decision' => 'failed',
                'reference' => 'PAYOUT-FAILED-' . $cycle,
                'note' => 'Dry-run payout failed',
            ]);
            $check('payout_failed_redirect', $respPayoutFailed['status'] === 302, 'status=' . $respPayoutFailed['status']);
            $check('payout_failed_meta', $getPostMeta($bookingId, 'gigtune_payout_status') === 'FAILED');

            $respRefundRequest = $post('/admin-dashboard/bookings/request-refund', [
                'booking_id' => $bookingId,
                'note' => 'Dry-run refund request',
            ]);
            $check('booking_refund_request_redirect', $respRefundRequest['status'] === 302, 'status=' . $respRefundRequest['status']);
            $check('booking_refund_requested_meta', $getPostMeta($bookingId, 'gigtune_refund_status') === 'REQUESTED');

            $respRefundPending = $post('/admin-dashboard/refunds/review', [
                'booking_id' => $bookingId,
                'decision' => 'pending',
                'note' => 'Dry-run refund approved',
            ]);
            $check('refund_pending_redirect', $respRefundPending['status'] === 302, 'status=' . $respRefundPending['status']);
            $check('refund_pending_meta', $getPostMeta($bookingId, 'gigtune_refund_status') === 'PENDING');

            $respRefundReject = $post('/admin-dashboard/refunds/review', [
                'booking_id' => $bookingId,
                'decision' => 'reject',
                'note' => 'Dry-run refund rejected',
            ]);
            $check('refund_reject_redirect', $respRefundReject['status'] === 302, 'status=' . $respRefundReject['status']);
            $check('refund_reject_meta', $getPostMeta($bookingId, 'gigtune_refund_status') === 'REJECTED');

            $respRefundComplete = $post('/admin-dashboard/refunds/review', [
                'booking_id' => $bookingId,
                'decision' => 'completed',
                'note' => 'Dry-run refund completed',
            ]);
            $check('refund_complete_redirect', $respRefundComplete['status'] === 302, 'status=' . $respRefundComplete['status']);
            $check('refund_complete_meta', $getPostMeta($bookingId, 'gigtune_refund_status') === 'SUCCEEDED');

            $respDisputeResolve = $post('/admin-dashboard/disputes/review', [
                'dispute_id' => $disputeId,
                'decision' => 'resolve',
                'note' => 'Dry-run dispute resolve',
                'mark_booking_completed' => '1',
            ]);
            $check('dispute_resolve_redirect', $respDisputeResolve['status'] === 302, 'status=' . $respDisputeResolve['status']);
            $check('dispute_resolve_meta', $getPostMeta($disputeId, 'gigtune_dispute_status') === 'RESOLVED');

            $respDisputeReject = $post('/admin-dashboard/disputes/review', [
                'dispute_id' => $disputeId,
                'decision' => 'reject',
                'note' => 'Dry-run dispute reject',
            ]);
            $check('dispute_reject_redirect', $respDisputeReject['status'] === 302, 'status=' . $respDisputeReject['status']);
            $check('dispute_reject_meta', $getPostMeta($disputeId, 'gigtune_dispute_status') === 'REJECTED');

            $kycNow = now();
            $kycSubmissionId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => $adminId,
                'post_date' => $kycNow->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run KYC Submission #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => 'dry-run-kyc-' . $cycle,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $kycNow->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gigtune_kyc_submission',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $insertPostMeta($kycSubmissionId, 'gigtune_kyc_user_id', (string) $joinedClientId);
            $insertPostMeta($kycSubmissionId, 'gigtune_kyc_decision', 'pending');
            $check('fixture_kyc_submission_created', $kycSubmissionId > 0, 'submission_id=' . $kycSubmissionId);

            $respKycReview = $post('/admin-dashboard/kyc/review', [
                'submission_id' => $kycSubmissionId,
                'target_user_id' => $joinedClientId,
                'decision' => 'approve',
                'decision_notes' => 'Dry-run KYC approved',
                'review_reason' => 'Dry-run review',
            ]);
            $check('kyc_review_redirect', $respKycReview['status'] === 302, 'status=' . $respKycReview['status']);
            $check('kyc_user_status_verified', $getUserMeta($joinedClientId, 'gigtune_kyc_status') === 'verified');

            $kycNotificationExists = $db->table($postsTable . ' as p')
                ->where('p.post_type', 'gigtune_notification')
                ->whereExists(function ($query) use ($postmetaTable, $kycSubmissionId): void {
                    $query->selectRaw('1')
                        ->from($postmetaTable . ' as pm')
                        ->whereColumn('pm.post_id', 'p.ID')
                        ->where('pm.meta_key', 'kyc_submission_id')
                        ->where('pm.meta_value', (string) $kycSubmissionId);
                })
                ->exists();
            $check('kyc_notification_created', $kycNotificationExists, 'submission_id=' . $kycSubmissionId);

            $orphanKycSubmissionId = (int) $db->table($postsTable)->insertGetId([
                'post_author' => $adminId,
                'post_date' => $kycNow->format('Y-m-d H:i:s'),
                'post_date_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content' => '',
                'post_title' => 'Dry-run Orphan KYC Submission #' . $cycle,
                'post_excerpt' => '',
                'post_status' => 'publish',
                'comment_status' => 'closed',
                'ping_status' => 'closed',
                'post_password' => '',
                'post_name' => 'dry-run-orphan-kyc-' . $cycle,
                'to_ping' => '',
                'pinged' => '',
                'post_modified' => $kycNow->format('Y-m-d H:i:s'),
                'post_modified_gmt' => now('UTC')->format('Y-m-d H:i:s'),
                'post_content_filtered' => '',
                'post_parent' => 0,
                'guid' => '',
                'menu_order' => 0,
                'post_type' => 'gigtune_kyc_submission',
                'post_mime_type' => '',
                'comment_count' => 0,
            ]);
            $insertPostMeta($orphanKycSubmissionId, 'gigtune_kyc_user_id', '999999999');
            $insertPostMeta($orphanKycSubmissionId, 'gigtune_kyc_decision', 'pending');
            $check(
                'fixture_orphan_kyc_submission_created',
                $orphanKycSubmissionId > 0,
                'submission_id=' . $orphanKycSubmissionId
            );

            $respKycPurgeDeleted = $post('/admin-dashboard/kyc/purge-deleted', [
                'submission_id' => $orphanKycSubmissionId,
            ]);
            $check(
                'kyc_purge_deleted_redirect',
                $respKycPurgeDeleted['status'] === 302,
                'status=' . $respKycPurgeDeleted['status']
            );
            $check(
                'kyc_purge_deleted_effective',
                !$db->table($postsTable)->where('ID', $orphanKycSubmissionId)->exists(),
                'submission_id=' . $orphanKycSubmissionId
            );

            $respUserDelete = $post('/admin-dashboard/users/hard-delete', [
                'user_id' => (string) $dryRunUserId,
            ]);
            $check('admin_user_hard_delete_redirect', $respUserDelete['status'] === 302, 'status=' . $respUserDelete['status']);
            $check(
                'admin_user_hard_delete_effective',
                !$db->table($usersTable)->where('ID', $dryRunUserId)->exists(),
                'user_id=' . $dryRunUserId
            );
        } catch (\Throwable $throwable) {
            $check('cycle_exception', false, $throwable->getMessage());
        } finally {
            try {
                $db->rollBack();
            } catch (\Throwable) {
            }
        }

        $results[] = [
            'cycle' => $cycle,
            'pass' => $cyclePass,
            'checks' => $checks,
        ];
        $this->line('Cycle ' . $cycle . ': ' . ($cyclePass ? 'PASS' : 'FAIL'));
    }

    $passed = count(array_filter($results, static fn (array $row): bool => (bool) ($row['pass'] ?? false)));
    $failed = count($results) - $passed;

    $summary = [
        'cycles' => $cycles,
        'passed_cycles' => $passed,
        'failed_cycles' => $failed,
        'results' => $results,
    ];

    $this->newLine();
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $failed === 0 ? 0 : 1;
})->purpose('Run dry-run end-to-end GigTune admin/frontend flow simulation with transaction rollback');

Artisan::command('gigtune:deep-audit {--max-pages=0} {--max-artists=0}', function () {
    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $postsTable = $prefix . 'posts';
    $usersTable = $prefix . 'users';
    $usermetaTable = $prefix . 'usermeta';
    $db = DB::connection($connection);

    $maxPages = max(0, (int) $this->option('max-pages'));
    $maxArtists = max(0, (int) $this->option('max-artists'));

    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $requestWithSession = function (string $method, string $uri, array $params, \Illuminate\Contracts\Session\Session $session) use ($kernel): array {
        $request = \Illuminate\Http\Request::create($uri, strtoupper($method), $params, [], [], [
            'HTTP_HOST' => '127.0.0.1:8002',
            'SERVER_PORT' => 8002,
            'REQUEST_SCHEME' => 'http',
            'HTTPS' => 'off',
        ]);
        $request->setLaravelSession($session);

        $response = $kernel->handle($request);
        $result = [
            'status' => (int) $response->getStatusCode(),
            'location' => (string) $response->headers->get('Location', ''),
            'content' => (string) $response->getContent(),
        ];
        $kernel->terminate($request, $response);
        return $result;
    };

    $failures = [];
    $check = function (string $name, bool $pass, string $detail = '') use (&$failures): void {
        if (!$pass) {
            $failures[] = ['name' => $name, 'detail' => $detail];
        }
    };

    $guestSession = app('session')->driver('array');
    $guestSession->start();

    $adminId = (int) $db->table($usersTable . ' as u')
        ->select('u.ID')
        ->whereExists(function ($query) use ($usermetaTable, $prefix): void {
            $query->selectRaw('1')
                ->from($usermetaTable . ' as um')
                ->whereColumn('um.user_id', 'u.ID')
                ->whereIn('um.meta_key', [$prefix . 'capabilities', 'capabilities'])
                ->where('um.meta_value', 'like', '%administrator%');
        })
        ->orderByDesc('u.ID')
        ->value('u.ID');

    if ($adminId <= 0) {
        $this->error('No administrator user found.');
        return 1;
    }

    $adminSession = app('session')->driver();
    $adminSession->start();
    $adminSession->put('gigtune_auth_user_id', $adminId);
    $adminSession->put('gigtune_auth_logged_in_at', now()->toIso8601String());
    $adminSession->put('gigtune_auth_remember', true);

    $pageQuery = $db->table($postsTable)
        ->where('post_type', 'page')
        ->where('post_status', 'publish')
        ->orderByDesc('ID');
    if ($maxPages > 0) {
        $pageQuery->limit($maxPages);
    }
    $pageSlugs = $pageQuery
        ->pluck('post_name')
        ->map(static fn ($slug): string => trim((string) $slug))
        ->filter(static fn (string $slug): bool => $slug !== '')
        ->unique()
        ->values()
        ->all();

    $this->info('Checking published pages: ' . count($pageSlugs));
    foreach ($pageSlugs as $slug) {
        $path = '/' . trim($slug, '/') . '/';
        $resp = $requestWithSession('GET', $path, [], $guestSession);
        $dbError = str_contains($resp['content'], 'Error establishing a database connection');
        $check('page:' . $path, $resp['status'] < 500 && !$dbError, 'status=' . $resp['status']);
    }

    $artistQuery = $db->table($postsTable)
        ->select(['ID', 'post_name'])
        ->where('post_type', 'artist_profile')
        ->where('post_status', 'publish')
        ->orderByDesc('ID');
    if ($maxArtists > 0) {
        $artistQuery->limit($maxArtists);
    }
    $artistRows = $artistQuery->get();

    $this->info('Checking artist profiles: ' . $artistRows->count());
    foreach ($artistRows as $artistRow) {
        $artistId = (int) ($artistRow->ID ?? 0);
        if ($artistId <= 0) {
            continue;
        }

        $respById = $requestWithSession('GET', '/artist-profile/?artist_id=' . $artistId, [], $guestSession);
        $dbErrorById = str_contains($respById['content'], 'Error establishing a database connection');
        $check('artist_id:' . $artistId, $respById['status'] < 500 && !$dbErrorById, 'status=' . $respById['status']);

        $artistSlug = trim((string) ($artistRow->post_name ?? ''));
        if ($artistSlug !== '') {
            $respBySlug = $requestWithSession('GET', '/artist-profile/?artist_slug=' . rawurlencode($artistSlug), [], $guestSession);
            $dbErrorBySlug = str_contains($respBySlug['content'], 'Error establishing a database connection');
            $check('artist_slug:' . $artistSlug, $respBySlug['status'] < 500 && !$dbErrorBySlug, 'status=' . $respBySlug['status']);
        }
    }

    foreach ([
        '/',
        '/artists/',
        '/secret-admin-login-security',
        '/join/',
        '/sign-in/',
    ] as $publicPath) {
        $resp = $requestWithSession('GET', $publicPath, [], $guestSession);
        $check('public:' . $publicPath, $resp['status'] < 500, 'status=' . $resp['status']);
    }

    foreach ([
        '/admin-dashboard',
        '/admin-dashboard/overview',
        '/admin-dashboard/users',
        '/admin-dashboard/payments',
        '/admin-dashboard/payouts',
        '/admin-dashboard/bookings',
        '/admin-dashboard/disputes',
        '/admin-dashboard/refunds',
        '/admin-dashboard/kyc',
        '/gts-admin-users',
    ] as $adminPath) {
        $resp = $requestWithSession('GET', $adminPath, [], $adminSession);
        $check(
            'admin:' . $adminPath,
            in_array((int) $resp['status'], [200, 302], true),
            'status=' . $resp['status'] . ' location=' . $resp['location']
        );
    }

    $summary = [
        'passed' => count($failures) === 0,
        'failure_count' => count($failures),
        'failures' => $failures,
        'scanned' => [
            'pages' => count($pageSlugs),
            'artists' => $artistRows->count(),
        ],
    ];
    $this->newLine();
    $this->line(json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $summary['passed'] ? 0 : 1;
})->purpose('Run deep page and artist profile endpoint audit against live dataset');

Artisan::command('gigtune:link-audit {--max-pages=0} {--max-links=0}', function () {
    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $postsTable = $prefix . 'posts';
    $usersTable = $prefix . 'users';
    $usermetaTable = $prefix . 'usermeta';
    $db = DB::connection($connection);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

    $maxPages = max(0, (int) $this->option('max-pages'));
    $maxLinks = max(0, (int) $this->option('max-links'));

    $requestWithSession = function (string $method, string $uri, array $params, \Illuminate\Contracts\Session\Session $session) use ($kernel): array {
        $request = \Illuminate\Http\Request::create($uri, strtoupper($method), $params, [], [], [
            'HTTP_HOST' => '127.0.0.1:8002',
            'SERVER_PORT' => 8002,
            'REQUEST_SCHEME' => 'http',
            'HTTPS' => 'off',
        ]);
        $request->setLaravelSession($session);

        $response = $kernel->handle($request);
        $result = [
            'status' => (int) $response->getStatusCode(),
            'location' => (string) $response->headers->get('Location', ''),
            'content' => (string) $response->getContent(),
        ];
        $kernel->terminate($request, $response);
        return $result;
    };

    $resolveUserByRole = function (string $needle) use ($db, $usersTable, $usermetaTable, $prefix): int {
        return (int) $db->table($usersTable . ' as u')
            ->select('u.ID')
            ->whereExists(function ($query) use ($usermetaTable, $prefix, $needle): void {
                $query->selectRaw('1')
                    ->from($usermetaTable . ' as um')
                    ->whereColumn('um.user_id', 'u.ID')
                    ->whereIn('um.meta_key', [$prefix . 'capabilities', 'capabilities'])
                    ->where('um.meta_value', 'like', '%' . $needle . '%');
            })
            ->orderByDesc('u.ID')
            ->value('u.ID');
    };

    $sessionForUser = function (int $userId): \Illuminate\Contracts\Session\Session {
        $session = app('session')->driver();
        $session->start();
        $session->put('gigtune_auth_user_id', $userId);
        $session->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $session->put('gigtune_auth_remember', true);
        return $session;
    };

    $normalizePath = function (string $raw): ?string {
        $value = trim(html_entity_decode($raw));
        if ($value === '' || $value === '#' || str_starts_with($value, '//')) {
            return null;
        }
        $lower = strtolower($value);
        foreach (['javascript:', 'mailto:', 'tel:', 'data:'] as $prefixValue) {
            if (str_starts_with($lower, $prefixValue)) {
                return null;
            }
        }

        $parts = parse_url($value);
        if ($parts === false) {
            return null;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host !== '' && !in_array($host, ['127.0.0.1', 'localhost', 'gigtune.africa', 'www.gigtune.africa'], true)) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        if (!str_starts_with($path, '/')) {
            $path = '/' . $path;
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($ext !== '' && in_array($ext, ['css', 'js', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'map', 'pdf', 'txt', 'xml', 'webmanifest'], true)) {
            return null;
        }
        if (str_starts_with($path, '/wp-content/') || str_starts_with($path, '/storage/')) {
            return null;
        }

        $query = trim((string) ($parts['query'] ?? ''));
        return $query !== '' ? ($path . '?' . $query) : $path;
    };

    $extractTargets = function (string $html): array {
        if ($html === '') {
            return [];
        }
        preg_match_all('/\\b(?:href|action)\\s*=\\s*["\\\']([^"\\\']+)["\\\']/i', $html, $matches);
        $items = $matches[1] ?? [];
        return array_values(array_unique(array_map(static fn ($v): string => trim((string) $v), $items)));
    };

    $pageQuery = $db->table($postsTable)
        ->where('post_type', 'page')
        ->where('post_status', 'publish')
        ->orderByDesc('ID');
    if ($maxPages > 0) {
        $pageQuery->limit($maxPages);
    }
    $pagePaths = $pageQuery->pluck('post_name')
        ->map(static fn ($slug): string => trim((string) $slug))
        ->filter(static fn (string $slug): bool => $slug !== '')
        ->unique()
        ->values()
        ->map(static fn (string $slug): string => '/' . trim($slug, '/') . '/')
        ->all();

    $adminId = $resolveUserByRole('administrator');
    $artistId = $resolveUserByRole('gigtune_artist');
    $clientId = $resolveUserByRole('gigtune_client');

    if ($adminId <= 0 || $artistId <= 0 || $clientId <= 0) {
        $this->error('Could not resolve admin/artist/client users for link audit.');
        return 1;
    }

    $guestSession = app('session')->driver('array');
    $guestSession->start();

    $contexts = [
        'guest' => [
            'session' => $guestSession,
            'seed' => ['/', '/join/', '/sign-in/', '/artists/', '/artist-profile/'],
        ],
        'admin' => [
            'session' => $sessionForUser($adminId),
            'seed' => ['/', '/admin-dashboard/', '/admin-dashboard/users', '/admin-dashboard/payments', '/admin-dashboard/bookings', '/admin-dashboard/kyc', '/gts-admin-users'],
        ],
        'artist' => [
            'session' => $sessionForUser($artistId),
            'seed' => ['/', '/artist-dashboard/', '/artist-profile-edit/', '/artist-availability/', '/messages/', '/notifications/', '/kyc/', '/kyc-status/'],
        ],
        'client' => [
            'session' => $sessionForUser($clientId),
            'seed' => ['/', '/client-dashboard/', '/book-an-artist/', '/messages/', '/notifications/', '/my-account-page/'],
        ],
    ];

    $allFailures = [];
    $summary = [];

    foreach ($contexts as $name => $ctx) {
        /** @var \Illuminate\Contracts\Session\Session $session */
        $session = $ctx['session'];
        $seedPaths = array_values(array_unique(array_merge($ctx['seed'], $pagePaths)));
        $seedPaths = array_values(array_filter($seedPaths, static fn ($p): bool => is_string($p) && $p !== ''));

        $foundTargets = [];
        $seedChecks = 0;
        $seedFailures = [];
        foreach ($seedPaths as $path) {
            $seedChecks++;
            $resp = $requestWithSession('GET', $path, [], $session);
            $hasDbError = str_contains($resp['content'], 'Error establishing a database connection');
            $hasRawShortcode = preg_match('/\\[gigtune_[^\\]]+\\]/i', $resp['content']) === 1;
            $hasWpAdminRef = str_contains($resp['content'], '/wp-admin/');
            if ($resp['status'] >= 500 || $hasDbError || $hasRawShortcode || $hasWpAdminRef) {
                $seedFailures[] = [
                    'path' => $path,
                    'status' => $resp['status'],
                    'db_error' => $hasDbError,
                    'raw_shortcode' => $hasRawShortcode,
                    'wp_admin_ref' => $hasWpAdminRef,
                ];
            }

            foreach ($extractTargets($resp['content']) as $target) {
                $normalized = $normalizePath($target);
                if ($normalized !== null && $normalized !== '') {
                    $foundTargets[$normalized] = true;
                }
            }
        }

        $targetPaths = array_keys($foundTargets);
        sort($targetPaths);
        if ($maxLinks > 0 && count($targetPaths) > $maxLinks) {
            $targetPaths = array_slice($targetPaths, 0, $maxLinks);
        }

        $targetChecks = 0;
        $targetFailures = [];
        foreach ($targetPaths as $targetPath) {
            $targetChecks++;
            $resp = $requestWithSession('GET', $targetPath, [], $session);
            $okStatus = in_array($resp['status'], [200, 201, 202, 204, 301, 302, 303, 307, 308, 401, 403], true);
            $hasDbError = str_contains($resp['content'], 'Error establishing a database connection');
            if (!$okStatus || $hasDbError) {
                $targetFailures[] = [
                    'path' => $targetPath,
                    'status' => $resp['status'],
                    'location' => $resp['location'],
                    'db_error' => $hasDbError,
                ];
            }
        }

        $contextFailures = [
            'seed_failures' => $seedFailures,
            'target_failures' => $targetFailures,
        ];
        $summary[$name] = [
            'seed_paths_checked' => $seedChecks,
            'targets_discovered' => count($foundTargets),
            'targets_checked' => $targetChecks,
            'seed_failures' => count($seedFailures),
            'target_failures' => count($targetFailures),
        ];

        if (!empty($seedFailures) || !empty($targetFailures)) {
            $allFailures[$name] = $contextFailures;
        }
    }

    $result = [
        'passed' => empty($allFailures),
        'summary' => $summary,
        'failures' => $allFailures,
    ];

    $this->newLine();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return empty($allFailures) ? 0 : 1;
})->purpose('Crawl role-based page links/actions and flag broken internal endpoints');

Artisan::command('gigtune:ui-audit {--max-pages=0} {--json=}', function () {
    $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
    $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
    $postsTable = $prefix . 'posts';
    $usersTable = $prefix . 'users';
    $usermetaTable = $prefix . 'usermeta';
    $db = DB::connection($connection);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);

    $maxPages = max(0, (int) $this->option('max-pages'));

    $requestWithSession = function (string $method, string $uri, array $params, \Illuminate\Contracts\Session\Session $session) use ($kernel): array {
        $request = \Illuminate\Http\Request::create($uri, strtoupper($method), $params, [], [], [
            'HTTP_HOST' => '127.0.0.1:8002',
            'SERVER_PORT' => 8002,
            'REQUEST_SCHEME' => 'http',
            'HTTPS' => 'off',
        ]);
        $request->setLaravelSession($session);

        $response = $kernel->handle($request);
        $result = [
            'status' => (int) $response->getStatusCode(),
            'location' => (string) $response->headers->get('Location', ''),
            'content' => (string) $response->getContent(),
        ];
        $kernel->terminate($request, $response);
        return $result;
    };

    $resolveUserByRole = function (string $needle) use ($db, $usersTable, $usermetaTable, $prefix): int {
        return (int) $db->table($usersTable . ' as u')
            ->select('u.ID')
            ->whereExists(function ($query) use ($usermetaTable, $prefix, $needle): void {
                $query->selectRaw('1')
                    ->from($usermetaTable . ' as um')
                    ->whereColumn('um.user_id', 'u.ID')
                    ->whereIn('um.meta_key', [$prefix . 'capabilities', 'capabilities'])
                    ->where('um.meta_value', 'like', '%' . $needle . '%');
            })
            ->orderByDesc('u.ID')
            ->value('u.ID');
    };

    $sessionForUser = function (int $userId): \Illuminate\Contracts\Session\Session {
        $session = app('session')->driver();
        $session->start();
        $session->put('gigtune_auth_user_id', $userId);
        $session->put('gigtune_auth_logged_in_at', now()->toIso8601String());
        $session->put('gigtune_auth_remember', true);
        return $session;
    };

    $guestSession = app('session')->driver('array');
    $guestSession->start();

    $adminId = $resolveUserByRole('administrator');
    $artistId = $resolveUserByRole('gigtune_artist');
    $clientId = $resolveUserByRole('gigtune_client');
    if ($adminId <= 0 || $artistId <= 0 || $clientId <= 0) {
        $this->error('Could not resolve admin/artist/client users for UI audit.');
        return 1;
    }

    $adminSession = $sessionForUser($adminId);
    $artistSession = $sessionForUser($artistId);
    $clientSession = $sessionForUser($clientId);

    $pageQuery = $db->table($postsTable)
        ->where('post_type', 'page')
        ->where('post_status', 'publish')
        ->orderByDesc('ID');
    if ($maxPages > 0) {
        $pageQuery->limit($maxPages);
    }
    $pagePaths = $pageQuery->pluck('post_name')
        ->map(static fn ($slug): string => '/' . trim((string) $slug, '/') . '/')
        ->filter(static fn (string $path): bool => $path !== '//' && $path !== '/')
        ->unique()
        ->values()
        ->all();

    $failures = [];
    $check = function (string $name, bool $pass, string $detail = '') use (&$failures): void {
        if (!$pass) {
            $failures[] = ['name' => $name, 'detail' => $detail];
        }
    };

    $assertHtmlHealth = function (string $name, array $resp, bool $requireThemeShell = false) use ($check): void {
        $content = (string) ($resp['content'] ?? '');
        $status = (int) ($resp['status'] ?? 0);
        $check($name . ':status', $status < 500, 'status=' . $status);
        $check($name . ':db_error', !str_contains($content, 'Error establishing a database connection'));
        $check($name . ':raw_shortcode', preg_match('/\[gigtune_[^\]]+\]/i', $content) !== 1);
        if ($status === 200 && $requireThemeShell) {
            $hasNav = str_contains($content, '<nav') || str_contains($content, 'gt-mobile-nav');
            $hasFooter = str_contains($content, '<footer');
            $check($name . ':header', $hasNav, 'missing nav');
            $check($name . ':footer', $hasFooter, 'missing footer');
        }
    };

    foreach (array_values(array_unique(array_merge([
        '/',
        '/artists/',
        '/join/',
        '/sign-in/',
        '/how-it-works/',
        '/pricing/',
        '/support-contact/',
    ], $pagePaths))) as $path) {
        $resp = $requestWithSession('GET', $path, [], $guestSession);
        $requireThemeShell = (int) $resp['status'] === 200
            && !str_starts_with($path, '/secret-admin-login-security')
            && !str_starts_with($path, '/admin-dashboard')
            && !str_starts_with($path, '/gts-admin-users')
            && !str_starts_with($path, '/admin/');
        $assertHtmlHealth('guest:' . $path, $resp, $requireThemeShell);
    }

    $adminPaths = [
        '/secret-admin-login-security',
        '/admin-dashboard',
        '/admin-dashboard/overview',
        '/admin-dashboard/users',
        '/admin-dashboard/payments',
        '/admin-dashboard/payouts',
        '/admin-dashboard/bookings',
        '/admin-dashboard/disputes',
        '/admin-dashboard/refunds',
        '/admin-dashboard/kyc',
        '/admin-dashboard/reports',
        '/gts-admin-users',
    ];

    foreach ($adminPaths as $path) {
        $session = $path === '/secret-admin-login-security' ? $guestSession : $adminSession;
        $resp = $requestWithSession('GET', $path, [], $session);
        $assertHtmlHealth('admin:' . $path, $resp, false);
        if ((int) $resp['status'] === 200) {
            $html = (string) $resp['content'];
            if ($path === '/secret-admin-login-security') {
                $check('admin_login_ui', str_contains($html, 'Admin Sign-in'));
            } elseif ($path === '/gts-admin-users') {
                $check('gts_users_ui', str_contains($html, 'Create User'));
            } else {
                $check('admin_layout_header:' . $path, str_contains($html, 'GigTune Admin'));
            }
            if ($path === '/admin-dashboard/kyc') {
                $check('admin_kyc_select_style', str_contains($html, 'gigtune-admin-select'));
            }
        }
    }

    foreach ([
        'artist' => ['session' => $artistSession, 'paths' => ['/artist-dashboard/', '/notifications/', '/notification-settings/', '/artist-profile-edit/']],
        'client' => ['session' => $clientSession, 'paths' => ['/client-dashboard/', '/notifications/', '/notification-settings/', '/my-account-page/']],
    ] as $role => $ctx) {
        $session = $ctx['session'];
        foreach ($ctx['paths'] as $path) {
            $resp = $requestWithSession('GET', $path, [], $session);
            $assertHtmlHealth($role . ':' . $path, $resp, (int) $resp['status'] === 200);
            if ((int) $resp['status'] === 200) {
                $html = (string) $resp['content'];
                if ($path === '/artist-dashboard/') {
                    $check('artist_dashboard_ui', str_contains($html, 'Artist Dashboard'));
                }
                if ($path === '/client-dashboard/') {
                    $check('client_dashboard_ui', str_contains($html, 'Client Dashboard'));
                }
            }
        }
    }

    $result = [
        'passed' => empty($failures),
        'failure_count' => count($failures),
        'failures' => $failures,
        'scanned' => [
            'published_pages' => count($pagePaths),
            'admin_paths' => count($adminPaths),
            'role_paths' => 8,
        ],
    ];

    $jsonPath = trim((string) $this->option('json'));
    if ($jsonPath !== '') {
        file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote UI audit report: ' . $jsonPath);
    }

    $this->newLine();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return empty($failures) ? 0 : 1;
})->purpose('Validate frontend/admin UI shells (header/footer/dashboard markers) and page health');

Artisan::command('gigtune:mail-audit {--user-id=0} {--send-test} {--json=}', function () {
    $mailer = app(\App\Services\GigTuneMailService::class);
    $users = app(\App\Services\WordPressUserService::class);

    $config = $mailer->auditConfiguration();
    $warnings = is_array($config['warnings'] ?? null) ? $config['warnings'] : [];
    $errors = [];
    $tests = [];

    if ((bool) $this->option('send-test')) {
        $targetUserId = abs((int) $this->option('user-id'));
        if ($targetUserId <= 0) {
            $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
            $prefix = (string) config('gigtune.wordpress.table_prefix', 'wp_');
            $targetUserId = (int) DB::connection($connection)
                ->table($prefix . 'users as u')
                ->whereExists(function ($query) use ($prefix): void {
                    $query->selectRaw('1')
                        ->from($prefix . 'usermeta as um')
                        ->whereColumn('um.user_id', 'u.ID')
                        ->whereIn('um.meta_key', [$prefix . 'capabilities', 'capabilities'])
                        ->where('um.meta_value', 'like', '%administrator%');
                })
                ->orderByDesc('u.ID')
                ->value('u.ID');
        }

        if ($targetUserId <= 0) {
            $errors[] = 'No valid target user was found for mail send test.';
        } else {
            $user = $users->getUserById($targetUserId);
            $email = is_array($user) ? (string) ($user['email'] ?? '') : '';
            if ($email === '') {
                $errors[] = 'Target user has no email address for send-test.';
            } else {
                $token = 'audit-' . Str::random(32);
                $verificationSent = $mailer->sendVerificationEmail($targetUserId, $token);
                $resetSent = $mailer->sendPasswordResetEmail($targetUserId, $token);
                $tests[] = [
                    'user_id' => $targetUserId,
                    'email' => $email,
                    'verification_email_sent' => $verificationSent,
                    'reset_email_sent' => $resetSent,
                ];
                if (!$verificationSent || !$resetSent) {
                    $errors[] = 'One or more mail send-test operations failed. Check mail logs/config.';
                }
            }
        }
    }

    $result = [
        'passed' => empty($errors),
        'config' => $config,
        'warnings' => $warnings,
        'tests' => $tests,
        'errors' => $errors,
    ];

    $jsonPath = trim((string) $this->option('json'));
    if ($jsonPath !== '') {
        file_put_contents($jsonPath, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->info('Wrote mail audit report: ' . $jsonPath);
    }

    $this->newLine();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    return empty($errors) ? 0 : 1;
})->purpose('Audit notification/email delivery configuration and optional test sends');
