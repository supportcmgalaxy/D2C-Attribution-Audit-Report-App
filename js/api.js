/**
 * CMGalaxy - Mock API & Data Persistence Service Layer
 * Isolates backend endpoints for audit lifecycle, lead capture, email dispatch, and CRM sync.
 * Ready for drop-in replacement with real production endpoints.
 */

import { getStoredUTM } from './tracking.js';
import { analyzeAuditAnswers, evaluateAnswer } from './rulesEngine.js';
import { AUDIT_QUESTIONS } from './auditData.js';

const AUDIT_STORAGE_KEY = 'cmgalaxy_active_audit_session';
const LEADS_STORAGE_KEY = 'cmgalaxy_captured_leads';

/**
 * Generates a unique audit session ID.
 */
function generateId() {
  return 'aud_' + Math.random().toString(36).substring(2, 9) + Date.now().toString(36);
}

/**
 * Simulates network latency with configurable error probability (default 0%).
 */
function mockNetworkDelay(ms = 350) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Determines the target API endpoint URL with support for local file testing.
 */
function getApiEndpoint() {
  if (typeof window !== 'undefined') {
    if (window.location.protocol === 'file:' || window.location.hostname === 'localhost' || window.location.hostname === '127.0.0.1') {
      return 'https://d2c.cmgalaxy.com/send_email.php';
    }
  }
  return './send_email.php';
}

let cachedSiteKey = null;

async function getSiteKey() {
  if (cachedSiteKey !== null) return cachedSiteKey;
  if (typeof window !== 'undefined' && window.RECAPTCHA_SITE_KEY && window.RECAPTCHA_SITE_KEY !== 'your_recaptcha_site_key_here') {
    cachedSiteKey = window.RECAPTCHA_SITE_KEY;
    return cachedSiteKey;
  }
  try {
    const endpoint = getApiEndpoint();
    const configUrl = endpoint.includes('?') ? `${endpoint}&config=1` : `${endpoint}?config=1`;
    const res = await fetch(configUrl);
    const data = await res.json();
    cachedSiteKey = data.recaptchaSiteKey || '';
  } catch (e) {
    cachedSiteKey = '';
  }
  return cachedSiteKey;
}

/**
 * Generates an invisible Google reCAPTCHA v3 token.
 * Gracefully returns empty string if unconfigured or bypassed.
 */
