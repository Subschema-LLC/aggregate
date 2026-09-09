(function() {
    const style = document.createElement('style');
    style.textContent = `
    #priv-banner { position: fixed; bottom: 0; left: 0; right: 0; background: #fff; border-top: 1px solid #ccc; padding: 20px; box-shadow: 0 -2px 10px rgba(0,0,0,0.1); display: flex; justify-content: space-between; align-items: center; z-index: 9999; font-family: sans-serif; }
    #priv-banner.hidden { display: none; }
    #priv-icon { position: fixed; bottom: 20px; left: 20px; background: #333; color: #fff; width: 44px; height: 44px; border-radius: 50%; text-align: center; line-height: 44px; cursor: pointer; z-index: 9998; font-family: sans-serif; box-shadow: 0 2px 5px rgba(0,0,0,0.2); }
    #priv-icon.hidden { display: none; }
    #priv-icon svg { width: 24px; height: 24px; margin-top: 10px; fill: currentColor; }
    #priv-modal { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 10000; display: flex; justify-content: center; align-items: center; font-family: sans-serif; }
    #priv-modal.hidden { display: none; }
    .priv-content { background: #fff; width: 90%; max-width: 500px; border-radius: 8px; overflow: hidden; display: flex; flex-direction: column; }
    .priv-tabs { display: flex; border-bottom: 1px solid #ccc; }
    .priv-tab { flex: 1; padding: 15px; text-align: center; cursor: pointer; background: #f8f9fa; border: none; outline: none; font-size: 14px; margin: 0; }
    .priv-tab.active { background: #fff; font-weight: bold; border-bottom: 2px solid #333; }
    .priv-tab-content { padding: 20px; display: none; }
    .priv-tab-content.active { display: block; }
    .priv-row { display: flex; justify-content: space-between; margin-bottom: 15px; align-items: center; }
    .priv-form-group { margin-bottom: 15px; }
    .priv-form-group input, .priv-form-group select { width: 100%; padding: 8px; box-sizing: border-box; margin-top: 5px; }
    .priv-btn { padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; background: #333; color: #fff; width: 100%; font-size: 14px; }
    .priv-btn-close { background: #eee; color: #333; margin-top: 10px; }
  `;
    document.head.appendChild(style);

    const container = document.createElement('div');
    container.innerHTML = `
    <div id="priv-banner">
      <div>We use cookies for analytics and marketing.</div>
      <button class="priv-btn" id="banner-manage-btn" style="width: auto;">Manage Preferences</button>
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
          <div id="dsar-msg" style="margin-top:10px; text-align:center; display:none;"></div>
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
                form.style.display = 'none';
                const msg = document.getElementById('dsar-msg');
                msg.textContent = 'Request submitted successfully.';
                msg.style.display = 'block';
            }
        });
    });
})();
