<?php

use App\Ai\ModelTier;
use App\Models\AiModel;
use Database\Seeders\ModelTierSeeder;

it('registers a model for every chat tier, with prices and a verification date', function () {
    (new ModelTierSeeder)->run();

    foreach ([ModelTier::Low, ModelTier::Medium, ModelTier::High] as $tier) {
        $model = AiModel::query()->forTier($tier)->first();

        expect($model)->not->toBeNull("no model registered at the {$tier->value} tier")
            ->and((float) $model->input_price_per_mtok)->toBeGreaterThan(0)
            ->and((float) $model->output_price_per_mtok)->toBeGreaterThan(0)
            // A price with no verification date is a price nobody can check.
            ->and($model->pricing_verified_on)->not->toBeNull()
            ->and($model->context_window)->toBeGreaterThan(0);
    }
});

it('leaves the specialised tier empty rather than registering a chat model for embeddings', function () {
    (new ModelTierSeeder)->run();

    // A vector from a chat model is not comparable with the vectors already stored, so refusing is
    // the only honest option until an embedding provider exists.
    expect(AiModel::query()->forTier(ModelTier::Specialised)->count())->toBe(0);
});

it('is idempotent and does not re-enable a model an operator switched off', function () {
    (new ModelTierSeeder)->run();
    $before = AiModel::count();

    $model = AiModel::query()->forTier(ModelTier::Low)->first();
    $model->update(['is_active' => false]);

    (new ModelTierSeeder)->run();

    expect(AiModel::count())->toBe($before)
        // ⚠ The operator's decision survives. A seeder that reset the table would silently undo it.
        ->and($model->fresh()->is_active)->toBeFalse();
});

it('prices the cheaper tier below the dearer one, so degrading actually saves money', function () {
    (new ModelTierSeeder)->run();

    $low = AiModel::query()->forTier(ModelTier::Low)->first();
    $medium = AiModel::query()->forTier(ModelTier::Medium)->first();
    $high = AiModel::query()->forTier(ModelTier::High)->first();

    expect((float) $low->input_price_per_mtok)->toBeLessThan((float) $medium->input_price_per_mtok)
        ->and((float) $medium->input_price_per_mtok)->toBeLessThan((float) $high->input_price_per_mtok);
});

it('records a cache read price below the input price on every model', function () {
    (new ModelTierSeeder)->run();

    foreach (AiModel::all() as $model) {
        expect((float) $model->cache_read_price_per_mtok)
            ->toBeLessThan((float) $model->input_price_per_mtok, "{$model->display_name} cache read price is not below its input price");
    }
});
