/**
 * CMGalaxy - Interactive D2C Attribution Audit Application Controller
 */

import { AUDIT_QUESTIONS, AUDIT_CATEGORIES, ANSWER_OPTIONS } from './auditData.js';
import { analyzeAuditAnswers, evaluateAnswer } from './rulesEngine.js';
import { initUTMTracking, getStoredUTM, trackEvent } from './tracking.js';
import { apiService } from './api.js?v=2.4';

class CMGalaxyAuditApp {
  constructor() {
    this.currentQuestionIndex = 0;
    this.activeSession = null;
    this.activeAnalysis = null;
    this.currentFindingFilter = 'all';

    // DOM Elements
    this.views = {
      landing: document.getElementById('view-landing'),
      audit: document.getElementById('view-audit'),
      lead: document.getElementById('view-lead'),
      report: document.getElementById('view-report')
    };

    // Header buttons
    this.btnRestart = document.getElementById('btn-restart-audit');
    this.btnSalesInspector = document.getElementById('btn-sales-inspector');
    this.headerBrandLink = document.getElementById('header-brand-link');

    // Audit interface elements
    this.categoryNameEl = document.getElementById('audit-category-name');
    this.counterTextEl = document.getElementById('audit-counter-text');
    this.progressBarEl = document.getElementById('audit-progressbar');
    this.progressFillEl = document.getElementById('audit-progress-fill');
    this.questionTitleTagEl = document.getElementById('question-title-tag');
    this.questionTextEl = document.getElementById('question-text');
    this.answersContainerEl = document.getElementById('answers-container');
    this.btnAuditBack = document.getElementById('btn-audit-back');
    this.btnAuditNext = document.getElementById('btn-audit-next');

    // Lead capture elements
    this.leadForm = document.getElementById('lead-capture-form');
    this.inputName = document.getElementById('lead-fullname');
    this.inputEmail = document.getElementById('lead-email');
    this.inputCompany = document.getElementById('lead-company');
    this.btnSubmitLead = document.getElementById('btn-submit-lead');

    // Report elements
    this.reportUserMetaEl = document.getElementById('report-user-meta');
    this.countReliableEl = document.getElementById('count-reliable');
    this.countInvestigateEl = document.getElementById('count-investigate');
    this.countGapsEl = document.getElementById('count-gaps');
    this.countUnknownEl = document.getElementById('count-unknown');
    this.biggestFindingHeadlineEl = document.getElementById('biggest-finding-headline');
    this.biggestFindingSubtextEl = document.getElementById('biggest-finding-subtext');
    this.findingsContainerEl = document.getElementById('findings-container');
    this.btnPrintReport = document.getElementById('btn-print-report');

    // Modals / Drawers
    this.crmDrawer = document.getElementById('crm-drawer');
    this.btnCloseCrm = document.getElementById('btn-close-crm');
    this.crmJsonPreview = document.getElementById('crm-json-preview');
    this.crmLeadSummary = document.getElementById('crm-lead-summary');
    this.crmUtmSummary = document.getElementById('crm-utm-summary');
    this.crmAuditBreakdown = document.getElementById('crm-audit-breakdown');

    this.consultationModal = document.getElementById('consultation-modal');
    this.btnCloseModal = document.getElementById('btn-close-modal');
    this.btnConfirmConsult = document.getElementById('btn-confirm-consultation');
  }

