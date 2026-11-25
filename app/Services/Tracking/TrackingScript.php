<?php

namespace App\Services\Tracking;

use App\Models\HotelTracking;
use App\Services\Support\Url;

/**
 * The website tracking script of a hotel, generated from its settings: a small async JavaScript with no
 * dependencies, no cookies, no storage, no fingerprinting and no personal data.
 *
 * It does two things on the hotel's own website:
 *  - AI referral visits: when the referrer or utm_source is an AI assistant, it sends a beacon (platform, page
 *    path) to our endpoint and a GA4 event ai_referral.
 *  - Booking clicks: on a click on a booking link or button it reads the check-in and check-out date and the
 *    guests from the link's query parameters (or from date inputs on the page), pushes a GA4 event
 *    booking_click and sends the same values to our endpoint.
 * It never throws: every step is inside try/catch, so the visitor's page is never affected.
 */
final class TrackingScript
{
    /**
     * The settings of a hotel: its own choices over the defaults in config('citevanta.tracking').
     *
     * @return array{selectors: list<string>, params: array<string, list<string>>, inputs: array<string, string>, date_format: string}
     */
    public static function settings(HotelTracking $tracking): array
    {
        $own = (array) ($tracking->settings ?? []);
        $defaults = (array) config('citevanta.tracking');
        $params = [];

        foreach ((array) $defaults['link_params'] as $field => $names) {
            $params[$field] = array_values(array_filter((array) ($own['params'][$field] ?? $names), fn ($name) => is_string($name) && $name !== ''));
        }

        $inputs = [];
        foreach ((array) $defaults['date_inputs'] as $field => $selector) {
            $inputs[$field] = (string) (($own['inputs'][$field] ?? '') !== '' ? $own['inputs'][$field] : $selector);
        }

        return [
            'selectors' => array_values(array_filter((array) ($own['selectors'] ?? $defaults['booking_selectors']), fn ($selector) => is_string($selector) && $selector !== '')),
            'params' => $params,
            'inputs' => $inputs,
            'date_format' => in_array($own['date_format'] ?? null, ['dmy', 'mdy'], true) ? $own['date_format'] : (string) $defaults['date_format'],
        ];
    }

    /**
     * The AI list the script knows: a domain (chatgpt.com) or a utm_source word (chatgpt) => platform name.
     *
     * @return array<string, string>
     */
    public static function aiMap(): array
    {
        return array_map('strval', (array) config('citevanta.ai_sources', []));
    }

    /** The platform names the endpoint accepts. */
    public static function platforms(): array
    {
        return array_values(array_unique(self::aiMap()));
    }

    /** The one line the web developer pastes before the closing head or body tag. */
    public static function snippet(HotelTracking $tracking): string
    {
        return '<script async src="'.route('tracking.script', ['key' => $tracking->public_key]).'"></script>';
    }

    /** A sentence for the hotel's privacy policy. */
    public static function privacySentence(string $hotelName): string
    {
        return $hotelName.' uses a small script on its website that counts visits that arrive from AI assistants such as ChatGPT and clicks on booking buttons, together with the stay dates and number of guests in the booking link. '
            .'The script sets no cookies, stores nothing in your browser, does not use your IP address or any device identifier, and cannot identify you.';
    }

    /**
     * The JavaScript file for a hotel.
     */
    public static function build(HotelTracking $tracking): string
    {
        $settings = self::settings($tracking);
        $hotel = $tracking->hotel;
        $bookingHost = Url::host($hotel?->booking_engine_domain);
        $selectors = $settings['selectors'];

        if ($bookingHost !== '') {
            $selectors[] = 'a[href*="'.str_replace(['"', '\\'], '', $bookingHost).'"]';
        }

        $config = [
            'k' => $tracking->public_key,
            'e' => route('tracking.collect'),
            'ai' => (object) self::aiMap(),
            'sel' => implode(',', $selectors),
            'p' => $settings['params'],
            'in' => $settings['inputs'],
            'df' => $settings['date_format'],
        ];

        return str_replace('__CONFIG__', (string) json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP), self::TEMPLATE);
    }

    private const TEMPLATE = <<<'JS'
