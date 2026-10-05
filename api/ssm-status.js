const cheerio = require('cheerio');

const SSM_URL = 'https://www.ssm.com.my/Pages/e-Search.aspx';
const CANARY_NUMBER = '201903123456';
const CANARY_OLD_NUMBER = 'AS0402639-H';

const REGISTRATION_TYPES = [
  { key: 'robNew', name: 'Business Registration Number New' },
  { key: 'rob', name: 'Business Registration Number Old' },
];

function normalizeNumber(value) {
  const cleaned = String(value ?? '').trim().toUpperCase();
  if (/^\d{12}$/.test(cleaned)) return cleaned;
  const match = cleaned.match(/^([A-Z0-9]{9})(?:-[A-Z]?)?$/);
  if (!match) return '';
  const base = match[1];
  if (/^\d{9}$/.test(base) || /^[A-Z]\d{8}$/.test(base) || /^[A-Z]{2}\d{7}$/.test(base)) return base;
  return '';
}

function cookieHeaderFromResponse(response) {
  if (typeof response.headers.getSetCookie === 'function') {
    return response.headers.getSetCookie().map(v => v.split(';', 1)[0]).join('; ');
  }
  const raw = response.headers.get('set-cookie');
  if (!raw) return '';
  return raw
    .split(/,(?=\s*[^;,=]+=[^;,]+)/g)
    .map(v => v.trim().split(';', 1)[0])
    .join('; ');
}

async function fetchWithTimeout(url, options = {}, timeoutMs = 20000) {
  const controller = new AbortController();
  const timer = setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { ...options, signal: controller.signal, redirect: 'follow' });
  } finally {
    clearTimeout(timer);
  }
}

function unavailable(reason, httpStatus = 0) {
  return { exists: false, unavailable: true, reason: String(reason || 'SSM verification unavailable.'), http_status: Number(httpStatus || 0) };
}

async function rawLookup(number) {
  let initial;
  try {
    initial = await fetchWithTimeout(SSM_URL, {
      method: 'GET',
      headers: {
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129 Safari/537.36',
        'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language': 'en-US,en;q=0.9',
      },
    });
  } catch (error) {
    return unavailable(error?.name === 'AbortError' ? 'SSM connection timed out.' : error?.message);
  }

  const initialStatus = initial.status;
  const html = await initial.text();
  if (!initial.ok) return unavailable(`SSM returned HTTP ${initialStatus}.`, initialStatus);
  if (!html.trim()) return unavailable('SSM returned an empty response.', initialStatus);

  const $ = cheerio.load(html);
  const viewState = $('input[name="__VIEWSTATE"]').attr('value') || '';
  const eventValidation = $('input[name="__EVENTVALIDATION"]').attr('value') || '';
  const viewStateGenerator = $('input[name="__VIEWSTATEGENERATOR"]').attr('value') || '';
  if (!viewState || !eventValidation || !viewStateGenerator) {
    return unavailable('The expected SSM hidden fields were not found.', initialStatus);
  }

  const cookie = cookieHeaderFromResponse(initial);
  let confirmedNotFoundCount = 0;
  let lastStatus = initialStatus;

  for (const type of REGISTRATION_TYPES) {
    const params = new URLSearchParams();
    params.set('__VIEWSTATE', viewState);
    params.set('__EVENTVALIDATION', eventValidation);
    params.set('__VIEWSTATEGENERATOR', viewStateGenerator);
    params.set('ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$idDropRegistrationType', type.key);
    params.set('ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$txtCarieSearch', number);
    params.set('ctl00$ctl36$g_2e791db6_58e3_41f2_a6f4_9c226eefabbc$ctl00$idBtneSearch', 'Search Now');

    let response;
    try {
      response = await fetchWithTimeout(SSM_URL, {
        method: 'POST',
        headers: {
          'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129 Safari/537.36',
          'Accept': 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
          'Accept-Language': 'en-US,en;q=0.9',
          'Content-Type': 'application/x-www-form-urlencoded',
          'Referer': SSM_URL,
          ...(cookie ? { 'Cookie': cookie } : {}),
        },
        body: params.toString(),
      });
    } catch (error) {
      return unavailable(error?.name === 'AbortError' ? 'SSM validation request timed out.' : error?.message);
    }

    lastStatus = response.status;
    const postHtml = await response.text();
    if (!response.ok) return unavailable(`SSM validation returned HTTP ${response.status}.`, response.status);
    if (!postHtml.trim()) return unavailable('SSM returned an empty validation response.', response.status);

    const $$ = cheerio.load(postHtml);
    if ($$('tr.alert-info').length > 0) {
      confirmedNotFoundCount++;
      continue;
    }

    const rows = $$('table[id*="GridViewRob"] tr');
    if (rows.length < 2) return unavailable('The SSM validation response structure was unexpected.', response.status);
    const cols = $$(rows.eq(1)).find('td');
    if (cols.length < 5) return unavailable('The expected SSM business fields were missing.', response.status);

    const textAt = i => $$(cols.eq(i)).text().replace(/\s+/g, ' ').trim();
    return {
      exists: true,
      unavailable: false,
      type: type.name,
      data: {
        registrationNumber: textAt(0),
        newRegistrationNumber: textAt(1),
        entityName: textAt(2),
        status: textAt(3),
        gstNumber: textAt(4) || null,
      },
      http_status: response.status,
    };
  }

  if (confirmedNotFoundCount === REGISTRATION_TYPES.length) {
    return { exists: false, unavailable: false, http_status: lastStatus };
  }
  return unavailable('SSM verification returned an unexpected result.', lastStatus);
}

