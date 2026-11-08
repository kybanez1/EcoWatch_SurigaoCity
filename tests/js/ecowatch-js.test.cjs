const { JSDOM } = require('jsdom');
const fs = require('fs');
const root = '' + __dirname + '/../../public/js/';
let failures = 0;
function check(name, ok) { console.log((ok ? 'PASS ' : 'FAIL ') + name); if (!ok) failures++; }

const html = `<body>
<button id="opener" data-open="m1">Open</button>
<form id="f"><button type="submit" id="s">Go</button></form>
<div class="modal-bg" id="m1"><div class="modal"><button class="close" data-close type="button">x</button><h2>Edit</h2>
  <input type="text" id="first"><button id="last" type="button">Save</button></div></div>
<div class="modal-bg open" id="m2"><div class="modal"><button class="close" data-close type="button">x</button><h2>Server opened</h2><input id="srv"></div></div>
<button title="Edit thing" id="iconbtn">✎</button>
<div id="spot"></div>
</body>`;
const dom = new JSDOM(html, { runScripts: 'outside-only' });
dom.window.eval(fs.readFileSync(root + 'ecowatch.js', 'utf8'));
const w = dom.window, d = w.document;

check('modal gets role=dialog and aria-modal', d.getElementById('m1').getAttribute('role') === 'dialog' && d.getElementById('m1').getAttribute('aria-modal') === 'true');
check('modal labelled by its heading', d.getElementById(d.getElementById('m1').getAttribute('aria-labelledby')).textContent === 'Edit');
check('close button has accessible name', d.querySelector('#m1 .close').getAttribute('aria-label') === 'Close');
check('icon button gets aria-label from title', d.getElementById('iconbtn').getAttribute('aria-label') === 'Edit thing');
check('server-opened modal focuses its first field', d.activeElement.id === 'srv');

w.eval("document.getElementById('m2').classList.remove('open')");
d.getElementById('opener').focus();
d.getElementById('opener').click();
check('modal opens on click', d.getElementById('m1').classList.contains('open'));
check('focus moves to first field', d.activeElement.id === 'first');

// Tab from last element wraps to first
d.getElementById('last').focus();
const tab = new w.KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
d.dispatchEvent(tab);
check('Tab at the end stays inside the modal', tab.defaultPrevented && d.activeElement.className === 'close');

d.dispatchEvent(new w.KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
check('Escape closes the modal', !d.getElementById('m1').classList.contains('open'));
check('focus returns to the opener', d.activeElement.id === 'opener');

// Popups: saved text must stay plain text.
const evil = '<img src=x onerror=alert(1)>';
const popup = w.eval('buildPopup').call(null, evil, '<script>bad()</script>');
check('popup is a DOM element', popup.nodeType === 1);
check('no injected elements in popup', popup.querySelectorAll('img, script').length === 0);
check('popup shows the text literally', popup.textContent.indexOf(evil) !== -1);

// Double submit guard (record whether each submit was cancelled by ecowatch.js)
const results = [];
w.addEventListener('submit', e => { results.push(e.defaultPrevented); e.preventDefault(); });
d.getElementById('s').click(); d.getElementById('s').click();
check('first submit allowed, quick second submit blocked', results.length === 2 && results[0] === false && results[1] === true);

// drawRoutes uses safe popups
const popups = [];
w.L = { polyline: () => ({ bindPopup: (c) => { popups.push(c); }, addTo() {} }) };
w.eval('drawRoutes')({}, [{ path: [[1,1],[2,2]], color: '#000', truck: evil, name: 'n', barangay: 'b' }]);
check('drawRoutes passes a DOM element to bindPopup', popups.length === 1 && typeof popups[0] === 'object' && popups[0].querySelectorAll('img').length === 0);
console.log(failures === 0 ? 'ALL JS CHECKS PASSED' : failures + ' FAILED');
process.exit(failures ? 1 : 0);
