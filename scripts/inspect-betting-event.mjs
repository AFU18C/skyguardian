import dns from 'node:dns/promises';
import http from 'node:http';
import net from 'node:net';
import process from 'node:process';
import { chromium } from 'playwright';
import ipaddr from 'ipaddr.js';

const readInput = async () => {
    const chunks = [];
    for await (const chunk of process.stdin) chunks.push(chunk);
    return JSON.parse(Buffer.concat(chunks).toString('utf8'));
};

const dnsCache = new Map();
const maximumTransferBytes = 20 * 1024 * 1024;
const maximumOutputBytes = 2 * 1024 * 1024;

const normalizeHost = (value) => value.toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');

const isPublicIp = (value) => {
    try {
        let address = ipaddr.parse(value);
        if (address.kind() === 'ipv6' && address.isIPv4MappedAddress()) address = address.toIPv4Address();
        return address.range() === 'unicast';
    } catch {
        return false;
    }
};

const resolveHost = async (host) => {
    if (net.isIP(host)) return [host];
    if (dnsCache.has(host)) return dnsCache.get(host);

    const lookup = (async () => {
        const [v4, v6] = await Promise.allSettled([dns.resolve4(host), dns.resolve6(host)]);
        const addresses = [
            ...(v4.status === 'fulfilled' ? v4.value : []),
            ...(v6.status === 'fulfilled' ? v6.value : []),
        ];
        if (!addresses.length) throw new Error('Не удалось определить IP-адрес BETON.');
        if (addresses.some((address) => !isPublicIp(address))) {
            throw new Error('Сайт перенаправил запрос во внутреннюю сеть.');
        }
        return [...new Set(addresses)];
    })();

    dnsCache.set(host, lookup);
    return lookup;
};

const assertPublicUrl = async (value) => {
    const url = new URL(value);
    if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        throw new Error('Разрешены только публичные HTTP/HTTPS адреса.');
    }
    const port = Number(url.port || (url.protocol === 'https:' ? 443 : 80));
    if (![80, 443].includes(port)) throw new Error('Нестандартный сетевой порт запрещён.');
    const host = normalizeHost(url.hostname);
    if (!host || host === 'localhost' || host.endsWith('.local') || host.endsWith('.localhost')) {
        throw new Error('Локальные адреса запрещены.');
    }
    await resolveHost(host);
    return url;
};

const startPinnedProxy = async () => {
    const proxy = http.createServer(async (request, response) => {
        try {
            const target = await assertPublicUrl(request.url);
            const addresses = await resolveHost(normalizeHost(target.hostname));
            const headers = { ...request.headers, host: target.host };
            delete headers['proxy-connection'];
            const upstream = http.request({
                host: addresses[0],
                port: Number(target.port) || 80,
                method: request.method,
                path: `${target.pathname}${target.search}`,
                headers,
            }, (upstreamResponse) => {
                let transferred = 0;
                upstreamResponse.on('data', (chunk) => {
                    transferred += chunk.length;
                    if (transferred > maximumTransferBytes) {
                        upstreamResponse.destroy();
                        response.destroy();
                    }
                });
                response.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
                upstreamResponse.pipe(response);
            });
            upstream.on('error', () => response.end());
            request.pipe(upstream);
        } catch {
            response.writeHead(403);
            response.end();
        }
    });

    proxy.on('connect', async (request, clientSocket, head) => {
        try {
            const target = new URL(`http://${request.url}`);
            const port = Number(target.port) || 443;
            if (![80, 443].includes(port)) throw new Error('Blocked port');
            const addresses = await resolveHost(normalizeHost(target.hostname));
            const upstream = net.connect({ host: addresses[0], port });
            upstream.once('connect', () => {
                clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
                if (head.length) upstream.write(head);
                upstream.pipe(clientSocket);
                clientSocket.pipe(upstream);
            });
            let transferred = head.length;
            const enforceLimit = (chunk) => {
                transferred += chunk.length;
                if (transferred > maximumTransferBytes) {
                    upstream.destroy();
                    clientSocket.destroy();
                }
            };
            upstream.on('data', enforceLimit);
            clientSocket.on('data', enforceLimit);
            upstream.once('error', () => clientSocket.destroy());
            clientSocket.once('error', () => upstream.destroy());
        } catch {
            clientSocket.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
        }
    });

    await new Promise((resolve, reject) => {
        proxy.once('error', reject);
        proxy.listen(0, '127.0.0.1', resolve);
    });
    const address = proxy.address();
    if (!address || typeof address === 'string') throw new Error('Не удалось запустить браузерный прокси.');
    return { proxy, url: `http://127.0.0.1:${address.port}` };
};

