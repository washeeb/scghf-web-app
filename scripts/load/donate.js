// Ten people on the donate form at once, submitting to the FAKE gateway.
//
//   k6 run --vus 10 --duration 60s -e BASE_URL=http://127.0.0.1:8000 scripts/load/donate.js
//
// NEVER against an environment with Paystack keys: every submission would
// initialise a real (test-mode) transaction at rate, and Paystack's
// dashboard would fill with them. The script refuses unless the donate
// page is served with PAYMENT_DRIVER=fake (the sandbox route exists).
//
// The honeypot needs a few seconds between rendering the form and
// submitting it; a virtual user waits like a person does.

import http from 'k6/http';
import { check, sleep, fail } from 'k6';

export const options = {
    thresholds: {
        http_req_failed: ['rate<0.01'],
        'http_req_duration{step:submit}': ['p(95)<1500'],
    },
};

const base = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');

export function setup() {
    const probe = http.get(base + '/payments/fake/probe');

    // 404 = route exists, no such reference (fake driver, non-production).
    // Anything else means the sandbox route is not registered: stop.
    if (probe.status !== 404) {
        fail('The fake gateway is not available on ' + base + ' — refusing to load-test a real one.');
    }
}

function field(html, name) {
    const m = html.match(new RegExp('name="' + name + '"[^>]*value="([^"]*)"'));

    return m ? m[1] : '';
}

export default function () {
    const form = http.get(base + '/donate', { tags: { step: 'form' } });
    check(form, { 'form loads': (r) => r.status === 200 });

    const html = form.body || '';
    const token = field(html, '_token');

    // spatie/laravel-honeypot renders its two fields inside a display:none
    // box: the name field (left empty, whatever it is called this time) and
    // the encrypted "valid from" timestamp, copied as a browser would.
    const honeypot = {};
    const box = html.match(/<div[^>]*display:\s*none[^>]*>([\s\S]*?)<\/div>/i);
    if (box) {
        const inputs = box[1].matchAll(/<input[^>]*name="([^"]+)"[^>]*?(?:value="([^"]*)")?[^>]*>/g);
        for (const m of inputs) honeypot[m[1]] = m[2] || '';
    }

    sleep(4 + Math.random() * 3);

    const res = http.post(base + '/donate', Object.assign({
        _token: token,
        amount: '50.00',
        donor_name: 'Load Test ' + __VU,
        donor_email: 'load' + __VU + '-' + __ITER + '@example.test',
        donor_phone: '024' + String(1000000 + __VU * 100 + (__ITER % 100)).slice(-7),
        consent: '1',
        pay_with: 'gateway',
    }, honeypot), { tags: { step: 'submit' }, redirects: 3 });

    check(res, {
        'lands on the sandbox': (r) => r.url.includes('/payments/fake/'),
    });

    sleep(2);
}
