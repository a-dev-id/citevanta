<div>
    <ol class="cv-stepper" aria-label="Setup progress">
        @foreach ($steps as $number => $label)
            @php
                $isCurrent = $number === $step;
                $isDone = ! $isCurrent && $number < $reached;
                $canOpen = $hotelId !== null && $number <= $reached && ! $isCurrent;
            @endphp
            <li @class(['cv-stepper__step', 'is-done' => $isDone, 'is-current' => $isCurrent])>
                <button type="button" class="cv-stepper__button"
                        @if ($canOpen) wire:click="goTo({{ $number }})" @else disabled @endif
                        @if ($isCurrent) aria-current="step" @endif>
                    <span class="cv-stepper__circle" aria-hidden="true">
                        @if ($isDone)
                            <i class="fa-solid fa-check"></i>
                        @else
                            {{ $number }}
                        @endif
                    </span>
                    <span class="cv-stepper__label">{{ $label }}</span>
                    <span class="visually-hidden">
                        Step {{ $number }} of {{ count($steps) }}{{ $isDone ? ', completed' : '' }}{{ $isCurrent ? ', current step' : '' }}
                    </span>
                </button>
            </li>
        @endforeach
    </ol>

    @error('finish')
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-circle-xmark me-1" aria-hidden="true"></i> {{ $message }}
        </div>
    @enderror

    @switch($step)
        @case(1)
            <livewire:hotels.details-form :hotel-id="$hotelId" :wizard="true" wire:key="wizard-step-1-{{ $hotelId }}" />
            @break
        @case(2)
            <livewire:hotels.facts-form :hotel-id="$hotelId" :wizard="true" wire:key="wizard-step-2-{{ $hotelId }}" />
            @break
        @case(3)
            <livewire:hotels.competitors-manager :hotel-id="$hotelId" :wizard="true" wire:key="wizard-step-3-{{ $hotelId }}" />
            @break
        @default
            <livewire:hotels.prompts-manager :hotel-id="$hotelId" :wizard="true" wire:key="wizard-step-4-{{ $hotelId }}" />
    @endswitch
</div>
