@extends('layouts.seller.app')

@section('title', $rule ? translate('edit_automation') : translate('create_automation'))

@php
    use App\Services\SellerCenter\Copy;
    use App\Services\SellerCenter\Shell;

    $triggers = collect($catalogue['triggers']);
    $actions = collect($catalogue['actions'])->keyBy('key');

    /* Which trigger and action the form is showing was decided by the controller, from the server's
       own catalogue — the select offers only the pairs the server declares legal (handoff 08 A2). */
    $triggerSpec = $triggers->firstWhere('key', $chosenTrigger);
    $allowedActions = collect($triggerSpec['actions'] ?? []);
    $actionSpec = $actions->get($chosenAction);
    $classification = $actionSpec['classification'] ?? ['class' => 'safe', 'reason' => null];

    $triggerValues = old('trigger_settings', $rule->trigger_settings ?? []);
    $actionValues = old('action_settings', $rule->action_settings ?? []);
    $scopeValues = old('scope', $rule->scope ?? []);

    $cap = old('max_actions_per_run', $rule->max_actions_per_run ?? 5);
    $cooldown = old('cooldown_minutes', $rule->cooldown_minutes ?? 15);

    $sentence = app(\App\Services\SellerCenter\Automation\RulePresenter::class)->sentenceFrom(
        trigger: $chosenTrigger,
        action: $chosenAction,
        triggerSettings: is_array($triggerValues) ? $triggerValues : [],
        actionSettings: is_array($actionValues) ? $actionValues : [],
        cap: (int) $cap,
        cooldownMinutes: (int) $cooldown,
    );

    $formUrl = $rule
        ? Shell::route('seller.automation.update', $rule->id)
        : Shell::route('seller.automation.store');
@endphp

