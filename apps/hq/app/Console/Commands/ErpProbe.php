<?php

namespace App\Console\Commands;

use App\Erp\ErpRegistry;
use Illuminate\Console\Command;

final class ErpProbe extends Command
{
    protected $signature = 'hq:erp:probe {business? : HQ business slug; every business with a stored token when omitted} {--overview : also fetch the overview}';

    protected $description = 'Check that the stored ERP token for a business works and can see its own business.';

    public function handle(ErpRegistry $registry): int
    {
        $slugs = $this->argument('business') ? [$this->argument('business')] : $registry->businessesWithConnectors();
        if ($slugs === []) {
            $this->warn('No business has an active erp_api_token credential yet.');

            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($slugs as $slug) {
            try {
                $erp = $registry->for($slug);
                $probe = $erp->probe();
                $this->line("[{$slug}] ".json_encode($probe));
                $failed += $probe['ok'] ? 0 : 1;
                if ($this->option('overview') && $probe['ok']) {
                    $this->line("[{$slug}] overview: ".json_encode($erp->overview()->toArray()));
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->error("[{$slug}] ".class_basename($e).': '.$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
