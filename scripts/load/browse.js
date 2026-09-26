// A visitor browsing the public site — the read pages a WhatsApp share or a
// radio mention sends people to. Run against STAGING with the demo data:
//
//   k6 run --vus 20 --duration 60s -e BASE_URL=https://staging.example.org scripts/load/browse.js
//
// Shared hosting is limited by concurrent PHP processes, not requests per
// second; 20 virtual users with think time is a busy hour for this
// foundation, and the point is to confirm the host never queues, not to
// find its ceiling. docs/PHASE-14-QA.md §5 says what to read afterwards.

import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
    thresholds: {
        http_req_failed: ['rate<0.01'],
        http_req_duration: ['p(95)<800'],
    },
};

const base = (__ENV.BASE_URL || 'http://127.0.0.1:8000').replace(/\/$/, '');

const pages = [
    '/',
    '/donate',
    '/appeals',
    '/appeals/back-to-school-2026',
    '/projects',
    '/projects/bongo-school-kits',
    '/news',
    '/news/what-gh50-actually-pays-for',
    '/shop',
    '/shop/tote-bag',
    '/faq',
    '/contact',
    '/events',
];

export default function () {
    const path = pages[Math.floor(Math.random() * pages.length)];
    const res = http.get(base + path, { tags: { page: path } });

    check(res, {
        'status is 200': (r) => r.status === 200,
        'has a main landmark': (r) => r.body && r.body.includes('<main'),
    });

    // A person reads before they click.
    sleep(3 + Math.random() * 5);
}