  async init() {
    // 1. Initialize UTM and analytics
    initUTMTracking();
    trackEvent('audit_landing_view', { page: 'landing' });

    // 2. Attach global event listeners
    this.bindEvents();

    // 3. Check for existing active session in localStorage
    const stored = apiService.getSessionFromStorage();
    const resumeBannerWrap = document.getElementById('resume-banner-wrap');
    const resumeSessionText = document.getElementById('resume-session-text');
    const btnResume = document.getElementById('btn-resume-audit');

    if (stored && stored.id) {
      this.activeSession = stored;
      if (stored.completedAt && stored.email) {
        if (this.btnRestart) this.btnRestart.style.display = 'inline-flex';
        this.activeAnalysis = stored.analysis || analyzeAuditAnswers(stored.answers);
        if (resumeBannerWrap && resumeSessionText && btnResume) {
          resumeSessionText.textContent = `Completed Report for ${stored.name || 'Your Brand'} (${stored.company || ''})`;
          btnResume.textContent = 'View Report →';
          btnResume.onclick = () => this.showReport(false);
          resumeBannerWrap.style.display = 'block';
        }
      } else {
        const answeredCount = Object.keys(stored.answers || {}).length;
        if (answeredCount > 0 && answeredCount <= 12) {
          this.currentQuestionIndex = Math.min(answeredCount, 11);
          if (resumeBannerWrap && resumeSessionText && btnResume) {
            resumeSessionText.textContent = `Question ${this.currentQuestionIndex + 1} of 12`;
            btnResume.textContent = 'Resume Audit →';
            btnResume.onclick = () => {
              this.switchView('audit');
              this.renderCurrentQuestion();
            };
            resumeBannerWrap.style.display = 'block';
          }
        }
      }
    }

    this.updateCRMInspector();
  }

  bindEvents() {
    // Landing start CTA button
    const btnHero = document.getElementById('btn-start-audit-hero');
    if (btnHero) btnHero.addEventListener('click', () => this.startAuditFlow());

    // Sticky header shadow on scroll
    const appHeader = document.querySelector('.app-header');
    if (appHeader) {
      this.updateHeaderShadow = () => {
        if (window.scrollY > 8) {
          appHeader.classList.add('scrolled');
        } else {
          appHeader.classList.remove('scrolled');
        }
      };
      window.addEventListener('scroll', this.updateHeaderShadow, { passive: true });
      this.updateHeaderShadow();
    }

    // Brand and Home navigation (allow natural navigation to cmgalaxy.com or view reset)
    if (this.headerBrandLink) {
      this.headerBrandLink.addEventListener('click', () => {
        // Keep active session intact while allowing standard link navigation
      });
    }

    // Dropdown Toggles for Touch / Click (Features & Resources)
    const dropdownWraps = document.querySelectorAll('.nav-item-dropdown-wrap');
    dropdownWraps.forEach((wrap) => {
      const btn = wrap.querySelector('.nav-item-dropdown-btn');
      if (btn) {
        btn.addEventListener('click', (e) => {
          e.stopPropagation();
          // Close other open dropdowns
          dropdownWraps.forEach((w) => {
            if (w !== wrap) {
              w.classList.remove('open');
              const b = w.querySelector('.nav-item-dropdown-btn');
              if (b) b.setAttribute('aria-expanded', 'false');
            }
          });
          const isOpen = wrap.classList.toggle('open');
          btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      }
    });

    document.addEventListener('click', (e) => {
      dropdownWraps.forEach((wrap) => {
        if (!wrap.contains(e.target)) {
          wrap.classList.remove('open');
          const btn = wrap.querySelector('.nav-item-dropdown-btn');
          if (btn) btn.setAttribute('aria-expanded', 'false');
        }
      });
    });

    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape') {
        dropdownWraps.forEach((wrap) => {
          if (wrap.classList.contains('open')) {
            wrap.classList.remove('open');
            const btn = wrap.querySelector('.nav-item-dropdown-btn');
            if (btn) {
              btn.setAttribute('aria-expanded', 'false');
              btn.focus();
            }
          }
        });
      }
    });

    // Sign in header action - navigates directly to https://platform.cmgalaxy.com/login
    const navSignIn = document.getElementById('nav-signin-btn');
    if (navSignIn) {
      navSignIn.addEventListener('click', () => {
        trackEvent('cmgalaxy_signin_clicked', { source: 'navbar' });
      });
    }

    // Restart audit button
    if (this.btnRestart) {
      this.btnRestart.addEventListener('click', () => {
        apiService.resetAudit();
        this.activeSession = null;
        this.activeAnalysis = null;
        this.currentQuestionIndex = 0;
        this.btnRestart.style.display = 'none';
        this.startAuditFlow();
      });
    }

    // Audit navigation buttons
    if (this.btnAuditBack) {
      this.btnAuditBack.addEventListener('click', () => this.handleAuditBack());
    }

    if (this.btnAuditNext) {
      this.btnAuditNext.addEventListener('click', () => this.handleAuditNext());
    }