export async function getRecaptchaToken(action = 'submitlead', timeoutMs = 6000) {
  try {
    let siteKey = (typeof window !== 'undefined' && window.RECAPTCHA_SITE_KEY) ? window.RECAPTCHA_SITE_KEY : null;
    
    if (!siteKey && typeof window !== 'undefined' && window.recaptchaReadyPromise) {
      siteKey = await window.recaptchaReadyPromise;
    }
    if (!siteKey) {
      siteKey = await getSiteKey();
    }
    if (!siteKey) {
      console.warn('[reCAPTCHA] Site key missing from .env');
      return '';
    }

    // Google reCAPTCHA v3 actions may only contain alphanumeric characters and slashes (NO underscores)
    const safeAction = (action || 'submitlead').replace(/[^a-zA-Z0-9\/]/g, '');

    // Ensure Google script is loading if not already in document
    if (typeof window.grecaptcha === 'undefined' && !document.querySelector('script[src*="recaptcha/api.js"]')) {
      const s = document.createElement('script');
      s.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(siteKey)}`;
      s.async = true;
      s.defer = true;
      document.head.appendChild(s);
    }

    // Wait for window.grecaptcha.execute to be ready
    const startTime = Date.now();
    while (Date.now() - startTime < timeoutMs) {
      if (typeof window.grecaptcha !== 'undefined' && typeof window.grecaptcha.execute === 'function') {
        break;
      }
      await new Promise((r) => setTimeout(r, 100));
    }

    if (typeof window.grecaptcha === 'undefined' || typeof window.grecaptcha.execute !== 'function') {
      console.warn('[reCAPTCHA] grecaptcha.execute not ready after wait');
      return '';
    }

    let token = '';
    try {
      token = await window.grecaptcha.execute(siteKey, { action: safeAction });
    } catch (execErr1) {
      try {
        token = await window.grecaptcha.execute({ action: safeAction });
      } catch (execErr2) {
        console.error('[reCAPTCHA execute failed]:', execErr1, execErr2);
      }
    }

    if (!token && typeof window.grecaptcha.ready === 'function') {
      token = await new Promise((resolve) => {
        const timer = setTimeout(() => resolve(''), 3000);
        window.grecaptcha.ready(async () => {
          try {
            const t = await window.grecaptcha.execute(siteKey, { action: safeAction });
            clearTimeout(timer);
            resolve(t || '');
          } catch (e) {
            clearTimeout(timer);
            resolve('');
          }
        });
      });
    }

    console.log('[reCAPTCHA] Token result:', token ? (token.substring(0, 10) + '... (' + token.length + ' chars)') : 'FAILED_EMPTY');
    return token || '';
  } catch (err) {
    console.error('[reCAPTCHA token error]:', err);
    return '';
  }
}

export const apiService = {
  /**
   * Initializes or restores an audit session.
   */
  async startAudit() {
    await mockNetworkDelay(150);

    let session = null;
    if (typeof localStorage !== 'undefined') {
      try {
        const stored = localStorage.getItem(AUDIT_STORAGE_KEY);
        if (stored) {
          const parsed = JSON.parse(stored);
          // Agar user ne form submit kar diya hai, toh agla attempt fresh hoga
          if (!parsed.completedAt && !parsed.email) {
            session = parsed;
          }
        }
      } catch (e) {
        // Silent fallback
      }
    }

    if (!session || !session.id) {
      const utm = getStoredUTM();
      session = {
        id: generateId(),
        createdAt: new Date().toISOString(),
        completedAt: null,
        name: '',
        email: '',
        company: '',
        answers: {}, // map of questionId => 'yes' | 'partly' | 'no' | 'dont_know'
        source: utm.referrer || 'direct',
        utmSource: utm.utmSource,
        utmMedium: utm.utmMedium,
        utmCampaign: utm.utmCampaign,
        utmContent: utm.utmContent,
        utmTerm: utm.utmTerm
      };
      this.saveSessionToStorage(session);
    }

    return { success: true, session };
  },

  /**
   * Saves or updates an answer for a specific question.
   */
  async saveAnswer(questionId, answer) {
    await mockNetworkDelay(50);
    const session = this.getSessionFromStorage();
    if (!session) return { success: false, error: 'No active session' };

    session.answers[questionId] = answer;
    session.lastUpdated = new Date().toISOString();
    this.saveSessionToStorage(session);

    return {
      success: true,
      questionId,
      answer,
      evalResult: evaluateAnswer(questionId, answer)
    };
  },

  /**
   * Retrieves active session from storage synchronously.
   */
  getSessionFromStorage() {
    if (typeof localStorage === 'undefined') {
      return this._mockMemorySession || null;
    }
    try {
      const stored = localStorage.getItem(AUDIT_STORAGE_KEY);
      return stored ? JSON.parse(stored) : (this._mockMemorySession || null);
    } catch (e) {
      return this._mockMemorySession || null;
    }
  },

  /**
   * Persists session to storage synchronously.
   */
  saveSessionToStorage(session) {
    this._mockMemorySession = session;
    if (typeof localStorage === 'undefined') return;
    try {
      localStorage.setItem(AUDIT_STORAGE_KEY, JSON.stringify(session));
    } catch (e) {
      // Silent fallback
    }
  },

  /**
   * Completes the audit questionnaire and prepares analysis.
   */
  async completeAudit() {
    await mockNetworkDelay(300);
    const session = this.getSessionFromStorage();
    if (!session) throw new Error('No active audit session found');

    const analysis = analyzeAuditAnswers(session.answers);
    session.completedAt = new Date().toISOString();
    session.analysis = analysis;
    this.saveSessionToStorage(session);

    return {
      success: true,
      sessionId: session.id,
      analysis
    };
  },

  /**
   * Captures the lead, attaches the full diagnostic data, and stores for CRM/Sales.
   */
  async createLead(leadData) {
    await mockNetworkDelay(600); // realistic feel for generation

    const session = this.getSessionFromStorage() || {};
    const utm = getStoredUTM();
    const analysis = session.analysis || analyzeAuditAnswers(session.answers || {});

    // Format all answers with their status and impact for sales
    const structuredAnswers = Object.entries(session.answers || {}).map(([qId, ans]) => {
      const qNum = Number(qId);
      const evaluated = evaluateAnswer(qNum, ans);
      const qData = AUDIT_QUESTIONS.find((q) => q.id === qNum);
      return {
        questionId: qNum,
        title: qData ? qData.title : `Question ${qNum}`,
        answer: ans,
        status: evaluated.status,
        budgetImpact: evaluated.budgetImpact
      };
    });

    const fullLeadRecord = {
      id: session.id || generateId(),
      createdAt: session.createdAt || new Date().toISOString(),
      submittedAt: new Date().toISOString(),
      lead_type: 'raw',
      is_enriched: false,
      status: 'new',
      lead_status: 'New',
      hs_lead_status: 'NEW',
      leadStatus: 'New',
      lifecyclestage: '4435114717',
      lifecycle_stage: '4435114717',
      phone: (leadData.phone || '').trim(),
      lead: {
        firstName: (leadData.firstName || '').trim(),
        lastName: (leadData.lastName || '').trim(),
        name: (leadData.name || '').trim(),
        phone: (leadData.phone || '').trim(),
        email: (leadData.email || '').trim().toLowerCase(),
        company: (leadData.company || '').trim(),
        lead_type: 'raw',
        is_enriched: false,
        status: 'new',
        lead_status: 'New',
        hs_lead_status: 'NEW',
        leadStatus: 'New',
        lifecyclestage: '4435114717',
        lifecycle_stage: '4435114717'
      },
      answers: structuredAnswers,
      analysis: {
        reliableCount: analysis.reliableCount,
        investigateCount: analysis.investigateCount,
        attributionGapCount: analysis.attributionGapCount,
        unknownCount: analysis.unknownCount,
        biggestFindingHeadline: (analysis.biggestFinding && analysis.biggestFinding.headline) || analysis.biggestFindingHeadline || '',
        biggestFindingSubtext: (analysis.biggestFinding && analysis.biggestFinding.subtext) || analysis.biggestFindingSubtext || '',
        sortedFindings: analysis.sortedFindings || []
      },
      marketingSource: {
        utmSource: utm.utmSource,
        utmMedium: utm.utmMedium,
        utmCampaign: utm.utmCampaign,
        utmContent: utm.utmContent,
        utmTerm: utm.utmTerm,
        referrer: utm.referrer,
        landingPage: utm.landingPage
      }
    };

    // Update active session
    session.firstName = leadData.firstName || '';
    session.lastName = leadData.lastName || '';
    session.name = leadData.name;
    session.phone = leadData.phone || '';
    session.email = leadData.email;
    session.company = leadData.company;
    this.saveSessionToStorage(session);

    // Persist to historical leads list for Sales inspectability
    try {
      const existingLeads = JSON.parse(localStorage.getItem(LEADS_STORAGE_KEY) || '[]');
      existingLeads.unshift(fullLeadRecord);
      localStorage.setItem(LEADS_STORAGE_KEY, JSON.stringify(existingLeads.slice(0, 20)));
    } catch (e) {}

    // Attach reCAPTCHA v3 token to lead payload
    const recaptchaToken = await getRecaptchaToken('submitlead');
    fullLeadRecord.recaptcha_token = recaptchaToken;

    // Dispatch email and Staging API sync in parallel
    const [emailRes] = await Promise.all([
      this.sendReportEmail(leadData.email, fullLeadRecord),
      this.createCMGalaxyUser(leadData, fullLeadRecord).catch(() => null)
    ]);

    if (emailRes && emailRes.success === false && (emailRes.limit_reached || (emailRes.error && emailRes.error.toLowerCase().includes('limit')))) {
      throw new Error(emailRes.error || 'Daily submission limit reached (2 reports per day).');
    }

    return {
      success: true,
      leadRecord: fullLeadRecord,
      analysis
    };
  },

  /**
   * Checks if client IP / user has reached daily submission limit (2 per day).
   */
  async checkSubmissionLimit(email = '') {
    const endpoint = getApiEndpoint();
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'check_limit',
          email: email
        })
      });
      return await response.json();
    } catch (err) {
      if (endpoint === './send_email.php') {
        try {
          const fbResp = await fetch('https://d2c.cmgalaxy.com/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'check_limit', email: email })
          });
          return await fbResp.json();
        } catch (fbErr) {}
      }
      return { success: true, allowed: true, limit_reached: false };
    }
  },

  /**
   * Dispatches 6-digit OTP verification email to user work email.
   */
  async sendOtp(email, name = '') {
    const endpoint = getApiEndpoint();
    const recaptchaToken = await getRecaptchaToken('sendotp');

    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'send_otp',
          email: email,
          name: name,
          recaptcha_token: recaptchaToken
        })
      });
      return await response.json();
    } catch (err) {
      if (endpoint === './send_email.php') {
        try {
          const fbResp = await fetch('https://d2c.cmgalaxy.com/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'send_otp', email, name, recaptcha_token: recaptchaToken })
          });
          return await fbResp.json();
        } catch (fbErr) {}
      }
      return { success: false, error: err.message || 'Network error sending code' };
    }
  },

  /**
   * Validates 6-digit OTP code against server.
   */
  async verifyOtp(email, otp) {
    const endpoint = getApiEndpoint();
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          action: 'verify_otp',
          email: email,
          otp: otp
        })
      });
      return await response.json();
    } catch (err) {
      if (endpoint === './send_email.php') {
        try {
          const fbResp = await fetch('https://d2c.cmgalaxy.com/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'verify_otp', email, otp })
          });
          return await fbResp.json();
        } catch (fbErr) {}
      }
      return { success: false, error: err.message || 'Network error verifying code' };
    }
  },

  /**
   * Dispatches transactional report email to User & Admin via SMTP.
   */
  async sendReportEmail(email, reportData) {
    const endpoint = getApiEndpoint();
    try {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(reportData),
        keepalive: true
      });
      const text = await response.text();
      let result = null;
      try {
        result = JSON.parse(text);
      } catch (parseErr) {
        return { success: false, error: 'Non-JSON server response' };
      }
      return result;
    } catch (err) {
      if (endpoint === './send_email.php') {
        try {
          const fbResp = await fetch('https://d2c.cmgalaxy.com/send_email.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(reportData),
            keepalive: true
          });
          return await fbResp.json();
        } catch (fbErr) {}
      }
      return { success: false, error: err.message };
    }
  },


  /**
   * Syncs lead to CMGalaxy Staging Lead Reporting API.
   * Dispatches direct browser network request so it shows in Network tab.
   */
  async createCMGalaxyUser(userData, leadRecord) {
    const STAGING_URL = 'https://staging-api.cmgalaxy.com/api/v2/lead_reporting/d_two_c_attribution_lead/';
    const API_KEY = 'cmg_d2c_lead_8a39f1c7e42d65b09812af43';

    try {
      const response = await fetch(STAGING_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': API_KEY
        },
        body: JSON.stringify(leadRecord),
        keepalive: true
      });
      const data = await response.json();
      return { success: response.ok, data };
    } catch (err) {
      return { success: false, error: err.message };
    }
  },

  /**
   * Resets active audit session (for testing / restart).
   */
  resetAudit() {
    try {
      this._mockMemorySession = null;
      if (typeof localStorage !== 'undefined') {
        localStorage.removeItem(AUDIT_STORAGE_KEY);
      }
    } catch (e) {}
  }
};
