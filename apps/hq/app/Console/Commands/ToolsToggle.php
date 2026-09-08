<?php

namespace App\Console\Commands;

use App\Gateway\Registry\ToolSwitch;
use App\Models\User;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * The kill switch from a terminal, for the case where the UI is what is broken.
 *
 * The screen at /tools is the ordinary way an operator does this. This exists because "disable the
 * tool that is misbehaving" is exactly the thing you need when the application is the problem.
 */
final class ToolsToggle extends Command
{
    protected $signature = 'hq:tools:toggle {key} {--disable} {--enable} {--reason=} {--as= : email of the acting operator}';

    protected $description = 'Enable or disable a registered tool at runtime, audited';

    public function handle(ToolSwitch $switch): int
    {
        $key = (string) $this->argument('key');
        $disable = (bool) $this->option('disable');
        $enable = (bool) $this->option('enable');

        if ($disable === $enable) {
            $this->error('Pass exactly one of --disable or --enable.');

            return self::FAILURE;
        }

        $by = null;
        if ($email = $this->option('as')) {
            $by = User::where('email', $email)->first();
            if (! $by) {
                $this->error("No user with email [{$email}].");

                return self::FAILURE;
            }
        }

        try {
            if ($disable) {
                $reason = (string) $this->option('reason');
                if (trim($reason) === '') {
                    $this->error('Disabling needs --reason: "why is this off?" asked three weeks later is the question the record has to answer.');

                    return self::FAILURE;
                }
                $switch->disable($key, $reason, $by);
                $this->info("[{$key}] is disabled for every caller.");
            } else {
                $switch->enable($key, $this->option('reason') ?: null, $by);
                $this->info("[{$key}] is enabled.");
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