const frameText = async (page) => {
    const values = [];
    for (const frame of page.frames()) {
        try {
            const text = await frame.locator('body').innerText({ timeout: 1500 });
            if (text.trim()) values.push(text.trim());
        } catch {
            // Cross-origin or detached frames are allowed to disappear while the line loads.
        }
    }
    return values;
};

const trySportsSearch = async (page, queries) => {
    for (const frame of page.frames()) {
        let inputs;
        try {
            inputs = frame.locator('input:not([type="hidden"]):not([disabled])');
            const count = Math.min(await inputs.count(), 12);
            for (let index = 0; index < count; index++) {
                const input = inputs.nth(index);
                const hint = `${await input.getAttribute('placeholder') || ''} ${await input.getAttribute('aria-label') || ''}`.toLowerCase();
                if (!/(search|find|пошук|поиск|команд|поді|событ|event|team)/u.test(hint)) continue;
                for (const query of queries) {
                    try {
                        await input.fill(query, { timeout: 1500 });
                        await input.press('Enter').catch(() => {});
                        await page.waitForTimeout(1200);
                    } catch {
                        break;
                    }
                }
            }
        } catch {
            // Continue with data captured from the page and network.
        }
    }
};

const run = async () => {
    const input = await readInput();
    const target = await assertPublicUrl(String(input.url || ''));
    const timeout = Math.max(5000, Math.min(45000, Number(input.timeout_ms) || 20000));
    const home = String(input.home_team || '').trim();
    const away = String(input.away_team || '').trim();
    if (!home || !away) throw new Error('Не указаны обе команды события.');

    const proxy = await startPinnedProxy();
    let browser;
    const payloads = [];
    let status = null;

    try {
        browser = await chromium.launch({
            headless: true,
            proxy: { server: proxy.url },
            args: ['--disable-dev-shm-usage'],
        });
        const context = await browser.newContext({
            acceptDownloads: false,
            serviceWorkers: 'block',
            locale: 'uk-UA',
            userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36',
        });
        const page = await context.newPage();
        page.setDefaultTimeout(timeout);
        page.setDefaultNavigationTimeout(timeout);

        page.on('response', async (response) => {
            try {
                const contentType = (response.headers()['content-type'] || '').toLowerCase();
                if (!contentType.includes('json')) return;
                const body = await response.text();
                if (body.length <= 1_000_000) payloads.push(body);
            } catch {
                // Some streaming or redirected responses cannot be read.
            }
        });

        const response = await page.goto(target.toString(), { waitUntil: 'domcontentloaded', timeout });
        status = response?.status() || null;
        if (status !== null && status >= 400) throw new Error(`BETON вернул HTTP ${status}.`);
        await page.waitForTimeout(2500);
        await trySportsSearch(page, [`${home} ${away}`, home, away]);
        await page.waitForTimeout(1500);

        const text = (await frameText(page)).join('\n\n');
        const body = [text, ...payloads].join('\n').slice(0, maximumOutputBytes);
        process.stdout.write(JSON.stringify({
            body,
            http_status: status,
            final_url: page.url(),
        }));
        await context.close();
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve) => proxy.proxy.close(resolve));
    }
};

run().catch((error) => {
    process.stderr.write(String(error?.stack || error));
    process.exitCode = 1;
});
