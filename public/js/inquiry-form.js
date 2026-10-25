// Inquiry form submission (validation messages, Turnstile token check, POST to
// /send-mail.php, VJT submission hook). Loaded by InquiryForm.astro as a classic
// external script after the form; the dev switch comes from its data-dev
// attribute, so the file is identical in every language and ClientRouter does
// not run it again on a soft navigation. Its astro:page-load listener binds each
// new form. If a new fingerprint runs in a tab that stayed open across a deploy,
// data-submitBound still binds each form element only once.
(function() {
  const isDev = (document.currentScript && document.currentScript.dataset.dev) === 'true';

  // Per-language copy rendered on the form element (data-i18n)
  function getMessages(form) {
    try {
      return JSON.parse((form && form.dataset.i18n) || '{}');
    } catch (_) {
      return {};
    }
  }

  // Form elements helper
  function getFormElements() {
    return {
      form: document.getElementById('inquiry-form'),
      submitBtn: document.getElementById('submit-btn'),
      btnText: document.getElementById('btn-text'),
      btnArrow: document.getElementById('btn-arrow'),
      btnSpinner: document.getElementById('btn-spinner'),
      errorContainer: document.getElementById('form-error'),
      errorMessage: document.getElementById('error-message'),
      turnstileErrorContainer: document.getElementById('turnstile-error'),
      turnstileErrorMessage: document.getElementById('turnstile-error-message'),
    };
  }

  // Loading state
  function setLoading(loading) {
    const { form, submitBtn, btnText, btnArrow, btnSpinner } = getFormElements();
    const fm = getMessages(form);
    if (loading) {
      if (submitBtn) submitBtn.disabled = true;
      if (btnText) btnText.textContent = fm.submitting;
      if (btnArrow) btnArrow.classList.add('hidden');
      if (btnSpinner) btnSpinner.classList.remove('hidden');
    } else {
      if (submitBtn) submitBtn.disabled = false;
      if (btnText) btnText.textContent = fm.submitRequest || 'Get My Project Review';
      if (btnArrow) btnArrow.classList.remove('hidden');
      if (btnSpinner) btnSpinner.classList.add('hidden');
    }
  }

  // Error display
  function showError(message) {
    const { errorContainer, errorMessage } = getFormElements();
    if (errorMessage) errorMessage.textContent = message;
    if (errorContainer) {
      errorContainer.classList.remove('hidden');
      errorContainer.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  function hideError() {
    const { errorContainer } = getFormElements();
    if (errorContainer) errorContainer.classList.add('hidden');
  }

  function showTurnstileError(message) {
    const { turnstileErrorContainer, turnstileErrorMessage } = getFormElements();
    if (turnstileErrorMessage) turnstileErrorMessage.textContent = message;
    if (turnstileErrorContainer) {
      turnstileErrorContainer.classList.remove('hidden');
      turnstileErrorContainer.style.display = 'block';
    }
  }

  function hideTurnstileError() {
    const { turnstileErrorContainer } = getFormElements();
    if (turnstileErrorContainer) turnstileErrorContainer.classList.add('hidden');
  }

  function removeVjtContextWithoutCurrentConsent(formData, form) {
    // Attribution is kept only if the form was patched under the consent
    // cycle that is still current. Otherwise only the vjt_* fields are
    // dropped; the inquiry itself is always sent.
    let analyticsAllowed = false;
    try {
      const cycle = window.__kssmiConsent && window.__kssmiConsent.trackingCycle();
      analyticsAllowed = !!cycle && !!form && form.dataset.vjtCycle === cycle;
    } catch (_) {}
    if (analyticsAllowed) return;
    [
      'vjt_analytics_consent', 'vjt_visitor_id', 'vjt_session_id',
      'vjt_submit_page', 'vjt_submit_title', 'vjt_referrer',
      'vjt_landing_url', 'vjt_landing_title', 'vjt_path_snapshot',
      'vjt_utm_source', 'vjt_utm_medium', 'vjt_utm_campaign',
      'vjt_utm_content', 'vjt_utm_term', 'vjt_journey_step',
      'vjt_submission_event_id'
    ].forEach((name) => formData.delete(name));
  }

  function validateAttachments(form) {
    const input = form.querySelector('input[name="attachments[]"]');
    const files = input && input.files ? Array.from(input.files) : [];
    if (files.length > 20) return 'Please attach no more than 20 files.';
    const allowedExtensions = new Set(['pdf', 'jpg', 'jpeg', 'png', 'webp', 'docx', 'xlsx', 'dwg', 'dxf', 'stp', 'step', 'igs', 'iges', 'stl', 'ai', 'eps', 'psd', 'cdr', '3dm', 'sldprt', 'sldasm', 'ipt', 'iam', 'x_t']);
    let totalBytes = 0;
    for (const file of files) {
      const extension = (file.name.split('.').pop() || '').toLowerCase();
      if (!allowedExtensions.has(extension)) return 'Use a supported PDF, image, CAD, or design file.';
      if (file.size > 5 * 1024 * 1024) return 'Each attachment must be 5 MB or smaller.';
      totalBytes += file.size;
    }
    if (totalBytes > 8 * 1024 * 1024) return 'Attachments must total 8 MB or less.';
    return '';
  }

  // Redirect helper
  function redirectToThankYou(lang) {
    const thankYouUrl = (lang === 'en') ? '/thank-you/' : `/${lang}/thank-you/`;
    window.location.href = thankYouUrl;
  }

  // Form submission setup
  function setupFormSubmission() {
    // Bind each form element once. Listeners live on the element itself, so a
    // ClientRouter swap discards them with the old page; the form is never
    // cloned, which would also replace the Turnstile widget inside it.
    const { form: newForm } = getFormElements();
    if (!newForm || newForm.dataset.submitBound === '1') return;
    newForm.dataset.submitBound = '1';
    const fm = getMessages(newForm);

    const attachmentInput = newForm.querySelector('input[name="attachments[]"]');
    const attachmentList = document.getElementById('attachment-list');
    const attachmentDropZone = document.getElementById('attachment-dropzone');
    let selectedAttachments = [];

    function syncAttachments() {
      if (!attachmentInput || !attachmentList) return;
      try {
        const transfer = new DataTransfer();
        selectedAttachments.forEach((file) => transfer.items.add(file));
        attachmentInput.files = transfer.files;
      } catch (_) {
        // The server remains authoritative if a legacy browser cannot rebuild FileList.
      }
      attachmentList.replaceChildren();
      attachmentList.classList.toggle('hidden', selectedAttachments.length === 0);
      selectedAttachments.forEach((file, index) => {
        const item = document.createElement('li');
        item.className = 'flex items-center justify-between gap-3 rounded bg-white px-3 py-2 border border-gray-200';
        const name = document.createElement('span');
        name.className = 'min-w-0 truncate';
        name.textContent = `${file.name} (${(file.size / 1024 / 1024).toFixed(1)} MB)`;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'shrink-0 text-[#5D4E37] underline hover:text-[#4A3D2E] active:text-[#4A3D2E] focus-visible:text-[#4A3D2E] focus-within:text-[#4A3D2E] focus:outline-hidden focus-visible:ring-2 focus-visible:ring-[#8B7355]';
        remove.textContent = 'Remove';
        remove.addEventListener('click', () => {
          selectedAttachments.splice(index, 1);
          syncAttachments();
        });
        item.append(name, remove);
        attachmentList.appendChild(item);
      });
    }

    function addAttachments(additions) {
      additions.forEach((file) => {
        const duplicate = selectedAttachments.some((current) => current.name === file.name
          && current.size === file.size && current.lastModified === file.lastModified);
        if (!duplicate) selectedAttachments.push(file);
      });
      syncAttachments();
    }

    if (attachmentInput) {
      attachmentInput.addEventListener('change', () => addAttachments(Array.from(attachmentInput.files || [])));
    }
    if (attachmentDropZone) {
      ['dragenter', 'dragover'].forEach((eventName) => attachmentDropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        attachmentDropZone.classList.add('border-[#8B7355]', 'bg-stone-50');
      }));
      ['dragleave', 'drop'].forEach((eventName) => attachmentDropZone.addEventListener(eventName, (event) => {
        event.preventDefault();
        attachmentDropZone.classList.remove('border-[#8B7355]', 'bg-stone-50');
      }));
      attachmentDropZone.addEventListener('drop', (event) => {
        addAttachments(Array.from(event.dataTransfer?.files || []));
      });
    }

    newForm.addEventListener('submit', async function(e) {
      e.preventDefault();
      if (newForm.dataset.vjtSubmitting === '1') return;
      hideError();
      hideTurnstileError();

      // Check Turnstile (skip in dev) - use global token
      const token = window.turnstileToken;
      if (!isDev && !token) {
        showTurnstileError(fm.turnstileError);
        return;
      }

      const attachmentError = validateAttachments(newForm);
      if (attachmentError) {
        showError(attachmentError);
        return;
      }

      // From this point there is one real logical request in flight. The
      // tracker returns the ID used for its Attempt row; send-mail.php uses the
      // same ID to promote that row to Success/Error instead of adding another
      // Legacy row.
      newForm.dataset.vjtSubmitting = '1';
      let submissionEventId = '';
      if (typeof window.VJT_beginInquirySubmission === 'function') {
        try {
          submissionEventId = window.VJT_beginInquirySubmission(newForm) || '';
        } catch (_) {
          // Analytics is optional: corrupt/stale browser state must never stop
          // the real inquiry request from reaching send-mail.php.
          submissionEventId = '';
        }
      }

      // Gather form data
      const formData = new FormData(newForm);
      if (submissionEventId) formData.set('vjt_submission_event_id', submissionEventId);
      // A form may have been patched while consent was granted and submitted
      // after withdrawal. Re-check live consent at the final request boundary.
      removeVjtContextWithoutCurrentConsent(formData, newForm);
      if (!isDev && token) {
        formData.append('cf-turnstile-response', token);
      }

      setLoading(true);

      try {
        const devHostname = window.location.hostname === '127.0.0.1'
          ? '127.0.0.1'
          : ((window.location.hostname === '::1' || window.location.hostname === '[::1]') ? '[::1]' : 'localhost');
        const apiUrl = isDev ? `http://${devHostname}:4325/send-mail.php` : '/send-mail.php';

        const response = await fetch(apiUrl, {
          method: 'POST',
          credentials: 'include',
          body: formData
        });

        const contentType = response.headers.get('content-type');
        if (contentType && contentType.includes('text/html')) {
          throw new Error('PHP_NOT_CONFIGURED');
        }

        const result = await response.json();

        if (!response.ok) {
          const errorMsg = result.errors
            ? result.errors.join('. ')
            : (result.message || fm.errorDefault);
          showError(errorMsg);
          if (window.turnstile && window.turnstileWidgetId !== undefined) {
            window.turnstile.reset(window.turnstileWidgetId);
            window.turnstileToken = null;
          }
          delete newForm.dataset.vjtSubmitting;
          setLoading(false);
          return;
        }

        if (result.success) {
          const currentLang = formData.get('language') || 'en';
          redirectToThankYou(currentLang);
        } else {
          const errorMsg = result.errors
            ? result.errors.join('. ')
            : (result.message || fm.errorDefault);
          showError(errorMsg);
          delete newForm.dataset.vjtSubmitting;
          setLoading(false);
        }
      } catch (error) {

        if (isDev && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError') || error.message === 'PHP_NOT_CONFIGURED')) {
          showError(fm.phpNotRunning);

          const mailtoLink = document.createElement('a');
          // Open a blank email draft directly; never place form contents in the URL.
          mailtoLink.href = 'mailto:sales@kssmi.com';
          mailtoLink.setAttribute('data-vjt-contact', 'mailto');
          mailtoLink.setAttribute('data-vjt-placement', 'inquiry-fallback');
          mailtoLink.className = 'inline-block mt-3 px-6 py-3 bg-[#5D4E37] text-white rounded-lg hover:bg-[#4A3D2E] active:bg-[#4A3D2E] focus-visible:bg-[#4A3D2E] focus-within:bg-[#4A3D2E]';
          mailtoLink.textContent = fm.openEmailClient;
          mailtoLink.id = 'mailto-fallback';

          const existingBtn = document.getElementById('mailto-fallback');
          if (existingBtn) existingBtn.remove();

          const { errorContainer } = getFormElements();
          if (errorContainer) errorContainer.appendChild(mailtoLink);

          delete newForm.dataset.vjtSubmitting;
          setLoading(false);
          return;
        }

        if (error.message === 'PHP_NOT_CONFIGURED') {
          showError(fm.phpNotConfigured);
        } else if (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')) {
          showError(fm.cannotConnect);
        } else if (error.message.includes('Unexpected token')) {
          showError(fm.invalidResponse);
        } else {
          showError(fm.errorPrefix.replace('{0}', error.message));
        }

        if (window.turnstile && window.turnstileWidgetId !== undefined) {
          window.turnstile.reset(window.turnstileWidgetId);
          window.turnstileToken = null;
        }
        delete newForm.dataset.vjtSubmitting;
        setLoading(false);
      }
    });
  }

  // Initialize form
  setupFormSubmission();

  // Re-setup after View Transitions
  document.addEventListener('astro:page-load', function() {
    setupFormSubmission();
  });

  // Back/forward cache restore (e.g. Back from the thank-you page): the page
  // keeps the in-flight state from the submission that already succeeded.
  window.addEventListener('pageshow', function(event) {
    if (!event.persisted) return;
    const { form } = getFormElements();
    if (!form) return;
    delete form.dataset.vjtSubmitting;
    setLoading(false);
  });
})();
