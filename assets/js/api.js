/* Shared API helper for the USTED election frontend.
   Uses the same relative path regardless of page location. */

const API = (function () {
  const base = (location.pathname.replace(/\/[^/]*$/, '/')) + 'api/';

  function buildUrl(file, action, params) {
    let url = base + file + '?action=' + encodeURIComponent(action);
    if (params) {
      for (const k in params) {
        url += '&' + encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
      }
    }
    return url;
  }

  async function call(file, action, opts) {
    opts = opts || {};
    const method = opts.method || 'GET';
    const url = buildUrl(file, action, opts.params);
    const init = { method, credentials: 'same-origin' };
    if (opts.body !== undefined) {
      if (opts.body instanceof FormData) {
        init.body = opts.body;
      } else {
        init.headers = { 'Content-Type': 'application/json' };
        init.body = JSON.stringify(opts.body);
      }
    }
    let res;
    try {
      res = await fetch(url, init);
    } catch (e) {
      throw new Error('Network error. Is XAMPP running?');
    }
    let data = null;
    const text = await res.text();
    if (text) {
      try { data = JSON.parse(text); }
      catch { throw new Error('Bad server response.'); }
    }
    if (!res.ok) {
      const msg = (data && data.error) ? data.error : ('Request failed (' + res.status + ')');
      const err = new Error(msg);
      err.status = res.status;
      err.data = data;
      throw err;
    }
    return data;
  }

  return {
    // auth.php
    register:    (b) => call('auth.php', 'register',    { method: 'POST', body: b }),
    login:       (b) => call('auth.php', 'login',       { method: 'POST', body: b }),
    adminLogin:  (b) => call('auth.php', 'admin_login', { method: 'POST', body: b }),
    me:          ()  => call('auth.php', 'me'),
    logout:      ()  => call('auth.php', 'logout',      { method: 'POST' }),
    campuses:    ()  => call('auth.php', 'campuses'),
    departments: (p) => call('auth.php', 'departments', { params: p }),
    programmes:  (p) => call('auth.php', 'programmes',  { params: p }),
    halls:       (p) => call('auth.php', 'halls',        { params: p }),

    // ID photo + OTP
    uploadIdPhoto: (b) => call('auth.php', 'upload_id_photo', { method: 'POST', body: b }),
    sendOtp:       (b) => call('auth.php', 'send_otp',        { method: 'POST', body: b }),
    verifyOtp:     (b) => call('auth.php', 'verify_otp',      { method: 'POST', body: b }),

    // voting.php
    ballot:      ()  => call('voting.php', 'ballot'),
    cast:        (b) => call('voting.php', 'cast',       { method: 'POST', body: b }),

    // results.php
    results:     (p) => call('results.php', 'results',   { params: p }),
    turnout:     (p) => call('results.php', 'turnout',    { params: p }),
    elections:   ()  => call('results.php', 'elections'),

    adminElections:  ()  => call('results.php', 'admin_elections'),
    addElection:     (b) => call('results.php', 'add_election',  { method: 'POST', body: b }),
    adminPositions:  (p) => call('results.php', 'admin_positions', { params: p }),
    addPosition:     (b) => call('results.php', 'add_position',  { method: 'POST', body: b }),
    addCandidate:    (b) => call('results.php', 'add_candidate', { method: 'POST', body: b }),
    populatePositions: (b) => call('results.php', 'populate_positions', { method: 'POST', body: b }),
    uploadCandidatePhoto: (b) => call('results.php', 'upload_candidate_photo', { method: 'POST', body: b }),
    pendingVoters:   ()  => call('results.php', 'pending_voters'),
    approveVoter:    (b) => call('results.php', 'approve_voter', { method: 'POST', body: b }),
    rejectVoter:     (b) => call('results.php', 'reject_voter',  { method: 'POST', body: b }),
    openElection:    (b) => call('results.php', 'open_election', { method: 'POST', body: b }),
    closeElection:   (b) => call('results.php', 'close_election',{ method: 'POST', body: b }),
    adminStats:      ()  => call('results.php', 'admin_stats'),
  };
})();

function initials(name) {
  if (!name) return '?';
  return name.trim().split(/\s+/).slice(0, 2).map(w => w[0].toUpperCase()).join('');
}

function el(tag, attrs, children) {
  const node = document.createElement(tag);
  if (attrs) {
    for (const k in attrs) {
      if (k === 'class') node.className = attrs[k];
      else if (k === 'text') node.textContent = attrs[k];
      else if (k === 'html') node.innerHTML = attrs[k];
      else node.setAttribute(k, attrs[k]);
    }
  }
  if (children) {
    (Array.isArray(children) ? children : [children]).forEach(c => {
      if (c == null) return;
      node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
    });
  }
  return node;
}

function showAlert(container, type, message) {
  container.innerHTML = '';
  container.appendChild(el('div', { class: 'alert ' + type, text: message }));
}

/* Onboarding tour — shown to first-time users */
const TOUR_KEY = 'aamusted_tour_done';

function maybeStartTour(pageName) {
  if (localStorage.getItem(TOUR_KEY)) return;
  startTour(pageName);
}