function isVerified(result) {
  return !!result && result.exists === true && result.unavailable !== true && result.data && typeof result.data === 'object';
}
function isNotFound(result) {
  return !!result && result.exists === false && result.unavailable === false;
}
function hasOldRegistration(result) {
  return isVerified(result) && String(result.data.registrationNumber || '').trim() !== '';
}
function isCanaryHealthy(result) {
  return isVerified(result) &&
    String(result.data.newRegistrationNumber || '').trim() === CANARY_NUMBER &&
    String(result.data.registrationNumber || '').trim() === CANARY_OLD_NUMBER;
}
function verifiedResponse(result) {
  return { mode: 'verified', health: 'healthy', exists: true, unavailable: false, verified: true, verification_marker: 'Verified', data: result.data, message: '' };
}
function notFoundResponse() {
  return { mode: 'not_found', health: 'healthy', exists: false, unavailable: false, verified: false, verification_marker: '', data: null, message: "Registration Number doesn't exist, please make sure it is NON SDN BHD." };
}
function fallbackResponse(health, result = null) {
  const data = isVerified(result) ? { registrationNumber: String(result.data.registrationNumber || '').trim() } : null;
  return { mode: 'fallback', health: health === 'offline' ? 'offline' : 'healthy', exists: false, unavailable: true, verified: false, verification_marker: 'Unverified', data, message: 'SSM verification is temporarily unavailable.' };
}

async function orchestrateLookup(number) {
  const userResult = await rawLookup(number);
  if (isVerified(userResult)) return { response: verifiedResponse(userResult) };

  const canaryResult = await rawLookup(CANARY_NUMBER);
  if (!isCanaryHealthy(canaryResult)) return { response: fallbackResponse('offline', userResult) };
  if (isNotFound(userResult)) return { response: notFoundResponse() };

  const retryResult = await rawLookup(number);
  if (isVerified(retryResult)) return { response: verifiedResponse(retryResult) };
  if (isNotFound(retryResult)) return { response: notFoundResponse() };
  return { response: fallbackResponse('healthy', retryResult) };
}

module.exports = async function handler(req, res) {
  res.setHeader('Content-Type', 'application/json; charset=utf-8');
  res.setHeader('Cache-Control', 'no-store, private');
  res.setHeader('X-Content-Type-Options', 'nosniff');

  if (req.method !== 'POST') return res.status(405).json({ kind: 'unavailable' });

  const contentType = String(req.headers['content-type'] || '').toLowerCase();
  if (!contentType.startsWith('application/json')) return res.status(415).json({ kind: 'unavailable' });

  const origin = req.headers.origin;
  if (origin) {
    try {
      const originHost = new URL(origin).host;
      const requestHost = String(req.headers.host || '');
      if (!requestHost || originHost.toLowerCase() !== requestHost.toLowerCase()) return res.status(403).json({ kind: 'unavailable' });
    } catch {
      return res.status(403).json({ kind: 'unavailable' });
    }
  }

  let body = req.body;
  if (typeof body === 'string') {
    try { body = JSON.parse(body); } catch { body = null; }
  }
  if (!body || typeof body.number !== 'string') return res.status(400).json({ kind: 'validation_error' });

  const number = normalizeNumber(body.number);
  if (!number) return res.status(400).json({ kind: 'validation_error', message: 'Please enter a valid registration number format.' });

  try {
    const outcome = await orchestrateLookup(number);
    const result = outcome.response || {};
    if (result.mode === 'not_found') return res.status(200).json({ kind: 'not_found' });
    if (result.mode !== 'verified' || !result.data) return res.status(503).json({ kind: 'unavailable' });

    const data = result.data;
    const sourceStatus = String(data.status || '').trim();
    let kind = 'status_unknown';
    if (/\bEXPIRED\b/i.test(sourceStatus)) kind = 'expired_unknown';
    else if (/\bACTIVE\b/i.test(sourceStatus) && !/\bINACTIVE\b/i.test(sourceStatus)) kind = 'active';

    return res.status(200).json({
      kind,
      businessName: String(data.entityName || '').trim(),
      newRegistrationNumber: String(data.newRegistrationNumber || '').trim(),
      oldRegistrationNumber: String(data.registrationNumber || '').trim(),
      registrationNumber: String(data.newRegistrationNumber || data.registrationNumber || number).trim(),
      sourceStatus,
    });
  } catch (error) {
    console.error('[SSM Jelas] Lookup failed:', error);
    return res.status(503).json({ kind: 'unavailable' });
  }
};
