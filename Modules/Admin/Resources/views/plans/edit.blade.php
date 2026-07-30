@extends('admin::layouts.app')

@section('title', 'Edit ' . $plan->name . ' — back office')
@section('page_pretitle', 'Plans')
@section('page_title', 'Edit ' . $plan->name)

@section('page_actions')
    <a href="{{ route('admin.plans.index') }}" class="btn">
        <i class="ti ti-arrow-left me-1"></i>All plans
    </a>
@endsection

@section('content')
    <form method="POST" action="{{ route('admin.plans.update', $plan->code) }}">
        @csrf
        @method('PUT')

        <div class="row row-cards">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Details</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Code</label>
                            {{-- Read-only: the code is how code refers to this plan
                                 (Plan::CODE_FREE, the checkout URL, existing
                                 subscription snapshots). Renaming it would orphan all
                                 three. --}}
                            <input type="text" class="form-control" value="{{ $plan->code }}" disabled>
                            <small class="form-hint">
                                Fixed — referenced by existing subscriptions and by code.
                            </small>
                        </div>

                        <div class="mb-3">
                            <label for="name" class="form-label required">Name</label>
                            <input type="text" name="name" id="name" value="{{ old('name', $plan->name) }}"
                                   class="form-control @error('name') is-invalid @enderror" required maxlength="120">
                            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="mb-3">
                            <label for="tagline" class="form-label">Tagline</label>
                            <input type="text" name="tagline" id="tagline"
                                   value="{{ old('tagline', $plan->tagline) }}"
                                   class="form-control" maxlength="255">
                            <small class="form-hint">Shown under the plan name on the pricing page.</small>
                        </div>

                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label for="price" class="form-label required">Price (ZAR)</label>
                                <div class="input-group">
                                    <span class="input-group-text">R</span>
                                    {{-- Entered in rand; converted to integer cents in the
                                         controller, which is the only conversion point. --}}
                                    <input type="number" name="price" id="price" step="0.01" min="0"
                                           value="{{ old('price', number_format($plan->price_cents / 100, 2, '.', '')) }}"
                                           class="form-control @error('price') is-invalid @enderror" required>
                                </div>
                                @error('price')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-sm-6 mb-3">
                                <label for="interval" class="form-label required">Billing interval</label>
                                <select name="interval" id="interval" class="form-select" required>
                                    @foreach (['monthly' => 'Monthly', 'annually' => 'Annually', 'none' => 'None (free)'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('interval', $plan->interval) === $value)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Allowances</h3></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-sm-6 mb-3">
                                <label for="generation_quota" class="form-label">Generations per period</label>
                                <input type="number" name="generation_quota" id="generation_quota" min="0"
                                       value="{{ old('generation_quota', $plan->generation_quota) }}"
                                       class="form-control @error('generation_quota') is-invalid @enderror">
                                @error('generation_quota')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                {{-- The blank-vs-zero distinction is a real footgun: they
                                     are opposites, and the code never conflates them. --}}
                                <small class="form-hint">
                                    <strong>Leave blank for unlimited.</strong> Zero means none at all.
                                </small>
                            </div>

                            <div class="col-sm-6 mb-3">
                                <label for="seat_limit" class="form-label">Seats</label>
                                <input type="number" name="seat_limit" id="seat_limit" min="1"
                                       value="{{ old('seat_limit', $plan->seat_limit) }}"
                                       class="form-control @error('seat_limit') is-invalid @enderror">
                                @error('seat_limit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                <small class="form-hint">Leave blank for unlimited.</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header"><h3 class="card-title">Features</h3></div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label">Export formats</label>
                            @foreach ($exportFormats as $value => $label)
                                @php
                                    $current = $plan->feature('exports', []);
                                    $current = is_array($current) ? $current : [];
                                    // Markdown is always granted (FeatureGate baseline), so
                                    // it is shown ticked and locked rather than offered as
                                    // a choice the app would override anyway.
                                    $isBaseline = in_array($value, \Modules\Billing\Services\FeatureGate::BASELINE_EXPORTS, true);
                                @endphp
                                <label class="form-check">
                                    <input type="checkbox" name="exports[]" value="{{ $value }}"
                                           class="form-check-input"
                                           @checked($isBaseline || in_array($value, $current, true))
                                           @disabled($isBaseline)>
                                    <span class="form-check-label">
                                        {{ $label }}
                                        @if ($isBaseline)
                                            <span class="text-secondary small">(always included)</span>
                                        @endif
                                    </span>
                                </label>
                            @endforeach
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Capabilities</label>
                            <label class="form-check">
                                <input type="checkbox" name="features_custom_prompts" value="1"
                                       class="form-check-input"
                                       @checked($plan->feature(\Modules\Billing\Services\FeatureGate::CUSTOM_PROMPTS))>
                                <span class="form-check-label">Editable generation prompts</span>
                            </label>
                            <label class="form-check">
                                <input type="checkbox" name="features_api" value="1"
                                       class="form-check-input"
                                       @checked($plan->feature(\Modules\Billing\Services\FeatureGate::API))>
                                <span class="form-check-label">API access</span>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header"><h3 class="card-title">Visibility</h3></div>
                    <div class="card-body">
                        <label class="form-check">
                            <input type="checkbox" name="is_active" value="1" class="form-check-input"
                                   @checked($plan->is_active)>
                            <span class="form-check-label">
                                Active
                                <span class="d-block text-secondary small">
                                    Can be subscribed to. Turning this off does not affect existing
                                    subscribers.
                                </span>
                            </span>
                        </label>

                        <label class="form-check">
                            <input type="checkbox" name="is_public" value="1" class="form-check-input"
                                   @checked($plan->is_public)>
                            <span class="form-check-label">
                                Show on the public pricing page
                                <span class="d-block text-secondary small">
                                    Turn off for a bespoke or grandfathered plan that should stay
                                    subscribable but hidden.
                                </span>
                            </span>
                        </label>

                        <div class="mt-3">
                            <label for="sort" class="form-label required">Display order</label>
                            <input type="number" name="sort" id="sort" min="0"
                                   value="{{ old('sort', $plan->sort) }}" class="form-control" required>
                            <small class="form-hint">Lower numbers appear first.</small>
                        </div>
                    </div>
                    <div class="card-footer">
                        <button type="submit" class="btn btn-primary w-100">Save plan</button>
                    </div>
                </div>

                @if ($plan->isPaid())
                    <div class="card mt-3">
                        <div class="card-header"><h3 class="card-title">Paystack</h3></div>
                        <div class="card-body">
                            @if ($plan->paystack_plan_code)
                                <dl class="mb-0 small">
                                    <dt class="text-secondary">Plan code</dt>
                                    <dd class="mb-0"><code>{{ $plan->paystack_plan_code }}</code></dd>
                                </dl>
                                <div class="text-secondary small mt-2">
                                    Changing the price above does not change it at Paystack. Push the
                                    plan again from the plans list so new subscribers are billed the
                                    new amount.
                                </div>
                            @else
                                <div class="text-danger small">
                                    Not pushed to Paystack yet, so customers cannot subscribe to it.
                                    Push it from the plans list.
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        </div>
    </form>
@endsection
