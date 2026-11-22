@extends('layouts.admin.app')

@use('App\Models\StorefrontLegalDocument')

@section('title', translate('legal_texts') . ' · ' . $board['country'])

@section('content')
    @php
        $country = $board['country'];
        $types = collect($board['required'])->pluck('type')->unique()->values();
        $locales = collect($board['required'])->pluck('locale')->unique()->values();
        $inUtc = fn ($moment): string => (string) $moment?->copy()->utc()->format('Y-m-d H:i');
    @endphp
    <div class="content container-fluid">
        <x-k.page-header :title="translate('legal_texts') . ' · ' . $country" :subtitle="translate('a_text_is_written_by_one_admin_approved_by_another_and_published_from_a_day')">
            <x-slot:actions>
                <a class="btn btn-outline-secondary btn-sm" href="{{ route('admin.storefronts.index') }}">{{ translate('back') }}</a>
            </x-slot:actions>
        </x-k.page-header>

        @if ($errors->any())
            <div class="alert alert-danger fs-12" role="alert">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        @if ($board['is_grandfathered'])
            <div class="alert alert-info fs-12" role="note">{{ translate('this_storefront_keeps_the_documents_it_has_always_had_its_texts_are_not_written_here') }}</div>
        @elseif (!$tableExists)
            <div class="alert alert-warning fs-12" role="alert">{{ translate('the_table_of_legal_texts_does_not_exist_yet_run_the_migrations') }}</div>
        @else
            <x-k.card :title="translate('what_this_storefront_needs')" :padded="false" class="mb-3">
                <div class="k-table-wrap table-responsive">
                    <table class="k-table">
                        <thead><tr>
                            <th>{{ translate('document') }}</th><th>{{ translate('language') }}</th><th>{{ translate('in_force_today') }}</th>
                        </tr></thead>
                        <tbody>
                        @foreach ($board['required'] as $required)
                            <tr data-required="{{ $required['type'] }}.{{ $required['locale'] }}" data-in-force="{{ $required['in_force'] ? 'yes' : 'no' }}">
                                <td>{{ $required['type'] }}</td>
                                <td dir="ltr">{{ $required['locale'] }}</td>
                                <td>
                                    @if ($required['in_force'])
                                        <span dir="ltr">v{{ $required['in_force']->version }}</span>, {{ translate('from') }} <span dir="ltr">{{ $inUtc($required['in_force']->effective_from) }} UTC</span>
                                        @if ($required['in_force']->revoked_at)<x-k.badge tone="danger" :dot="false">{{ translate('revoked') }}</x-k.badge>@endif
                                    @else
                                        <x-k.badge tone="danger" :dot="false">{{ translate('none') }}</x-k.badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </x-k.card>

            @if ($canWrite)
                <x-k.card :title="translate('write_a_draft_from_a_template')" class="mb-3">
                    <form method="POST" action="{{ route('admin.storefronts.legal-texts.draft', $country) }}">@csrf
                        <input type="hidden" name="_step" value="draft">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label fs-12" for="draft-document-type">{{ translate('document') }}</label>
                                <select class="form-select form-select-sm" id="draft-document-type" name="document_type" required>
                                    @foreach ($types as $type)
                                        <option value="{{ $type }}" @selected(old('_step') === 'draft' && old('document_type') === $type)>{{ $type }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fs-12" for="draft-locale">{{ translate('language') }}</label>
                                <select class="form-select form-select-sm" id="draft-locale" name="locale" required>
                                    @foreach ($locales as $locale)
                                        <option value="{{ $locale }}" @selected(old('_step') === 'draft' && old('locale') === $locale)>{{ $locale }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <label class="form-label fs-12 mt-2" for="draft-template">{{ translate('the_template_to_start_from') }}</label>
                        <textarea class="form-control form-control-sm mb-1" id="draft-template" name="template" rows="6" required dir="auto">{{ old('_step') === 'draft' ? old('template') : '' }}</textarea>
                        <div class="fs-12 text-muted mb-2">{{ translate('the_text_is_html_scripts_styles_forms_and_frames_are_removed_when_it_is_saved') }}</div>
                        <button class="btn btn-primary btn-sm" type="submit">{{ translate('write_the_draft') }}</button>
                    </form>
                </x-k.card>
            @endif

            <h3 class="fs-14 mb-2">{{ translate('versions') }}</h3>
            @forelse ($board['texts'] as $entry)
                @php
                    $text = $entry['text'];
                    $steps = $entry['steps'];
                    $isRevoked = $text->revoked_at !== null;
                    $tone = match (true) {
                        $isRevoked => 'danger',
                        $text->status === StorefrontLegalDocument::STATUS_PUBLISHED => 'success',
                        $text->status === StorefrontLegalDocument::STATUS_APPROVED => 'info',
                        default => 'neutral',
                    };
                    $isTheAuthor = (int) $text->created_by === $viewer;
                    $typedBody = old('_step') === 'edit-' . $text->id ? old('body') : $text->body;
                @endphp
                <x-k.card class="mb-3" :title="$text->document_type . ' · ' . $text->locale . ' · v' . $text->version" data-text="{{ $text->id }}" data-status="{{ $text->status }}">
                    <x-slot:actions>
                        <x-k.badge :tone="$tone">{{ translate('legal_text_status_' . $text->status) }}@if ($isRevoked) · {{ translate('revoked') }}@endif</x-k.badge>
                    </x-slot:actions>

                    <dl class="row fs-12 mb-2">
                        <dt class="col-sm-4">{{ translate('written_by') }}</dt>
                        <dd class="col-sm-8">{{ $board['people'][$text->created_by] ?? (translate('admin') . ' ' . $text->created_by) }}</dd>
                        @if ($text->approved_by)
                            <dt class="col-sm-4">{{ translate('approved_by') }}</dt>
                            <dd class="col-sm-8">{{ $board['people'][$text->approved_by] ?? (translate('admin') . ' ' . $text->approved_by) }}</dd>
                        @endif
                        @if ($text->effective_from)
                            <dt class="col-sm-4">{{ translate('in_force_from') }}</dt>
                            <dd class="col-sm-8" dir="ltr">{{ $inUtc($text->effective_from) }} UTC</dd>
                        @endif
                        <dt class="col-sm-4">{{ translate('fingerprint_of_the_text') }}</dt>
                        <dd class="col-sm-8" dir="ltr"><code>{{ substr((string) $text->body_hash, 0, 12) }}</code></dd>
                    </dl>

                    <details class="mb-2">
                        <summary class="fs-12">{{ translate('read_the_text') }}</summary>
                        <pre class="fs-12 text-wrap mt-2 mb-0" dir="auto">{{ $text->body }}</pre>
                    </details>

                    @if ($steps !== [])
                        <div class="border-top pt-3 mt-2 d-flex flex-column gap-3">
                            @if ($canWrite && in_array('edit', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.edit', $text->id) }}">@csrf
                                    <input type="hidden" name="_step" value="edit-{{ $text->id }}">
                                    <label class="form-label fs-12" for="edit-{{ $text->id }}-body">{{ translate('the_text') }}</label>
                                    <textarea class="form-control form-control-sm mb-1" id="edit-{{ $text->id }}-body" name="body" rows="6" required dir="auto">{{ $typedBody }}</textarea>
                                    @if ($text->status === StorefrontLegalDocument::STATUS_APPROVED)
                                        <div class="fs-12 text-muted mb-1">{{ translate('editing_an_approved_text_voids_its_approval_and_you_become_its_author') }}</div>
                                    @endif
                                    <button class="btn btn-outline-primary btn-sm" type="submit">{{ translate('save_the_text') }}</button>
                                </form>
                            @endif

                            @if ($canApprove && in_array('approve', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.approve', $text->id) }}">@csrf
                                    <input type="hidden" name="body_hash" value="{{ $text->body_hash }}">
                                    <button class="btn btn-primary btn-sm" type="submit" @disabled($isTheAuthor)>{{ translate('approve_the_text') }}</button>
                                    @if ($isTheAuthor)
                                        <span class="fs-12 text-muted">{{ translate('someone_other_than_its_author_approves_a_text') }}</span>
                                    @endif
                                </form>
                            @endif

                            @if ($canApprove && in_array('publish', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.publish', $text->id) }}">@csrf
                                    <input type="hidden" name="_step" value="publish-{{ $text->id }}">
                                    <input type="hidden" name="body_hash" value="{{ $text->body_hash }}">
                                    <label class="form-label fs-12" for="publish-{{ $text->id }}-from">{{ translate('in_force_from') }} (UTC)</label>
                                    <input class="form-control form-control-sm mb-1" id="publish-{{ $text->id }}-from" name="effective_from" maxlength="16" dir="ltr" placeholder="2026-12-01 08:00" value="{{ old('_step') === 'publish-' . $text->id ? old('effective_from') : '' }}">
                                    <div class="fs-12 text-muted mb-1">{{ translate('leave_the_day_empty_to_publish_now') }}</div>
                                    <button class="btn btn-primary btn-sm" type="submit">{{ translate('publish_the_text') }}</button>
                                </form>
                            @endif

                            @if ($canApprove && in_array('revoke', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.revoke', $text->id) }}">@csrf
                                    <input type="hidden" name="_step" value="revoke-{{ $text->id }}">
                                    <label class="form-label fs-12" for="revoke-{{ $text->id }}-reason">{{ translate('why_it_is_revoked') }}</label>
                                    <input class="form-control form-control-sm mb-1" id="revoke-{{ $text->id }}-reason" name="reason" required maxlength="500" value="{{ old('_step') === 'revoke-' . $text->id ? old('reason') : '' }}">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('revoke_the_text') }}</button>
                                </form>
                            @endif

                            @if ($canWrite && in_array('new_version', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.new-version', $text->id) }}">@csrf
                                    <button class="btn btn-outline-secondary btn-sm" type="submit">{{ translate('start_a_new_version') }}</button>
                                </form>
                            @endif

                            @if ($canWrite && in_array('discard', $steps, true))
                                <form method="POST" action="{{ route('admin.storefronts.legal-texts.discard', $text->id) }}">@csrf
                                    <input type="hidden" name="_step" value="discard-{{ $text->id }}">
                                    <label class="form-label fs-12" for="discard-{{ $text->id }}-reason">{{ translate('why_it_is_put_away') }}</label>
                                    <input class="form-control form-control-sm mb-1" id="discard-{{ $text->id }}-reason" name="reason" required maxlength="500" value="{{ old('_step') === 'discard-' . $text->id ? old('reason') : '' }}">
                                    <button class="btn btn-outline-danger btn-sm" type="submit">{{ translate('put_the_text_away') }}</button>
                                </form>
                            @endif
                        </div>
                    @endif
                </x-k.card>
            @empty
                <x-k.card>
                    <p class="fs-12 text-muted mb-0">{{ translate('no_text_was_written_for_this_storefront_yet') }}</p>
                </x-k.card>
            @endforelse
        @endif
    </div>
@endsection
