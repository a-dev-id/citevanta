@props([
    // BookingDemandReport::calendar()
    'calendar',
])

@php
    $top = $calendar['top'];
@endphp

{{-- Alpine component: three months side by side, each night shaded by how many guests looked at it or started booking it. --}}
<div x-data="demandCalendar(@js($calendar))" class="cv-cal" id="demand-calendar">
    <div class="cv-cal__bar">
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="shift(-1)" :disabled="! canPrev" aria-label="Earlier months"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i></button>
            <button type="button" class="btn btn-sm btn-outline-secondary" @click="shift(1)" :disabled="! canNext" aria-label="Later months"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
            <strong x-text="rangeLabel"></strong>
        </div>
        <div class="cv-cal__legend" aria-hidden="true">
            <span>Fewer</span>
            @foreach (range(0, 5) as $level)<i class="cv-cal__swatch cv-heat-{{ $level }}"></i>@endforeach
            <span>More</span>
        </div>
    </div>

    <div class="row g-4">
        <template x-for="m in months" :key="m.key">
            <div class="col-lg-4">
                <h3 class="cv-cal__month" x-text="m.label"></h3>
                <div class="cv-cal__grid">
                    <template x-for="w in ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU']" :key="w"><span class="cv-cal__wd" x-text="w"></span></template>
                    <template x-for="b in m.blanks" :key="'b' + b"><span></span></template>
                    <template x-for="d in m.days" :key="d.key">
                        <button type="button" class="cv-cal__day" :class="d.cls" :disabled="d.past"
                                @mouseenter="show($event, d.key)" @mouseleave="hide()" @focus="show($event, d.key)" @blur="hide()" @click="pick($event, d.key)"
                                :aria-label="d.label" x-text="d.n"></button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    <div class="cv-cal__summary">
        @if ($top)
            <span class="cv-cal__dot" aria-hidden="true"></span>
            <span id="demand-top">Most searched check-in date ahead: <strong>{{ \Illuminate\Support\Carbon::parse($top['date'])->format('D j M') }}</strong>, {{ number_format($top['demand']) }} {{ $top['demand'] === 1 ? 'look or started booking' : 'looks and started bookings' }}. Hold the mouse over a date to see the stays behind it.</span>
        @else
            <span>No upcoming stay dates yet.</span>
        @endif
    </div>

    <div x-show="tip" x-cloak class="cv-cal__tip" :style="tip ? `left: ${tip.x}px; top: ${tip.y}px` : ''" role="tooltip" id="demand-tip">
        <template x-if="tip">
            <div>
                <div class="cv-cal__tip-title" x-text="fullDate(tip.key)"></div>
                <template x-if="data[tip.key]">
                    <div>
                        <div class="cv-cal__tip-totals">
                            <span><strong x-text="data[tip.key].l"></strong> looked at rooms</span>
                            <span><strong x-text="data[tip.key].s"></strong> started a booking</span>
                            <span><strong x-text="data[tip.key].b"></strong> booked</span>
                        </div>
                        <table class="cv-cal__tip-table">
                            <thead><tr><th scope="col">Stay</th><th scope="col">Looked</th><th scope="col">Started</th><th scope="col">Booked</th></tr></thead>
                            <tbody>
                                <template x-for="s in data[tip.key].stays" :key="s[0]">
                                    <tr><td x-text="stayLabel(s[0])"></td><td x-text="s[1]"></td><td x-text="s[2]"></td><td x-text="s[3]"></td></tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </template>
                <p x-show="! data[tip.key]" class="mb-0 text-muted">No searches for this date yet.</p>
            </div>
        </template>
    </div>
</div>

@once
    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('demandCalendar', (cal) => ({
                data: cal.days,
                max: cal.max,
                today: cal.today,
                selected: null,
                tip: null,
                base: cal.today.slice(0, 7),

                pad(n) { return String(n).padStart(2, '0'); },

                addMonths(ym, n) {
                    const [y, m] = ym.split('-').map(Number);
                    const d = new Date(Date.UTC(y, m - 1 + n, 1));
                    return d.getUTCFullYear() + '-' + this.pad(d.getUTCMonth() + 1);
                },

                get canPrev() { return this.base > this.today.slice(0, 7); },

                // Stay dates are shown up to a year ahead.
                get canNext() { return this.addMonths(this.base, 3) <= this.addMonths(this.today.slice(0, 7), 12); },

                shift(n) { this.base = this.addMonths(this.base, n); },

                level(info) {
                    if (!info || !this.max) return 0;
                    return Math.min(5, Math.max(1, Math.ceil(5 * info.d / this.max)));
                },

                fullDate(key) {
                    return new Date(key + 'T00:00:00Z').toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
                },

                monthLabel(ym, format) {
                    const [y, m] = ym.split('-').map(Number);
                    return new Date(Date.UTC(y, m - 1, 1)).toLocaleDateString('en-GB', Object.assign({ timeZone: 'UTC' }, format));
                },

                get rangeLabel() {
                    const last = this.addMonths(this.base, 2);
                    const sameYear = this.base.slice(0, 4) === last.slice(0, 4);

                    return this.monthLabel(this.base, sameYear ? { month: 'short' } : { month: 'short', year: 'numeric' }) + ' – ' + this.monthLabel(last, { month: 'short', year: 'numeric' });
                },

                get months() {
                    return [0, 1, 2].map((i) => {
                        const ym = this.addMonths(this.base, i);
                        const [y, m] = ym.split('-').map(Number);
                        const blanks = (new Date(Date.UTC(y, m - 1, 1)).getUTCDay() + 6) % 7;
                        const count = new Date(Date.UTC(y, m, 0)).getUTCDate();
                        const days = [];

                        for (let n = 1; n <= count; n++) {
                            const key = ym + '-' + this.pad(n);
                            const info = this.data[key];
                            const past = key < this.today;

                            days.push({
                                key, n, past,
                                cls: ['cv-heat-' + (past ? 0 : this.level(info)), past ? 'is-past' : '', key === this.today ? 'is-today' : '', key === this.selected ? 'is-selected' : ''].join(' '),
                                label: this.fullDate(key) + (info ? ': ' + info.d + ' looks and started bookings' : ''),
                            });
                        }

                        return { key: ym, label: this.monthLabel(ym, { month: 'long', year: 'numeric' }), blanks, days };
                    });
                },

                // The pop-up sits above the night, inside the calendar, and stays inside its edges.
                show(event, key) {
                    const box = this.$root.getBoundingClientRect();
                    const cell = event.currentTarget.getBoundingClientRect();
                    const half = 150;

                    this.tip = {
                        key,
                        x: Math.min(Math.max(cell.left - box.left + cell.width / 2, half), Math.max(box.width - half, half)),
                        y: cell.top - box.top,
                    };
                },

                hide() { if (!this.selected) this.tip = null; },

                // Touch screens have no hover: a tap keeps the pop-up open until the next tap.
                pick(event, key) {
                    this.selected = this.selected === key ? null : key;
                    this.selected ? this.show(event, key) : (this.tip = null);
                },

                stayLabel(n) { return n === 0 ? 'Not known' : n + (n === 1 ? ' night' : ' nights'); },
            }));
        });
    </script>
@endonce
