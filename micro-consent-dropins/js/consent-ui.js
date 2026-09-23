(function() {
    const currentScript = document.currentScript;
    if (currentScript && currentScript.src) {
        const stylesheet = document.createElement('link');
        stylesheet.rel = 'stylesheet';
        stylesheet.href = new URL('../css/consent-ui.css', currentScript.src).href;
        stylesheet.referrerPolicy = 'no-referrer';
        if (currentScript.nonce) stylesheet.nonce = currentScript.nonce;
        document.head.appendChild(stylesheet);
    }

    const container = document.createElement('div');
    container.innerHTML = `
    <div id="priv-banner">
      <div>We use cookies for analytics and marketing.</div>
      <button class="priv-btn" id="banner-manage-btn">Manage Preferences</button>
    </div>

    <div id="priv-icon" class="hidden">
      <svg viewBox="0 0 24 24"><path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 10.99h7c-.53 4.12-3.28 7.79-7 8.94V12H5V6.3l7-3.11v8.8z"/></svg>
    </div>

    <div id="priv-modal" class="hidden">
      <div class="priv-content">
        <div class="priv-tabs">
          <button class="priv-tab active" data-target="tab-prefs">Cookie Preferences</button>
          <button class="priv-tab" data-target="tab-dsar">Privacy Requests</button>
        </div>

        <div id="tab-prefs" class="priv-tab-content active">
          <div class="priv-row">
            <label>Strictly Necessary</label>
            <input type="checkbox" checked disabled>
          </div>
          <div class="priv-row">
            <label>Analytics Tracking</label>
            <input type="checkbox" id="chk-analytics">
          </div>
          <div class="priv-row">
            <label>Marketing</label>
            <input type="checkbox" id="chk-marketing">
          </div>
          <div class="priv-row">
            <label>Do Not Sell My Information</label>
            <input type="checkbox" id="chk-sell">
          </div>
          <button class="priv-btn" id="save-prefs-btn">Save Preferences</button>
          <button class="priv-btn priv-btn-close" id="close-modal-btn">Close</button>
        </div>

        <div id="tab-dsar" class="priv-tab-content">
          <form id="dsar-form" action="https://formspree.io/f/YOUR_FORM_ID" method="POST">
            <div class="priv-form-group">
              <label>Email Address</label>
              <input type="email" name="email" required>
            </div>
            <div class="priv-form-group">
              <label>Request Type</label>
              <select name="request_type">
                <option value="access">Access my data</option>
                <option value="delete">Delete my data</option>
                <option value="opt-out">Opt-out of selling</option>
              </select>
            </div>
            <button type="submit" class="priv-btn">Submit Request</button>
          </form>
          <div id="dsar-msg" class="priv-message" hidden></div>
        </div>
      </div>
    </div>
  `;
    document.body.appendChild(container);

    const banner = document.getElementById('priv-banner');
    const icon = document.getElementById('priv-icon');
    const modal = document.getElementById('priv-modal');
    const tabs = document.querySelectorAll('.priv-tab');
    const tabContents = document.querySelectorAll('.priv-tab-content');

    const savedPrefs = localStorage.getItem('privacy_prefs');
    if (savedPrefs) {
        banner.classList.add('hidden');
        icon.classList.remove('hidden');
        const prefs = JSON.parse(savedPrefs);
        document.getElementById('chk-analytics').checked = prefs.analytics;
        document.getElementById('chk-marketing').checked = prefs.marketing;
        document.getElementById('chk-sell').checked = prefs.doNotSell;
    }

    document.getElementById('banner-manage-btn').addEventListener('click', () => modal.classList.remove('hidden'));
    icon.addEventListener('click', () => modal.classList.remove('hidden'));
    document.getElementById('close-modal-btn').addEventListener('click', () => {
        modal.classList.add('hidden');
        if (!localStorage.getItem('privacy_prefs')) banner.classList.remove('hidden');
    });

    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            tabs.forEach(t => t.classList.remove('active'));
            tabContents.forEach(c => c.classList.remove('active'));
            tab.classList.add('active');
            document.getElementById(tab.dataset.target).classList.add('active');
        });
    });

    document.getElementById('save-prefs-btn').addEventListener('click', () => {
        const prefs = {
            analytics: document.getElementById('chk-analytics').checked,
            marketing: document.getElementById('chk-marketing').checked,
            doNotSell: document.getElementById('chk-sell').checked
        };
        localStorage.setItem('privacy_prefs', JSON.stringify(prefs));
        window.dispatchEvent(new CustomEvent('privacy_update', { detail: prefs }));
        modal.classList.add('hidden');
        banner.classList.add('hidden');
        icon.classList.remove('hidden');
    });

    const form = document.getElementById('dsar-form');
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            headers: { 'Accept': 'application/json' }
        }).then(response => {
            if (response.ok) {
                form.hidden = true;
                const msg = document.getElementById('dsar-msg');
                msg.textContent = 'Request submitted successfully.';
                msg.hidden = false;
            }
        });
    });
})();
