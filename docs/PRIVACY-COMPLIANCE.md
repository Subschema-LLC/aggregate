# Privacy Compliance Guide

This guide explains how Aggregate Analytics is designed to be compliant with strict privacy laws including GDPR, CCPA, ePrivacy Directive, and similar regulations.

## Table of Contents

- [Privacy Architecture](#privacy-architecture)
- [Session Cookie Implementation](#session-cookie-implementation)
- [Legal Basis for Processing](#legal-basis-for-processing)
- [Compliance Checklist](#compliance-checklist)
- [Cookie Consent Integration](#cookie-consent-integration)

---

## Privacy Architecture

### Two-Tier Tracking System

Aggregate Analytics uses a **two-tier tracking system** designed for privacy compliance:

#### **Tier 1: Cookieless Anonymous Tracking (Default)**

**No consent required** - Meets "strictly necessary" or "legitimate interest" exemptions:

- ✅ **No cookies or local storage**
- ✅ **No persistent identifiers**
- ✅ **Server-side IP anonymization** (daily salted hash)
- ✅ **Generalized User-Agent** (no fingerprinting)
- ✅ **No cross-site tracking**
- ✅ **No personal data collection**

**What's tracked:**
- Page URLs (necessary for analytics functionality)
- Referrer (for traffic source analysis)
- Screen width (for responsive design insights)
- Daily anonymized IP hash (cannot be reversed)
- Browser category (e.g., "Chrome on Desktop")

**Privacy guarantees:**
- Cannot identify individual users
- Cannot track users across days
- Cannot track users across websites
- Data automatically becomes anonymous after 24 hours

#### **Tier 2: Consent-Based Enhanced Tracking**

**Requires explicit user consent**:

- 📝 **Session cookie** (strictly necessary for session tracking)
- 📝 **Visitor ID** (persistent, stored in localStorage)
- 📝 **Session ID** (temporary, stored in sessionStorage)
- 📝 **Enhanced event tracking**

**Only activated when:**
```javascript
window.Aggregate.setConsent(true);
```

---

## Session Cookie Implementation

### Privacy-Compliant Session Cookie

When consent is granted, Aggregate Analytics sets a **strictly necessary session cookie** that complies with all major privacy laws.

### Cookie Specification

```
Name:     aggregate_session
Value:    [UUID v4]
Domain:   [auto - current domain only]
Path:     /
Max-Age:  1800 seconds (30 minutes)
Secure:   true (HTTPS only)
HttpOnly: false (needs JS access)
SameSite: Lax
```

### Why This Cookie is Compliant

#### 1. **Strictly Necessary Cookie Exemption**

Under GDPR Article 6(1)(f) and ePrivacy Directive, this cookie qualifies as "strictly necessary" because:

- ✅ **Purpose-limited**: Only used to maintain session continuity during a single visit
- ✅ **Minimal data**: Contains only a random UUID, no personal data
- ✅ **Short-lived**: Expires after 30 minutes of inactivity
- ✅ **Single-site**: Not used for cross-site tracking (SameSite=Lax)
- ✅ **User-initiated**: Only set after explicit consent is granted

#### 2. **GDPR Compliance (EU)**

**Legal basis**: Consent (Article 6(1)(a)) + Legitimate Interest (Article 6(1)(f))

- ✅ **Consent obtained**: Only set when user calls `setConsent(true)`
- ✅ **Purpose specified**: Session continuity for analytics
- ✅ **Data minimization**: Contains only a UUID
- ✅ **Storage limitation**: 30-minute expiry
- ✅ **Revocable**: Can be deleted by calling `setConsent(false)`
- ✅ **Transparent**: Documented in privacy policy

#### 3. **CCPA Compliance (California)**

- ✅ **No sale of data**: Cookie data is not shared or sold
- ✅ **User control**: Users can opt-out via `setConsent(false)`
- ✅ **Disclosure**: Usage disclosed in privacy policy
- ✅ **Limited use**: Only for analytics, not profiling

#### 4. **ePrivacy Directive Compliance (EU)**

- ✅ **Prior consent**: Set only after user consent
- ✅ **Information requirement**: Purpose disclosed to users
- ✅ **Rejection option**: Default is no cookie

#### 5. **PECR Compliance (UK)**

- ✅ **Soft opt-in**: Cookie enhances service user is already using
- ✅ **Clear information**: Purpose disclosed
- ✅ **Easy rejection**: Can disable via consent withdrawal

### Implementation

**JavaScript (Client-side):**

```javascript
// Set session cookie (only when consent = true)
function setSessionCookie(sessionId) {
  var expires = new Date();
  expires.setTime(expires.getTime() + 30 * 60 * 1000); // 30 minutes

  var cookieValue = 'aggregate_session=' + encodeURIComponent(sessionId) + ';' +
    'path=/;' +
    'max-age=1800;' +
    'SameSite=Lax';

  // Add Secure flag for HTTPS
  if (location.protocol === 'https:') {
    cookieValue += ';Secure';
  }

  document.cookie = cookieValue;
}

// Get session cookie
function getSessionCookie() {
  var name = 'aggregate_session=';
  var cookies = document.cookie.split(';');
  for (var i = 0; i < cookies.length; i++) {
    var cookie = cookies[i].trim();
    if (cookie.indexOf(name) === 0) {
      return decodeURIComponent(cookie.substring(name.length));
    }
  }
  return null;
}

// Delete session cookie
function deleteSessionCookie() {
  document.cookie = 'aggregate_session=; path=/; max-age=0; SameSite=Lax';
}
```

### Cookie vs localStorage/sessionStorage

**Why we use both:**

| Storage Type | Purpose | Privacy Benefit |
|--------------|---------|----------------|
| **Session Cookie** | Session continuity across page loads | Can be blocked by users; visible in browser tools |
| **sessionStorage** | Backup session ID (tab-specific) | Automatically cleared when tab closes |
| **localStorage** | Visitor ID (cross-session) | Persistent but user-controllable |

**Fallback strategy:**
1. Try session cookie first (best for analytics)
2. Fall back to sessionStorage if cookies disabled
3. Fall back to localStorage for visitor ID
4. All three require consent

---

## Legal Basis for Processing

### GDPR Article 6 - Lawfulness of Processing

**For Tier 1 (No Consent Required):**

**Legal Basis**: Article 6(1)(f) - Legitimate Interest

- **Legitimate Interest**: Website analytics for service improvement
- **Necessity Test**: ✅ Cannot achieve analytics without tracking page views
- **Balancing Test**: ✅ User privacy protected via anonymization
- **User Expectations**: ✅ Users expect websites to improve based on usage
- **Safeguards**: Daily rotating hashes, generalized data, no persistent IDs

**GDPR Recital 47**: Analytics for "monitoring and improving the performance" qualifies as legitimate interest.

**For Tier 2 (Requires Consent):**

**Legal Basis**: Article 6(1)(a) - Consent

- **Freely given**: ✅ User explicitly calls `setConsent(true)`
- **Specific**: ✅ Consent is for analytics tracking only
- **Informed**: ✅ User informed via privacy policy
- **Unambiguous**: ✅ Requires positive action
- **Revocable**: ✅ Can call `setConsent(false)` anytime

### CCPA - Categories of Personal Information

**Information Collected:**

| Category | Tier 1 | Tier 2 | Purpose |
|----------|--------|--------|---------|
| **Identifiers** | ❌ No | ✅ Yes (UUID) | Session continuity |
| **Internet Activity** | ✅ Yes (anonymous) | ✅ Yes | Analytics |
| **Device Information** | ✅ Yes (generalized) | ✅ Yes | Responsive design |
| **Geolocation** | ❌ No | ❌ No | Not collected |

**CCPA Rights Honored:**
- ✅ Right to Know: Data collection disclosed in privacy policy
- ✅ Right to Delete: Call `setConsent(false)` or contact data controller
- ✅ Right to Opt-Out: Don't call `setConsent(true)`
- ✅ Right to Non-Discrimination: Service works without consent

---

## Compliance Checklist

### ✅ Implementation Checklist

- [ ] **Privacy Policy Updated**
  - [ ] Disclose use of analytics
  - [ ] Explain cookie usage
  - [ ] List data collected (Tier 1 and Tier 2)
  - [ ] Explain legal basis (legitimate interest + consent)
  - [ ] Provide contact information
  - [ ] Explain user rights (access, deletion, portability)

- [ ] **Cookie Banner/Consent Manager**
  - [ ] Explain analytics purpose
  - [ ] Provide "Accept" and "Reject" options
  - [ ] Link to privacy policy
  - [ ] Call `window.Aggregate.setConsent(true)` on accept
  - [ ] Respect prior consent choices

- [ ] **Technical Implementation**
  - [ ] Default to Tier 1 (no cookies)
  - [ ] Only enable Tier 2 after consent
  - [ ] Session cookie: 30-minute expiry, SameSite=Lax, Secure
  - [ ] Provide `setConsent(false)` to withdraw consent
  - [ ] Clear all cookies/storage on withdrawal

- [ ] **Data Protection**
  - [ ] IP addresses hashed server-side immediately
  - [ ] Daily salt rotation for IP hashes
  - [ ] User-Agent generalization
  - [ ] No data sharing with third parties
  - [ ] Secure data transmission (HTTPS)
  - [ ] Database access controls

- [ ] **User Rights Implementation**
  - [ ] Right to Access: Provide data export API
  - [ ] Right to Deletion: Implement data deletion
  - [ ] Right to Portability: JSON export format
  - [ ] Right to Object: Honor opt-out

### ✅ GDPR Compliance Checklist

- [x] **Lawfulness, Fairness, Transparency** (Art. 5(1)(a))
  - [x] Legal basis established
  - [x] Purpose disclosed in privacy policy

- [x] **Purpose Limitation** (Art. 5(1)(b))
  - [x] Purpose: Website analytics only
  - [x] No secondary uses

- [x] **Data Minimization** (Art. 5(1)(c))
  - [x] Only essential data collected
  - [x] IP addresses immediately hashed

- [x] **Accuracy** (Art. 5(1)(d))
  - [x] Data accurate by design (automated)

- [x] **Storage Limitation** (Art. 5(1)(e))
  - [x] Session cookie: 30 minutes
  - [x] IP hashes: Daily rotation (unlinkable after 24h)

- [x] **Integrity and Confidentiality** (Art. 5(1)(f))
  - [x] HTTPS required
  - [x] Secure cookie flags
  - [x] Database access controls

- [x] **Accountability** (Art. 5(2))
  - [x] Documentation of compliance measures
  - [x] Privacy by design implementation

---

## Cookie Consent Integration

### Example: Cookie Consent Banner

```html
<div id="cookie-banner" style="display: none;">
  <div class="banner-content">
    <p>
      We use cookies to analyze website traffic and improve your experience.
      <a href="/privacy-policy">Learn more</a>
    </p>
    <button id="accept-cookies">Accept</button>
    <button id="reject-cookies">Reject</button>
  </div>
</div>

<script>
  // Check if consent already given
  var consent = localStorage.getItem('analytics_consent');

  if (consent === null) {
    // Show banner
    document.getElementById('cookie-banner').style.display = 'block';
  } else if (consent === 'granted') {
    // Enable Tier 2 tracking
    window.Aggregate.setConsent(true);
  }

  // Accept button
  document.getElementById('accept-cookies').addEventListener('click', function() {
    localStorage.setItem('analytics_consent', 'granted');
    window.Aggregate.setConsent(true);
    document.getElementById('cookie-banner').style.display = 'none';
  });

  // Reject button
  document.getElementById('reject-cookies').addEventListener('click', function() {
    localStorage.setItem('analytics_consent', 'rejected');
    window.Aggregate.setConsent(false);
    document.getElementById('cookie-banner').style.display = 'none';
  });
</script>
```

### Example: Integrate with Popular Consent Managers

#### **Cookiebot**
```javascript
window.addEventListener('CookiebotOnAccept', function() {
  if (Cookiebot.consent.statistics) {
    window.Aggregate.setConsent(true);
  }
});
```

#### **OneTrust**
```javascript
function OptanonWrapper() {
  if (OnetrustActiveGroups.includes('C0002')) { // Performance cookies
    window.Aggregate.setConsent(true);
  }
}
```

#### **CookieYes**
```javascript
document.addEventListener('cookieyes_consent_update', function(e) {
  if (e.detail.accepted.includes('analytics')) {
    window.Aggregate.setConsent(true);
  }
});
```

---

## Privacy Policy Template

### Section to Add to Your Privacy Policy

```markdown
### Analytics and Cookies

#### What We Collect

We use Aggregate Analytics, a privacy-first analytics tool, to understand how visitors use our website.

**Without Your Consent (Tier 1):**
We collect anonymous analytics data including:
- Pages you visit (URL)
- Referring website (if applicable)
- Screen size (for responsive design)
- Anonymized daily IP hash (cannot identify you)
- Generalized browser type (e.g., "Chrome on Desktop")

This data is **completely anonymous** and cannot be used to identify you. We cannot track you across days or across different websites.

**Legal Basis:** Legitimate interest (GDPR Article 6(1)(f)) - necessary for website improvement and security.

**With Your Consent (Tier 2):**
If you accept analytics cookies, we additionally collect:
- Session cookie (30-minute expiry) to track your session
- Visitor ID (to recognize returning visitors)
- Enhanced event tracking (button clicks, form submissions, etc.)

**Legal Basis:** Your explicit consent (GDPR Article 6(1)(a))

#### Cookies Used

| Cookie Name | Purpose | Duration | Type |
|-------------|---------|----------|------|
| aggregate_session | Maintain session during your visit | 30 minutes | Session |
| analytics_consent | Remember your cookie preference | 1 year | Preference |

#### Your Rights

You have the right to:
- **Access** your data: Contact us at [email]
- **Delete** your data: Contact us at [email] or disable cookies
- **Opt-out** of tracking: Click "Reject" in our cookie banner
- **Withdraw consent**: Disable cookies in your browser settings

#### Data Retention

- Session cookies: 30 minutes
- Analytics data: Aggregated and kept for 2 years for trend analysis
- Individual page views: 90 days

#### Third Parties

We do **not** share your data with third parties. All analytics data is stored on our own servers.

#### Contact

For privacy questions: [email]
Data Controller: [name/company]
```

---

## Best Practices

### 1. **Default to Privacy**
- Always start with Tier 1 (no cookies)
- Only enable Tier 2 after explicit consent
- Make rejection as easy as acceptance

### 2. **Transparent Communication**
- Clear, simple language in privacy policy
- Explain what data is collected and why
- Show users where their data goes

### 3. **Easy Opt-Out**
- Provide clear "Reject" button
- Honor Do Not Track (DNT) signals
- Allow consent withdrawal anytime

### 4. **Technical Safeguards**
- HTTPS everywhere
- Secure cookie flags
- Short cookie expiry (30 minutes)
- SameSite protection

### 5. **Regular Audits**
- Review privacy policy annually
- Test consent mechanisms
- Monitor for compliance with new laws

---

## Frequently Asked Questions

### Is Tier 1 tracking legal without consent?

**Yes**, under most privacy laws including GDPR. Tier 1 tracking qualifies as:
- **Anonymous data** (GDPR Recital 26: "not personal data")
- **Legitimate interest** (necessary for website improvement)
- **Strictly necessary** (for service functionality)

However, we recommend disclosing it in your privacy policy for full transparency.

### Do I need a cookie banner for Tier 1?

**No**, because Tier 1 uses no cookies or persistent storage. However:
- You should still disclose analytics in your privacy policy
- Some countries (e.g., Germany) may require disclosure even for cookieless tracking
- Best practice: Include a privacy notice or banner anyway

### When do I need consent?

**Consent is required** for Tier 2 tracking because it:
- Sets cookies
- Uses persistent identifiers
- Tracks users over time

**No consent needed** for Tier 1 because it:
- Uses no cookies or storage
- Cannot identify individuals
- Data is automatically anonymized

### Can I use Tier 1 in the EU?

**Yes**. GDPR explicitly allows anonymous analytics under "legitimate interest" (Article 6(1)(f)). Since Tier 1:
- Cannot identify users
- Uses daily rotating hashes
- Collects minimal data
- Has a legitimate purpose

It falls outside the scope of personal data processing.

### What about children's privacy (COPPA)?

Aggregate Analytics is **COPPA-compliant** for Tier 1:
- No personal information collected
- No persistent identifiers
- No behavioral tracking

For Tier 2, you should:
- Not enable it for users under 13 (US)
- Not enable it for users under 16 (EU) without parental consent
- Implement age verification if your site targets children

---

## Conclusion

Aggregate Analytics is designed with **privacy by default**:

1. **Tier 1** provides valuable analytics without cookies or consent requirements
2. **Tier 2** offers enhanced tracking with full privacy law compliance
3. **Session cookies** are strictly necessary, short-lived, and consent-based

By following this guide, you can deploy Aggregate Analytics in compliance with GDPR, CCPA, ePrivacy, and other strict privacy laws worldwide.

For questions, refer to your legal counsel or contact your Data Protection Officer (DPO).