    // Keyboard navigation for audit questions (1, 2, 3, 4, Enter, Back)
    window.addEventListener('keydown', (e) => {
      if (!this.views.audit.classList.contains('active')) return;

      if (['1', '2', '3', '4'].includes(e.key)) {
        const index = parseInt(e.key, 10) - 1;
        const options = ANSWER_OPTIONS;
        if (options[index]) {
          this.selectAnswer(options[index].id);
        }
      } else if (e.key === 'Enter') {
        const currentQ = AUDIT_QUESTIONS[this.currentQuestionIndex];
        const hasAnswer = this.activeSession?.answers?.[currentQ.id];
        if (hasAnswer) {
          e.preventDefault();
          this.handleAuditNext();
        }
      } else if (e.key === 'ArrowLeft') {
        if (this.currentQuestionIndex > 0) {
          this.handleAuditBack();
        }
      }
    });

    // Lead capture form submission
    if (this.leadForm) {
      this.leadForm.addEventListener('submit', (e) => this.handleLeadSubmit(e));
    }

    // Print / Download PDF
    if (this.btnPrintReport) {
      this.btnPrintReport.addEventListener('click', () => {
        window.print();
      });
    }

    // Filter tabs in report
    const filterTabs = document.querySelectorAll('.filter-tab');
    filterTabs.forEach((tab) => {
      tab.addEventListener('click', (e) => {
        filterTabs.forEach((t) => t.classList.remove('active'));
        e.currentTarget.classList.add('active');
        this.currentFindingFilter = e.currentTarget.dataset.filter;
        this.renderFindingsList();
      });
    });

    // CMGalaxy conversion buttons
    const btnBookDemo = document.getElementById('btn-book-demo') || document.getElementById('btn-cmgalaxy-primary');
    if (btnBookDemo) {
      btnBookDemo.addEventListener('click', () => {
        trackEvent('cmgalaxy_book_demo_clicked', { cta: 'footer_banner_book_demo' });
        this.openConsultationModal('Book a Demo');
      });
    }

    // Report textual back button
    const btnReportBack = document.getElementById('btn-report-back');
    if (btnReportBack) {
      btnReportBack.addEventListener('click', () => {
        this.switchView('landing');
      });
    }

    // Lead form textual back button
    const btnLeadBack = document.getElementById('btn-lead-back');
    if (btnLeadBack) {
      btnLeadBack.addEventListener('click', () => {
        this.switchView('audit');
        this.renderCurrentQuestion();
      });
    }

    // Sales CRM Inspector Drawer
    if (this.btnSalesInspector) {
      this.btnSalesInspector.addEventListener('click', () => this.openCRMDrawer());
    }
    if (this.btnCloseCrm) {
      this.btnCloseCrm.addEventListener('click', () => this.closeCRMDrawer());
    }
    if (this.crmDrawer) {
      this.crmDrawer.addEventListener('click', (e) => {
        if (e.target === this.crmDrawer) this.closeCRMDrawer();
      });
    }

