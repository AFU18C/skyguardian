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

const publicRanges = new Set(['unicast']);
const dnsCache = new Map();
const maxProxyBytes = 15 * 1024 * 1024;

const normalizedHostname = (value) => value.toLowerCase().replace(/^\[|\]$/g, '').replace(/\.$/, '');

const isPublicIp = (value) => {
    try {
        let address = ipaddr.parse(value);
        if (address.kind() === 'ipv6' && address.isIPv4MappedAddress()) {
            address = address.toIPv4Address();
        }
        return publicRanges.has(address.range());
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
        if (!addresses.length) throw new Error('Не удалось определить IP-адрес сайта.');
        if (addresses.some((address) => !isPublicIp(address))) {
            throw new Error('Адрес сайта ведёт во внутреннюю или служебную сеть.');
        }
        return [...new Set(addresses)];
    })();

    dnsCache.set(host, lookup);
    return lookup;
};

const assertPublicUrl = async (value) => {
    let url;
    try {
        url = new URL(value);
    } catch {
        throw new Error('Некорректный адрес сайта.');
    }
    if (!['http:', 'https:'].includes(url.protocol)) {
        throw new Error('Разрешены только HTTP и HTTPS адреса.');
    }
    if (url.username || url.password) {
        throw new Error('Адреса со встроенными учётными данными запрещены.');
    }
    const port = Number(url.port || (url.protocol === 'https:' ? 443 : 80));
    if (![80, 443].includes(port)) {
        throw new Error('Разрешены только стандартные веб-порты 80 и 443.');
    }
    const host = normalizedHostname(url.hostname);
    if (!host || host === 'localhost' || host.endsWith('.localhost') || host.endsWith('.local')) {
        throw new Error('Локальные адреса запрещены.');
    }
    await resolveHost(host);
    return url;
};

