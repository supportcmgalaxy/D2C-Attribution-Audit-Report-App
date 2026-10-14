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
        console.warn('LocalStorage error', e);
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
      console.warn('LocalStorage write failed', e);
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

    // Dispatch email and Staging API sync in parallel
    await Promise.allSettled([
      this.sendReportEmail(leadData.email, fullLeadRecord),
      this.createCMGalaxyUser(leadData, fullLeadRecord)
    ]);

    return {
      success: true,
      leadRecord: fullLeadRecord,
      analysis
    };
  },

  /**
   * Dispatches transactional report email to User & Admin via SMTP.
   */
  async sendReportEmail(email, reportData) {
    console.group('✉️ [CRM / Email Dispatch] -> send_email.php');
    console.log('📦 Data sent to backend & Server CRM:', reportData);
    console.groupEnd();

    try {
      const response = await fetch('./send_email.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json'
        },
        body: JSON.stringify(reportData)
      });
      const text = await response.text();
      let result = null;
      try {
        result = JSON.parse(text);
      } catch (parseErr) {
        console.warn('⚠️ Non-JSON server response:', text);
        return { success: false, error: 'Non-JSON server response' };
      }
      console.log('📬 [send_email.php Response (Includes Server CRM Sync)]:', result);
      return result;
    } catch (err) {
      console.warn('⚠️ [send_email.php Network Error]:', err.message);
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

    console.group('🚀 [CRM Direct Browser Sync] -> CMGalaxy Staging API');
    console.log('📡 Endpoint:', STAGING_URL);
    console.log('📦 Exact CRM Payload:', leadRecord);
    console.groupEnd();

    try {
      const response = await fetch(STAGING_URL, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-API-Key': API_KEY
        },
        body: JSON.stringify(leadRecord)
      });
      const data = await response.json();
      console.log('✅ [CRM Staging API Direct Response]:', data);
      return { success: response.ok, data };
    } catch (err) {
      console.warn('⚠️ [CRM Staging API Direct Error]:', err.message);
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