    // Consultation Modal
    if (this.btnCloseModal) {
      this.btnCloseModal.addEventListener('click', () => this.closeConsultationModal());
    }
    if (this.consultationModal) {
      this.consultationModal.addEventListener('click', (e) => {
        if (e.target === this.consultationModal) this.closeConsultationModal();
      });
    }
    if (this.btnConfirmConsult) {
      this.btnConfirmConsult.addEventListener('click', () => {
        trackEvent('cmgalaxy_signup_completed', {
          preferredWindow: document.getElementById('consult-window').value,
          channel: document.getElementById('consult-channel').value
        });
        alert('Thank you! A CMGalaxy Attribution Specialist will reach out within 2 hours with your audit calendar invite.');
        this.closeConsultationModal();
      });
    }
  }

  switchView(viewName) {
    Object.keys(this.views).forEach((key) => {
      this.views[key].classList.remove('active');
    });

    if (this.views[viewName]) {
      this.views[viewName].classList.add('active');
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    if (this.updateHeaderShadow) {
      this.updateHeaderShadow();
    }

    // Show/hide restart button in header
    if (this.btnRestart) {
      this.btnRestart.style.display = viewName === 'landing' ? 'none' : 'inline-flex';
    }
  }

  async startAuditFlow() {
    this.currentQuestionIndex = 0;
    const res = await apiService.startAudit();
    this.activeSession = res.session;

    trackEvent('audit_started', {
      sessionId: this.activeSession.id,
      utmSource: this.activeSession.utmSource
    });

    this.switchView('audit');
    this.renderCurrentQuestion();
    this.updateCRMInspector();
  }

  renderCurrentQuestion() {
    const q = AUDIT_QUESTIONS[this.currentQuestionIndex];
    if (!q) return;

    // 1. Update top bar
    this.categoryNameEl.textContent = q.categoryName;
    const padNum = String(q.number).padStart(2, '0');
    this.counterTextEl.textContent = `Question ${padNum} of 12`;

    // 2. Update progress bar
    const progressPercent = ((this.currentQuestionIndex + 1) / 12) * 100;
    this.progressFillEl.style.width = `${progressPercent}%`;
    this.progressBarEl.setAttribute('aria-valuenow', q.number);

    // 3. Update question body
    this.questionTitleTagEl.textContent = q.title;
    this.questionTextEl.textContent = q.question;

    // 4. Render answer cards
    const currentAnswer = this.activeSession?.answers?.[q.id] || null;
    this.answersContainerEl.innerHTML = '';

    ANSWER_OPTIONS.forEach((opt, idx) => {
      const isSelected = currentAnswer === opt.id;
      const card = document.createElement('button');
      card.type = 'button';
      card.className = `answer-card ${isSelected ? 'selected' : ''}`;
      card.setAttribute('role', 'radio');
      card.setAttribute('aria-checked', isSelected ? 'true' : 'false');
      card.setAttribute('data-answer', opt.id);

      card.innerHTML = `
        <div class="answer-icon-circle" aria-hidden="true">${opt.icon}</div>
        <div class="answer-text-col">
          <span class="answer-main-label">${opt.label}</span>
          <span class="answer-helper-text">${opt.helper}</span>
        </div>
      `;

      card.addEventListener('click', () => {
        this.selectAnswer(opt.id);
      });

      this.answersContainerEl.appendChild(card);
    });

    // 5. Update Navigation Controls
    this.btnAuditBack.disabled = false;

    if (currentAnswer) {
      this.btnAuditNext.disabled = false;
    } else {
      this.btnAuditNext.disabled = true;
    }

    if (this.currentQuestionIndex === 11) {
      this.btnAuditNext.textContent = 'Finish Audit & Analyze →';
    } else {
      this.btnAuditNext.textContent = 'Next Question →';
    }

    // 6. Track view event
    trackEvent('audit_question_viewed', {
      question_id: q.id,
      question_title: q.title,
      question_number: q.number
    });
  }

  async selectAnswer(answerKey) {
    const q = AUDIT_QUESTIONS[this.currentQuestionIndex];
    if (!q || !this.activeSession) return;

    // Update in-memory session answers
    this.activeSession.answers = this.activeSession.answers || {};
    this.activeSession.answers[q.id] = answerKey;

    // Update UI selection visually
    const cards = this.answersContainerEl.querySelectorAll('.answer-card');
    cards.forEach((card) => {
      if (card.dataset.answer === answerKey) {
        card.classList.add('selected');
        card.setAttribute('aria-checked', 'true');
      } else {
        card.classList.remove('selected');
        card.setAttribute('aria-checked', 'false');
      }
    });

    // Enable next button immediately
    this.btnAuditNext.disabled = false;

    // Evaluate response & impact
    const evalResult = evaluateAnswer(q.id, answerKey);

    // Save answer via mock API
    await apiService.saveAnswer(q.id, answerKey);

    // Track analytics
    trackEvent('audit_question_answered', {
      question_id: q.id,
      question_title: q.title,
      answer: answerKey,
      status: evalResult.status,
      budget_impact: evalResult.budgetImpact
    });

    this.updateCRMInspector();
  }

  handleAuditBack() {
    if (this.currentQuestionIndex > 0) {
      this.currentQuestionIndex--;
      this.renderCurrentQuestion();
    } else {
      this.switchView('landing');
    }
  }

  async handleAuditNext() {
    const q = AUDIT_QUESTIONS[this.currentQuestionIndex];
    const currentAnswer = this.activeSession?.answers?.[q.id];

    if (!currentAnswer) {
      alert('Please select an option to proceed with the audit.');
      return;
    }

    if (this.currentQuestionIndex < 11) {
      this.currentQuestionIndex++;
      this.renderCurrentQuestion();
    } else {
      // Completed all 12 questions!
      const compRes = await apiService.completeAudit();
      this.activeAnalysis = compRes.analysis;

      trackEvent('audit_completed', {
        sessionId: this.activeSession.id
      });

      trackEvent('lead_form_viewed', {
        sessionId: this.activeSession.id
      });

      // Switch to lead capture view (State 3)
      this.switchView('lead');
      this.updateCRMInspector();
    }
  }

  async handleLeadSubmit(e) {
    e.preventDefault();

    const fullName = this.inputName.value.trim();
    const email = this.inputEmail.value.trim();
    const company = this.inputCompany.value.trim();

    let hasError = false;

    // Validate Name
    if (!fullName) {
      this.showInputError(this.inputName, 'error-fullname', true);
      hasError = true;
    } else {
      this.showInputError(this.inputName, 'error-fullname', false);
    }

    // Validate Email (format check)
    const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
    if (!email || !emailRegex.test(email)) {
      this.showInputError(this.inputEmail, 'error-email', true);
      hasError = true;
    } else {
      this.showInputError(this.inputEmail, 'error-email', false);
    }

    // Validate Company
    if (!company) {
      this.showInputError(this.inputCompany, 'error-company', true);
      hasError = true;
    } else {
      this.showInputError(this.inputCompany, 'error-company', false);
    }

    if (hasError) return;

    // Loading state
    this.btnSubmitLead.disabled = true;
    const btnText = this.btnSubmitLead.querySelector('.btn-text');
    const spinner = this.btnSubmitLead.querySelector('.spinner');
    if (btnText) btnText.textContent = 'Generating your report...';
    if (spinner) spinner.style.display = 'inline-block';

    trackEvent('lead_form_submitted', {
      hasCompany: Boolean(company)
    });

    try {
      const res = await apiService.createLead({
        name: fullName,
        email: email,
        company: company
      });

      this.activeAnalysis = res.analysis;
      this.activeSession = apiService.getSessionFromStorage();

      trackEvent('report_viewed', {
        sessionId: this.activeSession.id
      });

      // Clear session from storage so next attempt starts completely fresh!
      apiService.resetAudit();
      if (this.leadForm) this.leadForm.reset();

      // Reveal personalized report immediately!
      this.showReport(true);
    } catch (err) {
      console.error('Lead creation error', err);
      alert("We couldn't generate your report right now. Your answers are saved. Please try again.");
    } finally {
      this.btnSubmitLead.disabled = false;
      if (btnText) btnText.textContent = 'Show My Attribution Report →';
      if (spinner) spinner.style.display = 'none';
      this.updateCRMInspector();
    }
  }

  showInputError(inputEl, errorId, show) {
    const errorEl = document.getElementById(errorId);
    if (show) {
      inputEl.classList.add('has-error');
      if (errorEl) errorEl.classList.add('visible');
    } else {
      inputEl.classList.remove('has-error');
      if (errorEl) errorEl.classList.remove('visible');
    }
  }

  showReport(isNewSubmission = false) {
    if (!this.activeAnalysis) {
      this.activeAnalysis = analyzeAuditAnswers(this.activeSession?.answers || {});
    }

    const {
      reliableCount,
      investigateCount,
      attributionGapCount,
      unknownCount,
      biggestFindingHeadline,
      biggestFindingSubtext
    } = this.activeAnalysis;

    // 1. Meta Prepared For
    const comp = this.activeSession?.company || 'Your Brand';
    const user = this.activeSession?.name || 'Growth Lead';
    this.reportUserMetaEl.textContent = `${user} · ${comp}`;

    // 2. Animate / set 4 category summary counters
    this.countReliableEl.textContent = `${reliableCount} areas`;
    this.countInvestigateEl.textContent = `${investigateCount} areas`;
    this.countGapsEl.textContent = `${attributionGapCount} areas`;
    this.countUnknownEl.textContent = `${unknownCount} areas`;

    // 3. Biggest Finding Hero
    this.biggestFindingHeadlineEl.textContent = biggestFindingHeadline;
    this.biggestFindingSubtextEl.textContent = biggestFindingSubtext;

    // 4. Render Finding Cards
    this.renderFindingsList();

    // 5. Switch to report view (State 4 & 5)
    this.switchView('report');
    this.updateCRMInspector();
  }

  renderFindingsList() {
    if (!this.activeAnalysis || !this.findingsContainerEl) return;

    this.findingsContainerEl.innerHTML = '';
    const filter = this.currentFindingFilter;

    const filtered = this.activeAnalysis.findings.filter((f) => {
      if (filter === 'all') return true;
      return f.status === filter;
    });

    if (filtered.length === 0) {
      this.findingsContainerEl.innerHTML = `
        <div style="background: var(--bg-surface); padding: var(--space-8); border: 1px solid var(--border-light); border-radius: var(--radius-lg); text-align: center; color: var(--text-secondary);">
          No findings matching this filter.
        </div>
      `;
      return;
    }

    filtered.forEach((finding) => {
      const card = document.createElement('article');
      card.className = 'finding-card';

      // Status badge details
      let badgeClass = 'badge-unknown';
      let statusSymbol = '?';
      let statusLabel = 'Unknown';

      if (finding.status === 'RELIABLE') {
        badgeClass = 'badge-reliable';
        statusSymbol = '✓';
        statusLabel = 'Reliable';
      } else if (finding.status === 'INVESTIGATE') {
        badgeClass = 'badge-investigate';
        statusSymbol = '◐';
        statusLabel = 'Investigate';
      } else if (finding.status === 'ATTRIBUTION_GAP') {
        badgeClass = 'badge-gap';
        statusSymbol = '!';
        statusLabel = 'Attribution Gap';
      }

      // Budget Impact badge details
      let impactClass = 'impact-medium';
      if (finding.budgetImpact === 'CRITICAL') impactClass = 'impact-critical';
      else if (finding.budgetImpact === 'HIGH') impactClass = 'impact-high';
      else if (finding.budgetImpact === 'LOW') impactClass = 'impact-low';

      card.innerHTML = `
        <div class="finding-top-row">
          <div class="finding-status-group">
            <span class="badge-status ${badgeClass}">
              <span aria-hidden="true">${statusSymbol}</span>
              <span>${statusLabel}</span>
            </span>
            <span class="finding-category-sub">${finding.categoryName}</span>
          </div>

          <span class="badge-impact ${impactClass}">
            Budget Impact: ${finding.budgetImpact}
          </span>
        </div>

        <div>
          <h3 class="finding-title">${finding.title}</h3>
          <div class="finding-answer-strip">
            <span>You answered:</span>
            <span class="finding-answer-val">${finding.answerLabel}</span>
          </div>
        </div>

        <div class="finding-body-grid">
          <div class="diagnostic-block">
            <h4>Why It Matters</h4>
            <p>${finding.whyItMatters}</p>
          </div>
          <div class="diagnostic-block">
            <h4>What To Investigate</h4>
            <p>${finding.whatToInvestigate}</p>
          </div>
        </div>

        <div class="finding-card-footer">
          <div class="source-key-question">
            Diagnostic prompt: "${finding.keyQuestion}"
          </div>
          <button type="button" class="btn-card-cta" data-finding-title="${finding.title}">
            Review with CMGalaxy →
          </button>
        </div>
      `;

      const ctaBtn = card.querySelector('.btn-card-cta');
      if (ctaBtn) {
        ctaBtn.addEventListener('click', () => {
          trackEvent('finding_expanded', {
            question_id: finding.questionId,
            question_title: finding.title
          });
          this.openConsultationModal(finding.title);
        });
      }

      this.findingsContainerEl.appendChild(card);
    });
  }

  openConsultationModal(topic = 'Attribution Audit Findings') {
    if (this.consultationModal) {
      const channelSelect = document.getElementById('consult-channel');
      if (channelSelect && topic) {
        // Pre-select relevant channel option
        if (topic.toLowerCase().includes('meta')) channelSelect.value = 'Meta Ads (CAPI / Advantage+)';
        else if (topic.toLowerCase().includes('google')) channelSelect.value = 'Google Ads (PMax / Enhanced Conversions)';
        else if (topic.toLowerCase().includes('shopify') || topic.toLowerCase().includes('revenue')) channelSelect.value = 'Shopify Net Order Reconciliation';
        else channelSelect.value = 'All of the above';
      }
      this.consultationModal.classList.add('open');
      this.consultationModal.setAttribute('aria-hidden', 'false');
      trackEvent('cmgalaxy_signup_started', { topic });
    }
  }

  closeConsultationModal() {
    if (this.consultationModal) {
      this.consultationModal.classList.remove('open');
      this.consultationModal.setAttribute('aria-hidden', 'true');
    }
  }

  openCRMDrawer() {
    this.updateCRMInspector();
    if (this.crmDrawer) {
      this.crmDrawer.classList.add('open');
      this.crmDrawer.setAttribute('aria-hidden', 'false');
    }
  }

  closeCRMDrawer() {
    if (this.crmDrawer) {
      this.crmDrawer.classList.remove('open');
      this.crmDrawer.setAttribute('aria-hidden', 'true');
    }
  }

  updateCRMInspector() {
    const session = apiService.getSessionFromStorage();
    const utm = getStoredUTM();
    const analysis = this.activeAnalysis || (session?.answers ? analyzeAuditAnswers(session.answers) : null);

    if (!this.crmLeadSummary) return;

    if (session && session.email) {
      this.crmLeadSummary.innerHTML = `
        <div><strong>Name:</strong> ${session.name || 'Not provided'}</div>
        <div><strong>Email:</strong> ${session.email}</div>
        <div><strong>Company:</strong> ${session.company || 'Not provided'}</div>
        <div><strong>Audit Session ID:</strong> <code>${session.id}</code></div>
        <div><strong>Completed At:</strong> ${session.completedAt || 'In Progress'}</div>
      `;
    } else {
      this.crmLeadSummary.innerHTML = `
        <em>Lead details will appear here once the user completes the 12 questions and submits the report delivery form.</em>
      `;
    }

    if (this.crmUtmSummary) {
      this.crmUtmSummary.innerHTML = `
        <div><strong>Source:</strong> ${utm.utmSource || 'direct'}</div>
        <div><strong>Medium:</strong> ${utm.utmMedium || 'none'}</div>
        <div><strong>Campaign:</strong> ${utm.utmCampaign || '(not set)'}</div>
        <div><strong>Referrer:</strong> ${utm.referrer || 'direct'}</div>
      `;
    }

    if (this.crmAuditBreakdown && analysis) {
      this.crmAuditBreakdown.innerHTML = `
        <div>✓ <strong>Reliable:</strong> ${analysis.reliableCount} areas</div>
        <div>◐ <strong>Investigate:</strong> ${analysis.investigateCount} areas</div>
        <div>! <strong>Attribution Gaps:</strong> ${analysis.attributionGapCount} areas</div>
        <div>? <strong>Unknown:</strong> ${analysis.unknownCount} areas</div>
        <div style="margin-top: 6px; font-weight: 700; color: var(--cmg-brand);">Strategic Risk Level: ${analysis.strategicRiskLevel}</div>
      `;
    }

    if (this.crmJsonPreview) {
      const payload = {
        auditId: session?.id || null,
        lead: {
          name: session?.name || null,
          email: session?.email || null,
          company: session?.company || null
        },
        attributionSummary: analysis ? {
          reliable: analysis.reliableCount,
          investigate: analysis.investigateCount,
          attributionGaps: analysis.attributionGapCount,
          unknown: analysis.unknownCount,
          riskLevel: analysis.strategicRiskLevel
        } : null,
        marketingSource: utm,
        answersCount: Object.keys(session?.answers || {}).length,
        answers: session?.answers || {}
      };
      this.crmJsonPreview.textContent = JSON.stringify(payload, null, 2);
    }
  }
}

// Instantiate and initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
  const app = new CMGalaxyAuditApp();
  app.init();
  window.__cmgApp = app; // Accessible for debugging
});