const startPinnedProxy = async () => {
    const proxy = http.createServer(async (request, response) => {
        try {
            const target = await assertPublicUrl(request.url);
            const addresses = await resolveHost(normalizedHostname(target.hostname));
            const headers = { ...request.headers, host: target.host };
            delete headers['proxy-connection'];

            const upstream = http.request({
                host: addresses[0],
                port: Number(target.port) || 80,
                method: request.method,
                path: `${target.pathname}${target.search}`,
                headers,
            }, (upstreamResponse) => {
                const declaredLength = Number(upstreamResponse.headers['content-length'] || 0);
                if (declaredLength > maxProxyBytes) {
                    response.writeHead(413);
                    response.end();
                    upstreamResponse.destroy();
                    return;
                }

                let transferred = 0;
                upstreamResponse.on('data', (chunk) => {
                    transferred += chunk.length;
                    if (transferred > maxProxyBytes) {
                        upstreamResponse.destroy();
                        response.destroy();
                    }
                });
                response.writeHead(upstreamResponse.statusCode || 502, upstreamResponse.headers);
                upstreamResponse.pipe(response);
            });
            upstream.on('error', () => {
                if (!response.headersSent) response.writeHead(502);
                response.end();
            });
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
            if (![80, 443].includes(port) || target.username || target.password) {
                throw new Error('Blocked proxy destination');
            }
            const addresses = await resolveHost(normalizedHostname(target.hostname));
            if (addresses.some((address) => !isPublicIp(address))) throw new Error('Blocked address');

            const upstream = net.connect({
                host: addresses[0],
                port,
            });
            upstream.once('connect', () => {
                clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n');
                if (head.length) upstream.write(head);
                upstream.pipe(clientSocket);
                clientSocket.pipe(upstream);
            });
            let transferred = head.length;
            const enforceLimit = (chunk) => {
                transferred += chunk.length;
                if (transferred > maxProxyBytes) {
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
    if (!address || typeof address === 'string') throw new Error('Не удалось запустить защищённый браузерный прокси.');

    return { proxy, url: `http://127.0.0.1:${address.port}` };
};

const normalizeDate = (value) => {
    if (typeof value !== 'string' || !value.trim()) return null;
    const timestamp = Date.parse(value.trim());
    return Number.isFinite(timestamp) ? new Date(timestamp).toISOString() : null;
};

const collectJsonLdDates = (value, dates = []) => {
    if (Array.isArray(value)) {
        value.forEach((item) => collectJsonLdDates(item, dates));
        return dates;
    }
    if (!value || typeof value !== 'object') return dates;
    for (const [key, item] of Object.entries(value)) {
        if (['datePublished', 'dateCreated', 'uploadDate'].includes(key)) {
            const normalized = normalizeDate(item);
            if (normalized) dates.push(normalized);
        }
        collectJsonLdDates(item, dates);
    }
    return dates;
};

const extractDocument = async (page, source) => {
    const data = await page.evaluate(() => {
        const meta = (selector) => document.querySelector(selector)?.getAttribute('content')?.trim() || null;
        const jsonLd = [...document.querySelectorAll('script[type="application/ld+json"]')]
            .map((node) => node.textContent || '')
            .filter(Boolean);
        const timeValues = [...document.querySelectorAll('time[datetime]')]
            .map((node) => node.getAttribute('datetime'))
            .filter(Boolean);
        return {
            title: document.title || null,
            canonical: document.querySelector('link[rel="canonical"]')?.href || null,
            text: (document.body?.innerText || '').slice(0, 750000),
            publishedPrimary: [
                meta('meta[property="article:published_time"]'),
                meta('meta[name="pubdate"]'),
                meta('meta[itemprop="datePublished"]'),
                meta('meta[name="date"]'),
            ].filter(Boolean),
            publishedSecondary: [
                ...timeValues,
            ].filter(Boolean),
            jsonLd,
        };
    });

    const primaryDate = data.publishedPrimary.map(normalizeDate).find(Boolean) || null;
    const dates = data.publishedSecondary.map(normalizeDate).filter(Boolean);
    for (const raw of data.jsonLd) {
        try {
            collectJsonLdDates(JSON.parse(raw), dates);
        } catch {
            // Invalid third-party JSON-LD must not abort the whole source.
        }
    }

    return {
        name: String(source.name || new URL(source.url).hostname),
        requested_url: source.url,
        final_url: page.url(),
        canonical_url: data.canonical,
        title: data.title,
        text: data.text,
        published_at: primaryDate || ([...new Set(dates)].length === 1 ? dates[0] : null),
        fetched_at: new Date().toISOString(),
    };
};

const browseSource = async (browser, source, timeout) => {
    await assertPublicUrl(source.url);
    const context = await browser.newContext({
        acceptDownloads: false,
        javaScriptEnabled: true,
        serviceWorkers: 'block',
        locale: 'ru-RU',
        userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/140 Safari/537.36 SkyGuardian/1.0',
    });
    const page = await context.newPage();
    page.setDefaultNavigationTimeout(timeout);
    page.setDefaultTimeout(timeout);

    await page.route('**/*', async (route) => {
        if (['image', 'media', 'font'].includes(route.request().resourceType())) {
            await route.abort('blockedbyclient');
            return;
        }
        const requestUrl = route.request().url();
        if (requestUrl.startsWith('data:') || requestUrl.startsWith('blob:')) {
            await route.continue();
            return;
        }
        try {
            await assertPublicUrl(requestUrl);
            await route.continue();
        } catch {
            await route.abort('blockedbyclient');
        }
    });

    try {
        const response = await page.goto(source.url, { waitUntil: 'domcontentloaded', timeout });
        if (!response) throw new Error('Сайт не вернул ответ.');
        if (response.status() >= 400) throw new Error(`HTTP ${response.status()}`);
        await assertPublicUrl(page.url());
        await page.waitForTimeout(Math.min(1500, Math.max(250, Math.floor(timeout / 8))));
        return await extractDocument(page, source);
    } finally {
        await context.close();
    }
};

const run = async () => {
    const input = await readInput();
    const sources = (Array.isArray(input.sources) ? input.sources : [])
        .filter((source) => source && source.enabled !== false && typeof source.url === 'string');
    const concurrency = Math.max(1, Math.min(5, Number(input.concurrency) || 3));
    const timeout = Math.max(3000, Math.min(30000, Number(input.navigation_timeout_ms) || 12000));
    const documents = [];
    const errors = [];
    const pinnedProxy = await startPinnedProxy();
    let browser;
    let cursor = 0;

    const worker = async () => {
        while (cursor < sources.length) {
            const source = sources[cursor++];
            try {
                documents.push(await browseSource(browser, source, timeout));
            } catch (error) {
                errors.push({
                    source: String(source.name || source.url),
                    error: String(error?.message || error).slice(0, 500),
                });
            }
        }
    };

    try {
        browser = await chromium.launch({
            headless: true,
            proxy: { server: pinnedProxy.url },
            args: ['--disable-dev-shm-usage'],
        });
        await Promise.all(Array.from({ length: Math.min(concurrency, sources.length || 1) }, worker));
    } finally {
        if (browser) await browser.close();
        await new Promise((resolve) => pinnedProxy.proxy.close(resolve));
    }

    process.stdout.write(JSON.stringify({ documents, errors }));
};

run().catch((error) => {
    process.stderr.write(String(error?.stack || error));
    process.exitCode = 1;
});