function startTour(pageName) {
  const steps = {
    home: [
      { title: 'Welcome to USTED Elections!', body: 'This is the official student election platform for USTED. Let me show you around quickly.' },
      { title: 'Vote', body: 'Click "Vote Now" to register with your student ID, get approved by an admin, and cast your ballot for SRC, departmental, and hall positions.' },
      { title: 'Results', body: 'Check "Live Results" any time to see real-time vote tallies as they come in.' },
      { title: 'Admin', body: 'Election officers can manage everything from the Admin dashboard — approving voters, adding candidates, and opening or closing elections.' },
      { title: 'Ready to go!', body: 'You can revisit this tour anytime. Pick a page from the menu above to get started.' },
    ],
    vote: [
      { title: 'Voter Portal', body: 'Register with your student ID, campus, department, programme, and hall. Once registered, you can vote immediately.' },
      { title: 'Multiple Elections', body: 'Once approved, you may see several elections — SRC, departmental, and hall. You can vote in each one separately.' },
      { title: 'One Vote Per Position', body: 'Select your candidate for each position, then submit. You can only vote once per election, so choose carefully!' },
    ],
    results: [
      { title: 'Live Results', body: 'This page shows real-time vote tallies for every position in every election. It refreshes automatically every 15 seconds.' },
      { title: 'Turnout Stats', body: 'Check the top section for overall turnout, including male and female voter breakdowns.' },
    ],
    admin: [
      { title: 'Officer Dashboard', body: 'As an election officer, you can approve voters, add positions and candidates, create new elections, and control when voting opens and closes.' },
      { title: 'Election Types', body: 'You can create four types of elections: SRC (campus-wide), Departmental, Hall, and Class representative elections.' },
      { title: 'Approve Voters', body: 'Students register and can vote immediately. You can still see all registered voters from the dashboard, but no approval is required.' },
    ],
  };

  const tourSteps = steps[pageName] || steps.home;
  let current = 0;

  const overlay = el('div', { class: 'tour-overlay active' });
  const popup = el('div', { class: 'tour-popup active' });
  document.body.appendChild(overlay);
  document.body.appendChild(popup);

  function render() {
    const s = tourSteps[current];
    popup.innerHTML = '';
    popup.appendChild(el('h3', { text: s.title }));
    popup.appendChild(el('p', { text: s.body }));
    const nav = el('div', { class: 'tour-nav' });
    const dots = el('div', { class: 'tour-dots' });
    tourSteps.forEach((_, i) => {
      dots.appendChild(el('div', { class: 'tour-dot' + (i === current ? ' active' : '') }));
    });
    nav.appendChild(dots);
    const btnRow = el('div', { class: 'row' });
    if (current > 0) {
      const back = el('button', { class: 'btn btn-outline btn-sm', text: 'Back' });
      back.onclick = (e) => { e.stopPropagation(); current--; render(); };
      btnRow.appendChild(back);
    }
    if (current < tourSteps.length - 1) {
      const next = el('button', { class: 'btn btn-primary btn-sm', text: 'Next' });
      next.onclick = (e) => { e.stopPropagation(); current++; render(); };
      btnRow.appendChild(next);
    } else {
      const done = el('button', { class: 'btn btn-gold btn-sm', text: 'Got it!' });
      done.onclick = (e) => { e.stopPropagation(); closeTour(); };
      btnRow.appendChild(done);
    }
    const skip = el('button', { class: 'btn btn-sm', style: 'background:transparent;color:var(--muted)', text: 'Skip' });
    skip.onclick = (e) => { e.stopPropagation(); closeTour(); };
    nav.appendChild(skip);
    nav.appendChild(btnRow);
    popup.appendChild(nav);

    // center popup
    popup.style.left = '50%';
    popup.style.top = '50%';
    popup.style.transform = 'translate(-50%, -50%)';
  }

  function closeTour() {
    overlay.remove();
    popup.remove();
    localStorage.setItem(TOUR_KEY, '1');
  }

  overlay.onclick = closeTour;
  render();
}

/* Shared navbar */
function renderNavbar(activePage) {
  const links = [
    { href: 'index.html',   label: 'Home',    key: 'home' },
    { href: 'vote.html',    label: 'Vote',    key: 'vote' },
    { href: 'results.html', label: 'Results', key: 'results' },
    { href: 'admin.html',   label: 'Officer Area', key: 'admin' },
  ];

  const nav = el('nav', { class: 'navbar' });
  const brand = el('a', { class: 'navbar-brand', href: 'index.html' });
  const brandLogo = el('span', { class: 'brand-logo' });
  brandLogo.appendChild(el('img', { src: 'public/AAMUSTED_id6YoLL7u-_0.png', alt: 'USTED logo' }));
  brand.appendChild(brandLogo);
  const brandText = el('span', { class: 'brand-text' });
  brandText.appendChild(el('span', { class: 'brand-name', text: 'USTED' }));
  brandText.appendChild(el('span', { class: 'brand-sub', text: 'Student Elections' }));
  brand.appendChild(brandText);
  nav.appendChild(brand);

  const ul = el('ul', { class: 'navbar-links', id: 'navLinks' });
  links.forEach(l => {
    const a = el('a', { href: l.href, text: l.label });
    if (l.key === activePage) a.className += ' active';
    ul.appendChild(el('li', {}, [a]));
  });
  nav.appendChild(ul);

  const toggle = el('button', { class: 'navbar-toggle', text: '\u2630' });
  toggle.onclick = () => {
    const linksEl = document.getElementById('navLinks');
    linksEl.classList.toggle('open');
  };
  nav.appendChild(toggle);

  return nav;
}