/* CiteVanta website script: no cookies, no storage, no personal data. */
(function () {
  try {
    var C = __CONFIG__;
    if (window.__citevantaLoaded) { return; }
    window.__citevantaLoaded = 1;

    var debug = /[?&]citevanta_debug=1(&|#|$)/.test(window.location.search);
    var box = null;

    function show(line) {
      if (!debug) { return; }
      try {
        if (!box) {
          box = document.createElement('div');
          box.setAttribute('style', 'position:fixed;z-index:2147483647;right:8px;bottom:8px;max-width:340px;background:#111;color:#fff;font:12px/1.4 sans-serif;padding:8px 10px;border-radius:6px;opacity:.92');
          box.appendChild(document.createTextNode('CiteVanta debug'));
          (document.body || document.documentElement).appendChild(box);
        }
        var row = document.createElement('div');
        row.appendChild(document.createTextNode(line));
        box.appendChild(row);
      } catch (e) {}
    }

    function path() {
      return String(window.location.pathname || '/').slice(0, 300);
    }

    function send(payload) {
      try {
        payload.k = C.k;
        var body = JSON.stringify(payload);
        if (navigator.sendBeacon) {
          navigator.sendBeacon(C.e, new Blob([body], { type: 'text/plain' }));
        } else if (window.fetch) {
          window.fetch(C.e, { method: 'POST', body: body, mode: 'cors', keepalive: true, credentials: 'omit', headers: { 'Content-Type': 'text/plain' } }).catch(function () {});
        }
      } catch (e) {}
    }

    function ga(name, params) {
      try {
        if (typeof window.gtag === 'function') {
          window.gtag('event', name, params);
        } else if (window.dataLayer && typeof window.dataLayer.push === 'function') {
          var item = { event: name };
          for (var key in params) { if (Object.prototype.hasOwnProperty.call(params, key)) { item[key] = params[key]; } }
          window.dataLayer.push(item);
        }
      } catch (e) {}
    }

    // --- AI referral visits ---------------------------------------------------

    function hostOf(url) {
      try {
        var a = document.createElement('a');
        a.href = url;
        return String(a.hostname || '').toLowerCase().replace(/^www\./, '');
      } catch (e) { return ''; }
    }

    function platformFor(candidate) {
      candidate = String(candidate || '').toLowerCase().replace(/^www\./, '');
      if (!candidate) { return ''; }
      if (Object.prototype.hasOwnProperty.call(C.ai, candidate)) { return C.ai[candidate]; }
      if (candidate.indexOf('.') < 0) { return ''; }
      for (var key in C.ai) {
        if (key.indexOf('.') < 0 || !Object.prototype.hasOwnProperty.call(C.ai, key)) { continue; }
        if (candidate === key || candidate.slice(-key.length - 1) === '.' + key) { return C.ai[key]; }
      }
      return '';
    }

    function utmSource() {
      var m = /[?&]utm_source=([^&#]*)/i.exec(window.location.search || '');
      if (!m) { return ''; }
      try { return decodeURIComponent(m[1].replace(/\+/g, ' ')); } catch (e) { return ''; }
    }

    function aiReferral() {
      var platform = platformFor(hostOf(document.referrer || '')) || platformFor(utmSource());
      if (!platform) { return; }
      show('ai_referral: ' + platform);
      send({ t: 'ai_referral', p: platform, path: path() });
      ga('ai_referral', { ai_platform: platform, page_path: path() });
    }

    // --- Booking clicks -------------------------------------------------------

    function pad(n) { return (n < 10 ? '0' : '') + n; }

    function isoDate(y, m, d) {
      var date = new Date(Date.UTC(y, m - 1, d));
      if (date.getUTCFullYear() !== y || date.getUTCMonth() !== m - 1 || date.getUTCDate() !== d) { return ''; }
      return y + '-' + pad(m) + '-' + pad(d);
    }

    function toIso(value) {
      value = String(value || '').replace(/^\s+|\s+$/g, '');
      var m = /^(\d{4})[-\/.]?(\d{2})[-\/.]?(\d{2})$/.exec(value);
      if (m) { return isoDate(+m[1], +m[2], +m[3]); }
      m = /^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/.exec(value);
      if (m) { return C.df === 'mdy' ? isoDate(+m[3], +m[1], +m[2]) : isoDate(+m[3], +m[2], +m[1]); }
      return '';
    }

    function toCount(value) {
      var n = parseInt(String(value || '').replace(/[^0-9]/g, ''), 10);
      return isNaN(n) || n < 0 || n > 20 ? '' : n;
    }

    function parseQuery(url) {
      var out = {};
      try {
        var q = String(url || '').split('#')[0].split('?')[1] || '';
        var parts = q.split('&');
        for (var i = 0; i < parts.length; i++) {
          var kv = parts[i].split('=');
          if (!kv[0]) { continue; }
          try { out[decodeURIComponent(kv[0]).toLowerCase()] = decodeURIComponent((kv[1] || '').replace(/\+/g, ' ')); } catch (e) {}
        }
      } catch (e) {}
      return out;
    }

    function fromLink(query, field) {
      var names = C.p[field] || [];
      for (var i = 0; i < names.length; i++) {
        var value = query[String(names[i]).toLowerCase()];
        if (value !== undefined && value !== '') { return value; }
      }
      return '';
    }

    function fromInput(field) {
      try {
        var el = C.in[field] ? document.querySelector(C.in[field]) : null;
        return el && el.value ? el.value : '';
      } catch (e) { return ''; }
    }

    function closest(el) {
      while (el && el.nodeType === 1) {
        try { if ((el.matches || el.msMatchesSelector || el.webkitMatchesSelector).call(el, C.sel)) { return el; } } catch (e) { return null; }
        el = el.parentNode;
      }
      return null;
    }

    function bookingClick(event) {
      try {
        if (!C.sel) { return; }
        var el = closest(event.target);
        if (!el) { return; }
        var query = parseQuery(el.getAttribute && (el.getAttribute('href') || el.getAttribute('data-href')) || '');
        var checkin = toIso(fromLink(query, 'checkin')) || toIso(fromInput('checkin'));
        var checkout = toIso(fromLink(query, 'checkout')) || toIso(fromInput('checkout'));
        var adults = toCount(fromLink(query, 'adults')) || toCount(fromInput('adults'));
        var children = toCount(fromLink(query, 'children'));
        if (children === '') { children = toCount(fromInput('children')); }

        var payload = { t: 'booking_click', path: path() };
        var params = { page_path: path() };
        if (checkin) { payload.ci = checkin; params.check_in_date = checkin; }
        if (checkout) { payload.co = checkout; params.check_out_date = checkout; }
        if (checkin && checkout) {
          var nights = Math.round((Date.parse(checkout) - Date.parse(checkin)) / 86400000);
          if (nights >= 1 && nights <= 90) { params.nights = nights; }
        }
        if (adults !== '') { payload.a = adults; params.adults = adults; }
        if (children !== '') { payload.c = children; params.children = children; }

        show('booking_click: ' + (checkin || '?') + ' to ' + (checkout || '?') + ', adults ' + (adults === '' ? '?' : adults) + ', children ' + (children === '' ? '?' : children));
        send(payload);
        ga('booking_click', params);
      } catch (e) {}
    }

    aiReferral();
    document.addEventListener('click', bookingClick, true);
    show('script loaded');
  } catch (e) {}
})();
JS;
}