@section('content')
    <x-sc.page-header :eyebrow="translate('nav_automation_rules')"
                      :title="$rule ? translate('edit_automation') : translate('create_automation')"
                      :sub="translate('a_rule_watches_for_one_thing_and_does_one_thing_the_preview_shows_exactly_what_it_would_touch')">
        <x-slot:actions>
            @if ($backUrl = Shell::route('seller.automation.index'))
                <x-sc.button variant="ghost" icon="arrow-left" :href="$backUrl">{{ translate('back') }}</x-sc.button>
            @endif
        </x-slot:actions>
    </x-sc.page-header>

    <div class="sc-scroll">
        <form method="POST" action="{{ $formUrl }}" id="sc-rule-form">
            @csrf
            @if ($rule)@method('PUT')@endif

            <div class="sc-page sc-grid-builder">
                <div class="sc-stack">
                    @if ($errors->any())
                        <x-sc.alert tone="critical" :title="translate('this_rule_was_not_saved')">
                            <ul style="margin:0;padding-inline-start:16px">
                                @foreach ($errors->all() as $message)
                                    <li>{{ $message }}</li>
                                @endforeach
                            </ul>
                        </x-sc.alert>
                    @endif

                    <x-sc.card :label="translate('the_rule')">
                        <x-sc.field :label="translate('rule_name')" for="rule_name" required :error="$errors->first('name')">
                            <x-sc.input id="rule_name" name="name" :value="old('name', $rule->name ?? '')"
                                        maxlength="160" :invalid="$errors->has('name')"
                                        :placeholder="translate('what_this_rule_is_for')" />
                        </x-sc.field>
                    </x-sc.card>

                    {{-- WHEN ─────────────────────────────────────────────────── --}}
                    <x-sc.card :label="translate('when')">
                        <x-sc.field :label="translate('trigger')" for="rule_trigger" required :error="$errors->first('trigger')">
                            <x-sc.select id="rule_trigger" name="trigger" :value="$chosenTrigger"
                                         data-sc-rule-reload
                                         :options="$triggers->map(fn ($trigger) => ['value' => $trigger['key'], 'label' => translate('automation_trigger_' . $trigger['key'])])->all()" />
                        </x-sc.field>

                        @include('seller-views.automation._setting-fields', [
                            'fields' => $triggerSpec['fields'] ?? [],
                            'name' => 'trigger_settings',
                            'values' => $triggerValues,
                            'errors' => $errors,
                        ])
                    </x-sc.card>

                    {{-- THEN ─────────────────────────────────────────────────── --}}
                    <x-sc.card :label="translate('then')">
                        <x-sc.field :label="translate('action')" for="rule_action" required :error="$errors->first('action')">
                            <x-sc.select id="rule_action" name="action" :value="$chosenAction" data-sc-rule-reload
                                         :options="$allowedActions->map(fn ($key) => ['value' => $key, 'label' => translate('automation_action_' . $key)])->all()" />
                        </x-sc.field>

                        {{-- The safety class is the server's answer, not a decoration. An action the
                             seller may not perform says so here rather than at save time. --}}
                        <div class="sc-row" style="margin:-4px 0 10px">
                            <x-sc.badge :tone="$classification['class'] === 'safe' ? 'good' : 'neutral'"
                                        :glyph="$classification['class'] === 'safe' ? 'check-circle' : 'prohibit'"
                                        :label="translate('automation_class_' . $classification['class'])" />
                            @if ($classification['reason'])
                                <span class="sc-muted" style="font-size:11.5px">{{ translate($classification['reason']) }}</span>
                            @endif
                        </div>

                        @include('seller-views.automation._setting-fields', [
                            'fields' => $actionSpec['fields'] ?? [],
                            'name' => 'action_settings',
                            'values' => $actionValues,
                            'errors' => $errors,
                        ])
                    </x-sc.card>

                    {{-- GUARDS ───────────────────────────────────────────────── --}}
                    <x-sc.card :label="translate('limits')">
                        <p class="sc-dim" style="font-size:12px;margin:0 0 10px">
                            {{ translate('a_run_that_would_touch_more_than_the_cap_does_nothing_at_all_and_asks_for_a_person') }}
                        </p>

                        <div class="sc-grid-2">
                            <x-sc.field :label="translate('max_actions_per_run')" for="max_actions_per_run" required
                                        :error="$errors->first('max_actions_per_run')">
                                <x-sc.input id="max_actions_per_run" name="max_actions_per_run" type="number" num
                                            min="1" max="{{ $maxActionsPerRun }}"
                                            :value="$cap" data-sc-sentence="cap" />
                            </x-sc.field>

                            <x-sc.field :label="translate('cooldown_minutes')" for="cooldown_minutes" required
                                        :error="$errors->first('cooldown_minutes')">
                                <x-sc.input id="cooldown_minutes" name="cooldown_minutes" type="number" num
                                            min="5" max="10080"
                                            :value="$cooldown" data-sc-sentence="cooldown" />
                            </x-sc.field>
                        </div>
                    </x-sc.card>

                    {{-- SCOPE ────────────────────────────────────────────────── --}}
                    <x-sc.card :label="translate('scope')">
                        <p class="sc-dim" style="font-size:12px;margin:0 0 10px">
                            {{ translate('leave_empty_and_the_rule_applies_to_the_whole_catalogue') }}
                        </p>

                        @include('seller-views.automation._setting-fields', [
                            'fields' => $catalogue['scope'] ?? [],
                            'name' => 'scope',
                            'values' => $scopeValues,
                            'errors' => $errors,
                        ])
                    </x-sc.card>
                </div>

                {{-- Right column: what the rule says, and what it would touch ─── --}}
                <div class="sc-stack sc-context">
                    <x-sc.card side :label="translate('in_plain_words')">
                        <p style="font-size:12.5px;margin:0" id="sc-rule-sentence">{{ $sentence }}</p>
                    </x-sc.card>

                    @if ($rule)
                        <x-sc.card side :label="translate('preview_matches')">
                            <p class="sc-dim" style="font-size:12px;margin:0 0 10px">
                                {{ translate('runs_the_rule_without_changing_anything_and_lists_what_it_would_touch') }}
                            </p>
                            @if ($previewUrl = Shell::route('seller.automation.preview', $rule->id))
                                <x-sc.button variant="secondary" size="sm" icon="eye" :href="$previewUrl">{{ translate('preview_matches') }}</x-sc.button>
                            @endif
                        </x-sc.card>

                        <x-sc.card side :label="translate('what_it_has_done')">
                            <x-sc.info :label="translate('runs')" :value="number_format($presented['run_count'])" />
                            <x-sc.info :label="translate('applied')" :value="number_format($presented['applied_count'])" />
                            <x-sc.info :label="translate('success_rate')" :value="$presented['success_rate'] === null ? '—' : $presented['success_rate'] . '%'" />
                            <x-sc.info :label="translate('last_run')" :value="$presented['last_run_at'] ? $presented['last_run_at']->diffForHumans() : '—'" />
                        </x-sc.card>
                    @endif
                </div>
            </div>

            <div class="sc-form-footer">
                <div class="sc-row" style="gap:8px">
                    <x-sc.button type="submit" variant="primary" name="status" value="active">
                        {{ $rule ? translate('save') : translate('save_and_activate') }}
                    </x-sc.button>
                    <x-sc.button type="submit" variant="secondary" name="status" value="paused">
                        {{ translate('save_paused') }}
                    </x-sc.button>
                </div>
            </div>
        </form>

        @if ($rule && ($deleteUrl = Shell::route('seller.automation.destroy', $rule->id)))
            <div class="sc-page">
                <form method="POST" action="{{ $deleteUrl }}" data-sc-confirm="{{ translate('delete_this_rule_the_record_of_what_it_did_stays') }}">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="sc-btn sc-btn--ghost sc-btn--sm sc-btn--danger">{{ translate('delete_rule') }}</button>
                </form>
            </div>
        @endif
    </div>
