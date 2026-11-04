{{-- FAQ: a help panel beside native <details> accordions (works without JS). --}}

@php $faqStyle = $s['style'] ?? 'panel'; @endphp
{{-- Panel puts the questions beside a help panel; two_column splits them
     into two readable columns for a long list; cards makes each question a
     tile, which suits a short set of high-intent questions (delivery,
     returns, prescriptions). --}}
@if (count($blocks))
    <div class="ml-faq ml-faq--{{ $faqStyle }}">
        <aside class="ml-faq__intro ml-reveal">
            <span class="ml-eyebrow">{{ $s['eyebrow'] ?: translate('help_center') }}</span>
            <h3>{{ $s['title'] ?: translate('frequently_asked_questions') }}</h3>
            @if (!empty($s['subtitle']))<p>{{ $s['subtitle'] }}</p>@endif
            @if (!empty($s['button_text']))
                <a href="{{ $s['link'] ?: route('contacts') }}" class="ml-btn ml-btn-light">{{ $s['button_text'] }}</a>
            @endif
        </aside>
        <div class="ml-reveal ml-faq__list">
            @foreach ($__section['blocks'] ?? [] as $qa)
                @php $qaSettings = $qa['settings'] ?? []; @endphp
                @if (!empty($qaSettings['question']))
                    <details @if ($loop->first && $faqStyle === 'panel') open @endif>
                        <summary><span>{{ $qaSettings['question'] }}</span></summary>
                        <div>{{ $qaSettings['answer'] ?? '' }}</div>
                    </details>
                @endif
            @endforeach
        </div>
    </div>
@endif
