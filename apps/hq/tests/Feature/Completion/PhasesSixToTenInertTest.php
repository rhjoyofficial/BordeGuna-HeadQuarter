<?php

use App\Autonomy\Ladder\LadderGrants;
use App\Avatar\AvatarGenerations;
use App\Avatar\AvatarProvider;
use App\Avatar\PhaseEightTools;
use App\Content\Calendar\CaptionDrafter;
use App\Content\Calendar\ContentCalendar;
use App\Models\AutonomyGrant;
use App\Models\AutonomyLadderThreshold;
use App\Models\FeatureFlag;
use App\Models\Skill;
use App\Platform\PlatformRegistry;
use App\Skills\Delegations;
use App\Support\Features\Features;
use Database\Seeders\DatabaseSeeder;

/*
 | docs/145: everything Phases 6 to 10 added ships OFF. No flag row, no drafter, no avatar provider, no
 | publisher, no generation tool registered, no threshold, no grant, no skill, after the full seeder.
 */

it('ships every Phase 6 to 10 switch off, unbound and empty after the full seeder', function () {
    $this->seed(DatabaseSeeder::class);

    foreach ([ContentCalendar::AI_FLAG, AvatarGenerations::FLAG, Delegations::FLAG, 'content.drafting', 'publishing.write', 'research.engine'] as $flag) {
        expect(FeatureFlag::query()->where('key', $flag)->exists())->toBeFalse("flag {$flag} has a row")
            ->and(app(Features::class)->enabled($flag))->toBeFalse();
    }
    foreach (PhaseEightTools::DECLARED as $class) {
        expect(in_array($class, config('hq.tools'), true))->toBeFalse();
    }
    expect(app()->bound(CaptionDrafter::class))->toBeFalse()
        ->and(app()->bound(AvatarProvider::class))->toBeFalse()
        ->and(app(PlatformRegistry::class)->platforms())->toBe([])
        ->and(AutonomyLadderThreshold::query()->count())->toBe(0)
        ->and(AutonomyGrant::query()->count())->toBe(0)
        ->and(Skill::query()->count())->toBe(0);
});

it('keeps the grant a human act: no tool, job or agent path names the ladder grant service', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
        if (str_contains($path, '/Autonomy/Ladder/') || str_ends_with($path, '/Http/Controllers/Completion/AutonomyController.php')) {
            continue;
        }
        if (str_contains((string) file_get_contents($file->getPathname()), class_basename(LadderGrants::class))) {
            $offenders[] = $path;
        }
    }
    expect($offenders)->toBe([]);
});
