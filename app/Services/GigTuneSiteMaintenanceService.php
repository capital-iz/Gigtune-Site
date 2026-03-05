<?php

namespace App\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GigTuneSiteMaintenanceService
{
    private const OPTION_NAME = 'gigtune_site_maintenance_mode';
    private const CACHE_KEY = 'gigtune.site_maintenance_mode';

    public function isEnabled(): bool
    {
        return (bool) Cache::remember(self::CACHE_KEY, now()->addSeconds(10), function (): bool {
            try {
                $value = $this->db()
                    ->table($this->optionsTable())
                    ->where('option_name', self::OPTION_NAME)
                    ->value('option_value');
            } catch (\Throwable) {
                return false;
            }

            $normalized = strtolower(trim((string) $value));
            return in_array($normalized, ['1', 'true', 'yes', 'on', 'enabled'], true);
        });
    }

    public function setEnabled(bool $enabled): void
    {
        $this->db()->table($this->optionsTable())->updateOrInsert(
            ['option_name' => self::OPTION_NAME],
            ['option_value' => $enabled ? '1' : '0']
        );

        Cache::forget(self::CACHE_KEY);
    }

    private function optionsTable(): string
    {
        return $this->tablePrefix() . 'options';
    }

    private function tablePrefix(): string
    {
        return (string) config('gigtune.wordpress.table_prefix', 'wp_');
    }

    private function db(): ConnectionInterface
    {
        $connection = (string) config('gigtune.wordpress.database_connection', 'wordpress');
        return DB::connection($connection);
    }
}