@endsection

@push('script')
    <script>
        (function () {
            /* Changing the trigger or the action changes which settings exist, and the server is
               the only thing that knows which. The form reloads with the choice applied rather than
               carrying a copy of the catalogue in the page. */
            document.querySelectorAll('[data-sc-rule-reload]').forEach(function (control) {
                control.addEventListener('change', function () {
                    var params = new URLSearchParams(window.location.search);
                    params.set('trigger', document.getElementById('rule_trigger').value);
                    params.set('action', document.getElementById('rule_action').value);
                    window.location.search = params.toString();
                });
            });

            /* The plain-words sentence, kept current while the seller types. Every sentence here is
               a whole translated string from the server; this only puts the numbers into it, so the
               word order stays the translator's in both directions. */
            var templates = @json($sentenceTemplates);
            var target = document.getElementById('sc-rule-sentence');
            var form = document.getElementById('sc-rule-form');

            if (!target || !form) { return; }

            function fill(template, values) {
                return Object.keys(values).reduce(function (text, key) {
                    return text.split(':' + key).join(values[key] === '' ? '—' : values[key]);
                }, template);
            }

            function settings(prefix) {
                var values = {};
                form.querySelectorAll('[name^="' + prefix + '["]').forEach(function (input) {
                    var key = input.name.slice(prefix.length + 1, -1);
                    var raw = input.tagName === 'SELECT'
                        ? (input.selectedOptions[0] ? input.selectedOptions[0].textContent.trim() : '')
                        : input.value.trim();
                    /* The same rule the server's Copy::clause applies: a word sitting inside a
                       sentence does not keep the capital translate() gives every English string.
                       Only an ASCII capital is touched, so Arabic is left as written. */
                    values[key] = input.tagName === 'SELECT' ? raw.charAt(0).toLowerCase() + raw.slice(1) : raw;
                });
                return values;
            }

            function redraw() {
                var cap = form.querySelector('[data-sc-sentence="cap"]');
                var cooldown = form.querySelector('[data-sc-sentence="cooldown"]');

                target.textContent = fill(templates.frame, {
                    when: fill(templates.when, settings('trigger_settings')),
                    then: fill(templates.then, settings('action_settings')),
                    cap: cap ? cap.value : '',
                    /* Left as the raw minutes rather than reformatted here: turning 90 into "1 hour
                       30 minutes" is the server's sentence to write, not the browser's. */
                    cooldown: cooldown ? cooldown.value : '',
                });
            }

            form.addEventListener('input', redraw);
            form.addEventListener('change', redraw);
        })();
    </script>
@endpush
