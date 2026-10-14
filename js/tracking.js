/**
 * CMGalaxy - Tracking & Analytics Layer
 * Handles UTM extraction, session persistence, and GTM event dispatching (non-PII).
 */

const UTM_STORAGE_KEY = 'cmgalaxy_audit_utm';
const EVENT_LOG_KEY = 'cmgalaxy_audit_event_log';

/**
 * Parses UTM and referrer parameters from the current URL and browser environment.
 */
export function initUTMTracking() {
  // Check if we already have saved UTMs from this session
  let saved = null;
  if (typeof sessionStorage !== 'undefined') {
    try {
      const raw = sessionStorage.getItem(UTM_STORAGE_KEY);
      if (raw) saved = JSON.parse(raw);
    } catch (e) {
      // silent fail
    }
  }

  const search = typeof window !== 'undefined' && window.location ? window.location.search : '';
  const urlParams = new URLSearchParams(search);
  const docRef = typeof document !== 'undefined' ? document.referrer : '';
  const pageUrl = typeof window !== 'undefined' && window.location ? window.location.href : '';

  const current = {
    utmSource: urlParams.get('utm_source') || (saved && saved.utmSource) || 'direct',
    utmMedium: urlParams.get('utm_medium') || (saved && saved.utmMedium) || 'none',
    utmCampaign: urlParams.get('utm_campaign') || (saved && saved.utmCampaign) || '',
    utmContent: urlParams.get('utm_content') || (saved && saved.utmContent) || '',
    utmTerm: urlParams.get('utm_term') || (saved && saved.utmTerm) || '',
    referrer: docRef || (saved && saved.referrer) || 'direct',
    landingPage: pageUrl
  };

  if (typeof sessionStorage !== 'undefined') {
    try {
      sessionStorage.setItem(UTM_STORAGE_KEY, JSON.stringify(current));
    } catch (e) {
      // silent fail
    }
  }

  return current;
}

export function getStoredUTM() {
  try {
    const raw = sessionStorage.getItem(UTM_STORAGE_KEY);
    if (raw) return JSON.parse(raw);
  } catch (e) {}
  return initUTMTracking();
}

/**
 * Dispatches a standard GTM / GA4 event via window.dataLayer.
 * Enforces NO-PII policy.
 */
export function trackEvent(eventName, payload = {}) {
  // Initialize dataLayer if missing
  window.dataLayer = window.dataLayer || [];

  // Sanitize to remove accidental PII (emails, names, phone numbers)
  const sanitized = { ...payload };
  delete sanitized.email;
  delete sanitized.name;
  delete sanitized.fullName;
  delete sanitized.phone;
  delete sanitized.company;
  delete sanitized.auditSummary;

  const eventData = {
    event: eventName,
    timestamp: new Date().toISOString(),
    ...sanitized
  };

  // Push to GTM dataLayer
  window.dataLayer.push(eventData);

  // Keep a debug log in window for development / QA verification
  window.__cmgEvents = window.__cmgEvents || [];
  window.__cmgEvents.push(eventData);
}
