@props(['items', 'id' => 'faq', 'title' => null])
{{-- FAQ accordion. The same items go into the page's FAQPage JSON-LD. --}}
<div class="cv-faq">
    @if ($title)
        <h2 class="cv-section-title mb-4">{{ $title }}</h2>
    @endif
    <div class="accordion" id="{{ $id }}">
        @foreach ($items as $i => $item)
            <div class="accordion-item">
                <h3 class="accordion-header">
                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse"
                            data-bs-target="#{{ $id }}-{{ $i }}" aria-expanded="false" aria-controls="{{ $id }}-{{ $i }}">
                        {{ $item['q'] }}
                    </button>
                </h3>
                <div id="{{ $id }}-{{ $i }}" class="accordion-collapse collapse" data-bs-parent="#{{ $id }}">
                    <div class="accordion-body">{{ $item['a'] }}</div>
                </div>
            </div>
        @endforeach
    </div>
</div>
